<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Access;

use OCA\Projektwerk\Db\Ticket;

/**
 * Der Punkt „neu oder seit deinem Blick geändert" (#79, #175).
 *
 * Eine **reine** Berechnung über bereits geladene Daten — wie
 * {@see WaitStateCalculator}: Der Aufrufer ({@see \OCA\Projektwerk\Controller\TicketController})
 * bringt die sichtbaren Vorgänge, den eigenen Lesestand und den jüngsten
 * Kommentar je Vorgang mit; hier wird nur verglichen. Kein Datenbankzugriff und
 * damit keine zweite Stelle, an der die Sichtbarkeit stimmen müsste.
 *
 * **Die Regel (#175):**
 * - Eigene Anlage, Änderung oder Kommentar → **kein** Punkt. Wer selbst
 *   geschrieben hat, muss sich darüber nicht informieren lassen.
 * - Fremde Änderung an einem bereits gesehenen Vorgang → Punkt (wie #79).
 * - Fremd angelegter, noch ungesehener Vorgang → Punkt (neu statt bloß
 *   geändert).
 *
 * Die Prüfung auf den letzten Bearbeiter bzw. den Autor des jüngsten Kommentars
 * ist **zielgenauer**, als den Lesestand beim eigenen Schreiben nachzuziehen:
 * Sie übergeht nur die eigene Bewegung, nicht alles, was davor lag.
 */
class ChangeHighlighter {

	/**
	 * Welche Vorgänge für diese Person hervorgehoben werden.
	 *
	 * @param Ticket[] $tickets Die (bereits sichtbaren) Vorgänge.
	 * @param array<int, string> $seen ticketId => eigener Lesestand (ATOM), fehlt bei ungesehenen.
	 * @param array<int, array{at: string, author: string}> $newestComment ticketId => jüngster Kommentar.
	 * @param string $viewerUserId Die auslesende Person.
	 * @return array<int, bool> ticketId => true, nur für hervorzuhebende Vorgänge.
	 */
	public function detect(array $tickets, array $seen, array $newestComment, string $viewerUserId): array {
		$changed = [];

		foreach ($tickets as $ticket) {
			$id = (int)$ticket->getId();

			// Neu, nicht nur geändert: ein Vorgang ohne eigenen Lesestand ist neu
			// — außer die auslesende Person hat ihn selbst angelegt, dann kennt
			// sie ihn schon.
			if (!isset($seen[$id])) {
				if ($ticket->getCreatorUserId() !== $viewerUserId) {
					$changed[$id] = true;
				}

				continue;
			}

			$seenTs = (int)strtotime($seen[$id]);

			// Die eigene Änderung leuchtet nicht: War die auslesende Person der
			// letzte Bearbeiter, zählt die Vorgangs-Bewegung nicht.
			$activityTs = $ticket->getLastEditorUserId() === $viewerUserId
				? 0
				: ($ticket->getUpdatedAt()?->getTimestamp() ?? 0);

			// Ebenso der eigene Kommentar: Er zählt nur, wenn der jüngste
			// Kommentar von jemand anderem stammt.
			$comment = $newestComment[$id] ?? null;
			$commentTs = ($comment !== null && $comment['author'] !== $viewerUserId)
				? (int)strtotime($comment['at'])
				: 0;

			if (max($activityTs, $commentTs) > $seenTs) {
				$changed[$id] = true;
			}
		}

		return $changed;
	}
}
