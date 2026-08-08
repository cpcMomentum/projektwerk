<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Access;

use OCP\IDBConnection;

/**
 * Der einzige Erzeuger von {@see ViewerContext}.
 *
 * Liest `pwerk_members`. Fehlt der Eintrag, wird abgewiesen — **bevor** eine
 * Ticket-Abfrage laeuft (§8). Es gibt hier bewusst keine Admin-Ausnahme und
 * keinen Sonderweg fuer den Board-Eigentuemer: Eine Hintertuer wuerde die
 * Zusage entwerten, auf der das ganze Produkt beruht. Der Kein-Admin-Test in
 * der CI haelt das fest.
 *
 * Kein Rollen-Cache. Ein Cache waere die Klasse von Fehlern, bei der ein
 * Rechteentzug verzoegert wirkt — und genau das faellt beim Kunden auf, nicht
 * im Test.
 */
class BoardAccess {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @throws NotAMemberException wenn die Person nicht Mitglied des Boards ist
	 */
	public function contextFor(string $userId, int $boardId): ViewerContext {
		$qb = $this->db->getQueryBuilder();
		$qb->select('role', 'is_manager')
			->from('pwerk_members')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			// Absichtlich ohne Angabe, ob das Board existiert: Die Fehlerform
			// darf nicht verraten, was die Abfrage nicht verraten darf.
			throw new NotAMemberException('Kein Mitglied des Boards');
		}

		return ViewerContext::forMember(
			$userId,
			$boardId,
			(string)$row['role'],
			(int)$row['is_manager'] === 1,
		);
	}

	/**
	 * Alle Mitgliedschaften einer Person, je mit der dort geltenden Rolle.
	 *
	 * Fuer boarduebergreifende Sichten (Meine Aufgaben, spaeter Suche und
	 * Dashboard). Die Abfrage selbst braucht das **nicht** — `TicketScope`
	 * verbindet direkt auf `pwerk_members` und ist damit eine einzige Abfrage
	 * ohne zweite Implementierung der Regel. Diese Liste dient dem, was um die
	 * Abfrage herum steht: Board-Titel, Schreibrechte, leere Zustaende.
	 *
	 * @return ViewerContext[] leer, wenn die Person in keinem Board ist
	 */
	public function allContextsFor(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id', 'role', 'is_manager')
			->from('pwerk_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('board_id', 'ASC');

		$result = $qb->executeQuery();
		$contexts = [];
		while ($row = $result->fetch()) {
			$contexts[] = ViewerContext::forMember(
				$userId,
				(int)$row['board_id'],
				(string)$row['role'],
				(int)$row['is_manager'] === 1,
			);
		}
		$result->closeCursor();

		return $contexts;
	}
}
