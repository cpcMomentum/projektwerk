<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Access;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Die Sichtbarkeitsregel — als JOIN, nicht als gebundene Rolle.
 *
 * §8 formuliert die Bedingung mit einem Parameter `:meineRolle`. Hier steht
 * stattdessen ein Verbund auf `pwerk_members`, und die Rolle kommt aus der
 * verbundenen Spalte:
 *
 *     INNER JOIN pwerk_members m ON m.board_id = t.board_id AND m.user_id = :uid
 *     WHERE (   t.visibility = 'public'
 *            OR (t.visibility = 'internal' AND t.creator_role    = m.role)
 *            OR (t.visibility = 'private'  AND t.creator_user_id = :uid) )
 *
 * Vier Gruende, die den Mehraufwand gegenueber der Spezifikationsfassung
 * aufwiegen — der Reihe nach der wichtigste zuerst:
 *
 * 1. **Nicht-Mitgliedschaft faellt aus dem INNER JOIN**, auch wenn
 *    `BoardAccess` einmal umgangen wuerde. Zwei unabhaengige Sperren statt
 *    einer.
 * 2. **"Meine Aufgaben" ist eine einzige Abfrage** ueber alle Boards, keine
 *    orX()-Gruppe ueber N Kontexte — und damit keine zweite Implementierung
 *    der Regel, die irgendwann auseinanderlaeuft.
 * 3. **Kein Rollen-Cache**, also keine Fehlerklasse rund um verzoegerten
 *    Rechteentzug.
 * 4. Zaehler koennen dieselbe Bedingung als korrelierte Unterabfrage
 *    wiederverwenden, statt eine `IN (...)`-Liste zu materialisieren. Das
 *    umgeht die Parameterlimits von MySQL und PostgreSQL — und macht es
 *    strukturell unmoeglich, dass ein Zaehler zaehlt, was verborgen ist.
 *
 * Diese Datei und `lib/Db/TicketMapper.php` sind die **einzigen** Stellen
 * ausserhalb der Migration, an denen `pwerk_tickets` vorkommen darf. Der
 * Architekturtest in der CI setzt das durch.
 */
class TicketScope {

	public const VISIBILITY_PUBLIC = 'public';
	public const VISIBILITY_INTERNAL = 'internal';
	public const VISIBILITY_PRIVATE = 'private';

	/** Alias, unter dem der Mitgliedschaftsverbund haengt. */
	public const MEMBER_ALIAS = 'pwerk_scope_m';

	/**
	 * Haengt Verbund und Bedingung an eine bestehende Abfrage.
	 *
	 * Der Aufrufer hat `from('pwerk_tickets', $ticketAlias)` bereits gesetzt.
	 * `$boardId` einschraenken heisst Einzelboard-Sicht; `null` heisst
	 * boarduebergreifend — dann gilt die Regel je Board mit der dort
	 * geltenden Rolle, ohne dass der Aufrufer etwas dafuer tun muss.
	 */
	public function apply(IQueryBuilder $qb, string $ticketAlias, string $userId, ?int $boardId): void {
		$m = self::MEMBER_ALIAS;
		$uidParam = $qb->createNamedParameter($userId);

		$qb->innerJoin(
			$ticketAlias,
			'pwerk_members',
			$m,
			$qb->expr()->andX(
				$qb->expr()->eq($m . '.board_id', $ticketAlias . '.board_id'),
				$qb->expr()->eq($m . '.user_id', $uidParam),
			),
		);

		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->eq(
				$ticketAlias . '.visibility',
				$qb->createNamedParameter(self::VISIBILITY_PUBLIC),
			),
			$qb->expr()->andX(
				$qb->expr()->eq(
					$ticketAlias . '.visibility',
					$qb->createNamedParameter(self::VISIBILITY_INTERNAL),
				),
				// Die Symmetrie von 'internal': sichtbar fuer alle, die
				// dieselbe Rolle haben wie die erzeugende Person. creator_role
				// ist am Ticket eingefroren, damit das stabil bleibt, wenn
				// jemand die Rolle wechselt oder das Board verlaesst.
				$qb->expr()->eq($ticketAlias . '.creator_role', $m . '.role'),
			),
			$qb->expr()->andX(
				$qb->expr()->eq(
					$ticketAlias . '.visibility',
					$qb->createNamedParameter(self::VISIBILITY_PRIVATE),
				),
				$qb->expr()->eq($ticketAlias . '.creator_user_id', $uidParam),
			),
		));

		if ($boardId !== null) {
			$qb->andWhere($qb->expr()->eq(
				$ticketAlias . '.board_id',
				$qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT),
			));
		}
	}
}
