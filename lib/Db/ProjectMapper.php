<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCA\Projektwerk\Access\ViewerContext;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Das Projekt-Dach (#246).
 *
 * Bewusst schmal: Ein Projekt trägt keine Sichtbarkeit — seine Zeile ist für
 * alle Mitglieder dieselbe. Es gibt hier **kein** `find(int $id)`; wo der
 * Projektname gebraucht wird, kommt er über die schon gesperrte
 * {@see BoardMapper::findAllForUser()} aus Sicht des Betrachters. Angelegt wird
 * ein Projekt einzig beim Anlegen eines Boards (dort besitzt die aufrufende
 * Person das Board ohnehin).
 *
 * @template-extends QBMapper<Project>
 */
class ProjectMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_projects', Project::class);
	}

	/**
	 * Die nächste Vorgangsnummer — **projektweit** fortlaufend (#246 PR 4).
	 *
	 * Ein Ticket-INSERT läuft in genau eine Nummer, und die zählt seit PR 4 am
	 * **Projekt**, nicht mehr am Board: Alle Boards eines Projekts teilen sich
	 * eine lückenlose Reihe, und der eindeutige Index `(project_id, number)`
	 * (Migration 17) macht daraus eine Datenbank-Invariante. Das Board zählt
	 * weiter seinen `change_seq` mit ({@see BoardMapper::bumpChangeSeq()}), weil
	 * die Client-Synchronisation je Board pollt.
	 *
	 * Wie beim Board: erst atomar erhöhen, dann den erhöhten Wert lesen. Kein
	 * `find(id)` — die Zeile wird über `project_id` aus dem Kontext getroffen,
	 * dessen Projekt-Mitgliedschaft {@see \OCA\Projektwerk\Access\BoardAccess}
	 * bereits bezeugt hat.
	 */
	public function claimTicketNumber(ViewerContext $viewer): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('ticket_counter', $qb->createFunction('ticket_counter + 1'))
			->where($qb->expr()->eq(
				'id',
				$qb->createNamedParameter($viewer->projectId, IQueryBuilder::PARAM_INT),
			));
		$qb->executeStatement();

		$read = $this->db->getQueryBuilder();
		$read->select('ticket_counter')
			->from($this->tableName)
			->where($read->expr()->eq(
				'id',
				$read->createNamedParameter($viewer->projectId, IQueryBuilder::PARAM_INT),
			));

		$result = $read->executeQuery();
		$number = (int)$result->fetchOne();
		$result->closeCursor();

		return $number;
	}
}
