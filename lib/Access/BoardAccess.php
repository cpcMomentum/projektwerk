<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Access;

use OCP\DB\QueryBuilder\IQueryBuilder;
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
		// Board → Projekt → Mitgliedschaft in **einer** Abfrage (#246 PR 2). Die
		// Rolle kommt aus der Projekt-Mitgliedschaft, nicht mehr aus einer
		// Zeile je Board; das Board liefert nur noch sein Projekt.
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.role', 'm.is_manager')
			->selectAlias('b.project_id', 'project_id')
			->from('pwerk_boards', 'b')
			->innerJoin('b', 'pwerk_members', 'm', $qb->expr()->andX(
				$qb->expr()->eq('m.project_id', 'b.project_id'),
				$qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)),
			))
			->where($qb->expr()->eq('b.id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
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
			(int)$row['project_id'],
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
		// Projekt-Mitgliedschaft → alle Boards dieser Projekte (#246 PR 2). Wer
		// Mitglied eines Projekts ist, ist es für jedes seiner Boards; die Rolle
		// gilt projektweit. So entsteht je Board ein Kontext mit derselben Rolle.
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias('b.id', 'board_id')
			->selectAlias('b.project_id', 'project_id')
			->addSelect('m.role', 'm.is_manager')
			->from('pwerk_members', 'm')
			->innerJoin('m', 'pwerk_boards', 'b', $qb->expr()->eq('b.project_id', 'm.project_id'))
			->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)))
			->orderBy('b.id', 'ASC');

		$result = $qb->executeQuery();
		$contexts = [];
		while ($row = $result->fetch()) {
			$contexts[] = ViewerContext::forMember(
				$userId,
				(int)$row['board_id'],
				(int)$row['project_id'],
				(string)$row['role'],
				(int)$row['is_manager'] === 1,
			);
		}
		$result->closeCursor();

		return $contexts;
	}
}
