<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCA\Projektwerk\Access\ViewerContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Boards — auch hier ohne kontextfreie Lesemethode.
 *
 * Die Einschraenkung ist schwaecher begruendet als beim {@see TicketMapper}
 * (ein Board traegt keine Sichtbarkeit, seine Zeile ist fuer alle Mitglieder
 * dieselbe), aber sie kostet nichts und haelt die Bauform einheitlich: Wer eine
 * Boardzeile bekommt, hat vorher {@see \OCA\Projektwerk\Access\BoardAccess}
 * passiert. Eine Methode `find(int $id)` waere die eine Stelle, an der ein
 * Nichtmitglied Titel und Ordnerpfade eines fremden Projekts liest.
 *
 * @template-extends QBMapper<Board>
 */
class BoardMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_boards', Board::class);
	}

	/**
	 * Das Board, dessen Mitgliedschaft dieser Kontext bezeugt.
	 *
	 * @throws DoesNotExistException wenn die Boardzeile fehlt — dann ist die
	 *                               Mitgliedschaft verwaist, nicht der Zugriff
	 *                               unberechtigt
	 */
	public function findForViewer(ViewerContext $viewer): Board {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq(
				'id',
				$qb->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			));

		return $this->findEntity($qb);
	}

	/**
	 * Alle Boards, in denen diese Person Mitglied ist — die Startseite.
	 *
	 * Der Verbund auf `pwerk_members` ist dieselbe Sperre wie in
	 * {@see \OCA\Projektwerk\Access\TicketScope}: Nichtmitgliedschaft faellt aus
	 * dem INNER JOIN, es gibt keine Liste „alle Boards" und damit auch keine
	 * Stelle, an der man sie versehentlich ausliefert.
	 *
	 * @param bool $includeArchived archivierte Projekte erscheinen nur, wo man
	 *                              sie ausdruecklich sehen will
	 * @return Board[]
	 */
	public function findAllForUser(string $userId, bool $includeArchived = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.*')
			->from($this->tableName, 'b')
			->innerJoin(
				'b',
				'pwerk_members',
				'm',
				$qb->expr()->andX(
					$qb->expr()->eq('m.board_id', 'b.id'),
					$qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)),
				),
			)
			->orderBy('b.title', 'ASC')
			->addOrderBy('b.id', 'ASC');

		if (!$includeArchived) {
			$qb->andWhere($qb->expr()->eq(
				'b.archived',
				$qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Den Änderungszähler des Boards erhöhen (§3.8, Ergänzung 4).
	 *
	 * `change_seq` zählt jeden Schreibvorgang im Board mit — der Delta-Poll der
	 * Client-Synchronisation pollt **je Board** und hängt daran. Seit #246 PR 4
	 * wandert allein die **Nummernvergabe** aufs Projekt
	 * ({@see ProjectMapper::claimTicketNumber()}); der Board-`change_seq` bleibt,
	 * weil ein neues Ticket eine Änderung genau dieses Boards ist, nicht des
	 * ganzen Projekts.
	 *
	 * Das `UPDATE` nimmt die Sperre auf die Board-Zeile und gehört in die
	 * Transaktion des Aufrufers.
	 */
	public function bumpChangeSeq(ViewerContext $viewer): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('change_seq', $qb->createFunction('change_seq + 1'))
			->where($qb->expr()->eq(
				'id',
				$qb->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			));
		$qb->executeStatement();
	}
}
