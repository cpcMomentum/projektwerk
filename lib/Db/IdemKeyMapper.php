<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<IdemKey>
 */
class IdemKeyMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_idem', IdemKey::class);
	}

	// Die Benutzerkennung ist die Grenze: Fremde Kennungen bleiben unsichtbar.
	public function findForUser(string $userId, string $key): IdemKey {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	public function deleteOlderThan(DateTime $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE)));

		return $qb->executeStatement();
	}
}
