<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * #246 PR 1 — `tickets.project_id` (denormalisiert, aus dem Board befüllt).
 *
 * Legt die Spalte an und füllt sie aus dem Projekt des jeweiligen Boards
 * (PR 0 hat `boards.project_id` gesetzt). **Noch ungenutzt:** `TicketScope`
 * verbindet weiterhin über `board_id`; diese Spalte steht bereit, damit der
 * spätere JOIN-Schlüsselwechsel (PR 2) einspaltig und der Wert unveränderlich
 * bleibt — das Ticket wechselt kein Board, das Board kein Projekt.
 *
 * Ein **nicht-eindeutiger** Index auf `project_id` beschleunigt den künftigen
 * Verbund. Der eindeutige `(project_id, number)`-Index kommt erst, wenn die
 * Nummernvergabe projektweit wird (PR 4) — heute sind Nummern noch board-weit,
 * und ein Board frisch ohne Projekt (angelegt nach PR 0, vor PR 5) trüge sonst
 * `project_id = NULL`.
 *
 * Backfill je Board (wenige) statt je Ticket (viele), transaktional und
 * idempotent (nur Tickets ohne `project_id`).
 */
class Version000014Date20260901030000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'tickets.project_id (#246 PR 1)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_tickets.project_id (denormalised, backfilled from the board) and a project_id index; still unused by TicketScope (#246).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_tickets')) {
			return null;
		}

		$tickets = $schema->getTable('pwerk_tickets');
		if (!$tickets->hasColumn('project_id')) {
			$tickets->addColumn('project_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		}
		if (!$tickets->hasIndex('pwerk_tickets_project_idx')) {
			$tickets->addIndex(['project_id'], 'pwerk_tickets_project_idx');
		}

		return $schema;
	}

	/**
	 * Backfill: die Tickets jedes Boards auf dessen Projekt setzen.
	 *
	 * @param IOutput $output Fortschrittsausgabe.
	 * @param Closure $schemaClosure Liefert den Schema-Wrapper.
	 * @param array<string, mixed> $options Optionen des Migrationslaufs.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_tickets') || !$schema->hasTable('pwerk_boards')) {
			return;
		}
		if (!$schema->getTable('pwerk_tickets')->hasColumn('project_id')) {
			return;
		}

		$lese = $this->connection->getQueryBuilder();
		$lese->select('id', 'project_id')
			->from('pwerk_boards')
			->where($lese->expr()->isNotNull('project_id'));
		$ergebnis = $lese->executeQuery();
		$boards = $ergebnis->fetchAll();
		$ergebnis->closeCursor();

		if ($boards === []) {
			return;
		}

		$this->connection->beginTransaction();
		try {
			$gesetzt = 0;
			foreach ($boards as $board) {
				$qb = $this->connection->getQueryBuilder();
				$qb->update('pwerk_tickets')
					->set('project_id', $qb->createNamedParameter((int)$board['project_id'], IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('board_id', $qb->createNamedParameter((int)$board['id'], IQueryBuilder::PARAM_INT)))
					// Nur, wo noch nichts steht — der Lauf bleibt idempotent.
					->andWhere($qb->expr()->isNull('project_id'));
				$gesetzt += $qb->executeStatement();
			}
			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}

		$output->info('pwerk_tickets: project_id auf ' . $gesetzt . ' Vorgang/Vorgängen aus dem Board gesetzt');
	}
}
