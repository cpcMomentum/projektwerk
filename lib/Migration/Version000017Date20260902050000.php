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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * #246 PR 4a — Vorgangsnummern projektweit.
 *
 * Die Nummernvergabe zählt ab jetzt am Projekt
 * ({@see \OCA\Projektwerk\Db\ProjectMapper::claimTicketNumber()}) statt am Board.
 * Diese Migration zieht Schema und Bestand nach:
 *
 * 1. **Neu:** eindeutiger Index `(project_id, number)` auf `pwerk_tickets` — die
 *    Garantie, dass zwei Vorgänge desselben Projekts nie dieselbe Nummer (und
 *    damit denselben Dateinamen und Direktlink) tragen. Er löst den alten
 *    `(board_id, number)` ab, dessen Garantie mit mehreren Boards je Projekt zu
 *    schwach wäre.
 * 2. **Bestand:** `pwerk_projects.ticket_counter` aus dem höchsten Board-Zähler
 *    des Projekts nachziehen. Migration 13 hatte den Zähler beim Anlegen des
 *    Projekt-Dachs schon gespiegelt; seither zählte aber weiter das **Board**
 *    (bis diese Migration). Wurde zwischen PR 0 und PR 4 ein Vorgang angelegt,
 *    liefe der Projektzähler sonst hinter der bereits vergebenen Nummer her und
 *    der nächste Vorgang liefe in den neuen eindeutigen Index. Der Abgleich
 *    schließt diese Lücke — idempotent, nur nach oben.
 *
 * **Kein Dedup nötig für den neuen Index:** Bis PR 5 hat jedes Projekt genau ein
 * Board; mit dem bisherigen `(board_id, number)`-Unique und dem 1:1
 * Board↔Projekt ist `(project_id, number)` bereits eindeutig.
 */
class Version000017Date20260902050000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'ticket numbers per project (#246 PR 4a)';
	}

	#[\Override]
	public function description(): string {
		return 'Unique (project_id, number) on tickets, replacing (board_id, number); realign pwerk_projects.ticket_counter from the board (#246).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_tickets')) {
			return null;
		}

		$tickets = $schema->getTable('pwerk_tickets');

		if (!$tickets->hasIndex('pwerk_tickets_pn_uidx')) {
			$tickets->addUniqueIndex(['project_id', 'number'], 'pwerk_tickets_pn_uidx');
		}

		if ($tickets->hasIndex('pwerk_tickets_bn_uidx')) {
			$tickets->dropIndex('pwerk_tickets_bn_uidx');
		}

		return $schema;
	}

	/**
	 * Bestand: den Projektzähler auf den höchsten Board-Zähler des Projekts
	 * heben.
	 *
	 * @param IOutput $output Fortschrittsausgabe.
	 * @param Closure $schemaClosure Liefert den Schema-Wrapper.
	 * @param array<string, mixed> $options Optionen des Migrationslaufs.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_projects') || !$schema->hasTable('pwerk_boards')) {
			return;
		}

		$lese = $this->connection->getQueryBuilder();
		$lese->select('project_id', 'ticket_counter')
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
				$qb->update('pwerk_projects')
					->set('ticket_counter', $qb->createNamedParameter((int)$board['ticket_counter'], IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$board['project_id'], IQueryBuilder::PARAM_INT)))
					// Nur nach oben — bei mehreren Boards je Projekt gewinnt der
					// höchste Zähler, und der Lauf bleibt idempotent.
					->andWhere($qb->expr()->lt('ticket_counter', $qb->createNamedParameter((int)$board['ticket_counter'], IQueryBuilder::PARAM_INT)));
				$gesetzt += $qb->executeStatement();
			}
			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}

		$output->info('pwerk_projects: ticket_counter auf ' . $gesetzt . ' Projekt(en) aus dem Board nachgezogen');
	}
}
