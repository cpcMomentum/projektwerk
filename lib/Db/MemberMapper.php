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
}
