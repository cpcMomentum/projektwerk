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
 * #246 PR 2 — Mitgliedschaft projekt-scoped (`pwerk_members.project_id`).
 *
 * Legt `project_id` an und füllt sie aus dem Projekt des Boards (PR 0). **Ab
 * dieser Migration verbindet `TicketScope` über `m.project_id = t.project_id`
 * statt über `board_id`** — die Sichtbarkeits-REGEL (public / internal+Rolle /
 * private) bleibt byte-identisch, nur der Verbund-Schlüssel wandert. Solange
 * jedes Projekt genau ein Board hat, ist das verhaltensneutral; die
 * Zwei-Board-pro-Projekt-Fixture der Leak-Matrix beweist die Vertraulichkeit
 * für den Fall, dass es mehr werden.
 *
 * `board_id` bleibt vorerst an der Zeile (per-Board-Sichten wie „Meine
 * Aufgaben" laufen noch darüber); der Schlüsselwechsel betrifft nur den
 * Sichtbarkeits-Verbund. Backfill je Board, transaktional, idempotent.
 */
class Version000015Date20260902000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'Mitgliedschaft projekt-scoped (#246 PR 2)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_members.project_id (backfilled from the board); TicketScope now joins on project_id (#246).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_members')) {
			return null;
		}

		$members = $schema->getTable('pwerk_members');
		if (!$members->hasColumn('project_id')) {
			$members->addColumn('project_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		}
		if (!$members->hasIndex('pwerk_members_project_idx')) {
			// Der Verbundschlüssel der Sichtbarkeitsregel — (project_id, user_id)
			// deckt genau `m.project_id = t.project_id AND m.user_id = :uid`.
			$members->addIndex(['project_id', 'user_id'], 'pwerk_members_project_idx');
		}

		return $schema;
	}

	/**
	 * Backfill: die Mitglieder jedes Boards auf dessen Projekt setzen.
	 *
	 * @param IOutput $output Fortschrittsausgabe.
	 * @param Closure $schemaClosure Liefert den Schema-Wrapper.
	 * @param array<string, mixed> $options Optionen des Migrationslaufs.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_members') || !$schema->hasTable('pwerk_boards')) {
			return;
		}
		if (!$schema->getTable('pwerk_members')->hasColumn('project_id')) {
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
				$qb->update('pwerk_members')
					->set('project_id', $qb->createNamedParameter((int)$board['project_id'], IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('board_id', $qb->createNamedParameter((int)$board['id'], IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->isNull('project_id'));
				$gesetzt += $qb->executeStatement();
			}
			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}

		$output->info('pwerk_members: project_id auf ' . $gesetzt . ' Mitgliedschaft(en) aus dem Board gesetzt');
	}
}
