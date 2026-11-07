<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Nur Schreiben: Gelesen wird über {@see TicketMapper::findVisibleDependencies()}, damit die Sichtbarkeit am Partner greift.
 *
 * @template-extends QBMapper<TicketDep>
 */
class TicketDepMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_ticket_deps', TicketDep::class);
	}

	/** @return bool false, wenn das Paar schon bestand */
	public function insertPair(TicketDep $dep): bool {
		try {
			$this->insert($dep);
			return true;
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}

	public function deletePair(int $ticketId, int $dependsOnId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('depends_on_id', $qb->createNamedParameter($dependsOnId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}
}
