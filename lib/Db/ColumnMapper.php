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

	/**
	 * Die erste Spalte (kleinste Position) je Board — Grundlage fuer den Status
	 * „Neu" im Dashboard (#226): ein offener Vorgang, der noch in der
	 * Eingangsspalte liegt, ist neu und noch nicht aufgegriffen.
	 *
	 * Spalten sind fuer beide Seiten identisch und nicht sichtbarkeits-gefiltert
	 * (§5.1); eingeschraenkt wird nur auf die uebergebenen Boards des Betrachters.
	 * Die Reihenfolge ist dieselbe wie in {@see findForBoard()} (Position, dann
	 * Id), damit „erste Spalte" hier und im Board dasselbe meint.
	 *
	 * @param int[] $boardIds Die Boards des Betrachters.
	 * @return array<int, int> Board-Kennung => Kennung der ersten Spalte
	 */
	public function findFirstColumnByBoard(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'board_id')
			->from($this->tableName)
			->where($qb->expr()->in(
				'board_id',
				$qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->orderBy('board_id', 'ASC')
			->addOrderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$first = [];
		while (($row = $result->fetch()) !== false) {
			$boardId = (int)$row['board_id'];
			// Die erste Zeile je Board gewinnt — die Sortierung stellt sicher,
			// dass das die Spalte mit der kleinsten Position ist.
			if (!isset($first[$boardId])) {
				$first[$boardId] = (int)$row['id'];
			}
		}
		$result->closeCursor();

		return $first;
	}
}
