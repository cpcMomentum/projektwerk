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
 * Die Mitgliederliste eines Boards.
 *
 * Bewusst **kein** Erzeuger von {@see ViewerContext} — das ist allein
 * {@see \OCA\Projektwerk\Access\BoardAccess}, das dafuer direkt liest. Diese
 * Klasse bedient die Anzeige (Mitgliederverwaltung, Personenauswahl), nicht die
 * Zugriffskontrolle. Zwei Klassen mit demselben Zweck waeren zwei Orte, an
 * denen die Rollenermittlung stimmen muesste.
 *
 * @template-extends QBMapper<Member>
 */
class MemberMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_members', Member::class);
	}

	/**
	 * Alle Mitglieder des Boards, dessen Mitgliedschaft dieser Kontext bezeugt.
	 *
	 * Interne und externe gemeinsam und ohne Trennung: Die Personenauswahl an
	 * einem oeffentlichen Ticket zeigt beide Seiten nebeneinander — der
	 * Kundenzugriff ist Zweck des Produkts, keine Ausnahme (§ Personenauswahl).
	 * Wo Externe nicht erscheinen duerfen (interne und private Tickets), filtert
	 * die aufrufende Schicht, nicht die Abfrage.
	 *
	 * @return Member[]
	 */
	public function findForBoard(ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq(
				'board_id',
				$qb->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			))
			->orderBy('role', 'ASC')
			->addOrderBy('user_id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Alle Mitgliedschaften der Boards, in denen diese Person selbst Mitglied
	 * ist — fuer den Ueberblick (#76).
	 *
	 * **Warum es das braucht.** Die Wartemarke nennt Namen und keine Kennungen
	 * (#104), und die Aufloesung gehoert auf den Server: Nextclouds
	 * Personensuche liefert in einer Gast-Sitzung prinzipbedingt eine leere
	 * Liste, ein Nachschlagen im Browser bliebe also ausgerechnet beim Kunden
	 * stumm. {@see findForBoard()} beantwortet das nur fuer **ein** Board; der
	 * Ueberblick steht ueber allen, und bei mehr als zwanzig Projekten (Axel,
	 * 2026-08-13) waere ein Aufruf je Board keine Loesung.
	 *
	 * **Die Bedingung ist eine Unterabfrage auf dieselbe Tabelle**, kein
	 * Verbund: „Boards, in denen ich Mitglied bin" ist genau die Menge, die
	 * {@see BoardMapper::findAllForUser()} ueber ihre eigene Tabelle
	 * beantwortet, und beides nebeneinander waere dieselbe Frage zweimal
	 * gestellt. Der Parameter entsteht am **aeusseren** Builder — nur dort wird
	 * er gebunden.
	 *
	 * **Kein Sichtbarkeitsfilter, und das ist richtig:** Wer Mitglied eines
	 * Boards ist, darf dessen Mitgliederliste sehen — genau das tut die
	 * Mitgliederverwaltung heute schon ueber `findForBoard()`. Diese Methode
	 * fuegt keine Zeile hinzu, die dort nicht auch schon zu sehen waere; sie
	 * beantwortet nur mehrere Boards auf einmal.
	 *
	 * @return Member[]
	 */
	public function findForUserBoards(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$uid = $qb->createNamedParameter($userId);

		$meine = $this->db->getQueryBuilder();
		$meine->select('board_id')
			->from($this->tableName)
			->where($meine->expr()->eq('user_id', $uid));

		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->in(
				'board_id',
				$qb->createFunction($meine->getSQL()),
			))
			->orderBy('board_id', 'ASC')
			->addOrderBy('user_id', 'ASC');

		return $this->findEntities($qb);
	}
}
