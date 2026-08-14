<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Access;

use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\Ticket;

/**
 * „Wartet auf Kunde" — gerechnet, nicht gespeichert.
 *
 * **Nichts an dieser Klasse landet in der Datenbank, und das ist der Punkt.**
 * Ein gespeichertes Feld waere ein zweiter Ort fuer dieselbe Aussage: Es muesste
 * bei jedem Zuweisen, Erledigen, Loeschen und Rollenwechsel mitgepflegt werden,
 * und die erste vergessene Stelle erzeugt eine Marke, die niemand mehr erklaeren
 * kann. Hier folgt der Zustand aus den Schritten, immer.
 *
 * **Zwei Quellen, dieselbe Aussage.** Ein Vorgang wartet auf die Kundenseite,
 * wenn eines von beiden zutrifft:
 *
 * - **mindestens ein offener Schritt** mit gesetzter Zuweisung und
 *   `assigned_role = 'external'`, oder
 * - **ein Verantwortlicher mit eingefrorener externer Rolle** (#114). Nicht
 *   jeder Vorgang wird in Schritte zerlegt; einer, der jemandem auf der
 *   Gegenseite gehoert, liegt trotzdem dort.
 *
 * Das Datum ist das kleinste unter `assigned_at` der wartenden Schritte und
 * `responsible_since` — nicht das juengste, sonst spraenge die Marke bei jeder
 * weiteren Zuweisung auf ein neueres Datum und verloere ihren Sinn als
 * Wartezeit. Die Kennungen sind die der externen Schritt-Bearbeiter und, falls
 * er wartet, des Verantwortlichen.
 *
 * `assigned_role` **und** `responsible_role` werden beim Zuweisen **kopiert**
 * und nicht zur Laufzeit ermittelt. Sonst kippte der Wartezustand rueckwirkend,
 * sobald jemand die Rolle wechselt oder das Board verlaesst — an Vorgaengen, die
 * seit Wochen unveraendert sind.
 *
 * **Was hier (noch) NICHT steht:** „in Verzug". Warten ist ein Zustand des
 * Ballbesitzes; wirklich zu spaet ist ein Vorgang erst, wenn eine Faelligkeit
 * gerissen ist. Die Ticket-Faelligkeit kommt mit #72; bis dahin traegt nur der
 * Schritt ein Datum.
 */
class WaitStateCalculator {

	/**
	 * Der Wartezustand eines Tickets, oder `null`.
	 *
	 * **An geschlossenen Tickets wird nicht gerechnet** (E8). Ein extern
	 * zugewiesener Schritt ueberlebt das Schliessen seines Tickets — er wird
	 * nicht automatisch erledigt, weil das eine Aussage ueber die Wirklichkeit
	 * waere, die niemand getroffen hat. Aber ein geschlossener Vorgang wartet
	 * auf niemanden mehr, und eine Marke daran waere eine Aufforderung ins
	 * Leere.
	 *
	 * @param Step[] $steps Die Schritte **dieses** Tickets, aus der gefilterten
	 *                      Menge — nie eigenstaendig abgefragt (§5.8).
	 * @return array{since: string, userIds: string[]}|null
	 */
	public function forTicket(Ticket $ticket, array $steps): ?array {
		if ($ticket->getClosedAt() !== null) {
			return null;
		}

		// `waitsOnExternal()` steht an der Entitaet und wird hier benutzt statt
		// nachgebaut. Die zusaetzliche Pruefung auf die Kennung ist keine zweite
		// Fassung der Regel, sondern eine Zusicherung: Eine Rolle ohne Person
		// waere ein Datenfehler, und eine Marke ohne nennbaren Namen koennte der
		// Satz im Detail nicht ausfuellen.
		$waiting = array_filter(
			$steps,
			static fn (Step $step): bool => $step->waitsOnExternal() && $step->getAssignedUserId() !== null,
		);

		// Die zweite Quelle: der Verantwortliche selbst (#114). `waitsOnExternal()`
		// an der Entitaet prueft Rolle **und** Kennung — dieselbe Zusicherung wie
		// beim Schritt.
		$ticketWaits = $ticket->waitsOnExternal();

		if ($waiting === [] && !$ticketWaits) {
			return null;
		}

		$since = null;
		$userIds = [];
		foreach ($waiting as $step) {
			$assignedAt = $step->getAssignedAt();
			if ($assignedAt !== null && ($since === null || $assignedAt < $since)) {
				$since = $assignedAt;
			}

			$userId = (string)$step->getAssignedUserId();
			if (!in_array($userId, $userIds, true)) {
				$userIds[] = $userId;
			}
		}

		if ($ticketWaits) {
			$responsibleSince = $ticket->getResponsibleSince();
			if ($responsibleSince !== null && ($since === null || $responsibleSince < $since)) {
				$since = $responsibleSince;
			}

			$userId = (string)$ticket->getResponsibleUserId();
			if (!in_array($userId, $userIds, true)) {
				$userIds[] = $userId;
			}
		}

		return [
			// `assigned_at` kann fehlen, wenn eine Zuweisung aus einer aelteren
			// Fassung stammt. Dann steht die Marke ohne Datum da — das ist
			// ehrlicher als ein erfundenes.
			'since' => $since?->format(\DateTime::ATOM) ?? '',
			'userIds' => $userIds,
		];
	}

	/**
	 * Derselbe Zustand fuer eine ganze Liste, je Ticket-ID.
	 *
	 * Fuer die Boardansicht und den Filterschalter „Nur wartend". Beide fragen
	 * dieselbe Rechnung, deshalb gibt es sie einmal.
	 *
	 * @param Ticket[] $tickets
	 * @param Step[] $steps Alle Schritte der gefilterten Ticketmenge.
	 * @return array<int, array{since: string, userIds: string[]}>
	 */
	public function forTickets(array $tickets, array $steps): array {
		$byTicket = [];
		foreach ($steps as $step) {
			$byTicket[(int)$step->getTicketId()][] = $step;
		}

		$states = [];
		foreach ($tickets as $ticket) {
			$id = (int)$ticket->getId();
			$state = $this->forTicket($ticket, $byTicket[$id] ?? []);
			if ($state !== null) {
				$states[$id] = $state;
			}
		}

		return $states;
	}
}
