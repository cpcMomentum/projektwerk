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
 * Die Spalten eines Boards.
 *
 * Spalten sind fuer beide Seiten identisch (§5.1) — hier wird nichts gefiltert.
 * Der Kontext steht trotzdem in der Signatur: Er belegt, dass die
 * Mitgliedschaft geprueft wurde, und liefert die Board-ID aus geprueften Daten
 * statt aus dem Request.
 *
 * @template-extends QBMapper<Column>
 */
class ColumnMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_columns', Column::class);
	}

	/**
	 * @return Column[] in Board-Reihenfolge
	 */
	public function findForBoard(ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq(
				'board_id',
				$qb->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}
}
