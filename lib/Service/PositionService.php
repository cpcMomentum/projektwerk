<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Wo ein Ticket zwischen zwei Nachbarn landet — reine Rechnung, keine
 * Datenbank.
 *
 * **Ganzzahlen mit Schrittweite 65536, nicht Fliesskommazahlen.** Die
 * naheliegende Halbierung eines `float` ist nach etwa fuenfzig Einfuegungen an
 * derselben Stelle praezisionsabhaengig — und sie ist es auf SQLite, MySQL und
 * PostgreSQL **unterschiedlich**. Ein Board, das auf einer Installation richtig
 * sortiert und auf einer anderen nicht, waere nicht nachvollziehbar.
 *
 * **Gerechnet wird in PHP, nicht in SQL.** Die Ganzzahldivision verhaelt sich
 * ueber die drei Datenbanken nicht gleich (Rundung gegen null oder gegen minus
 * unendlich). `intdiv()` ist eine Antwort, drei `AVG()`-Dialekte waeren drei.
 */
class PositionService {

	/**
	 * Abstand zwischen zwei frisch vergebenen Positionen.
	 *
	 * 65536 erlaubt sechzehn Einfuegungen an derselben Stelle, bevor die Luecke
	 * aufgebraucht ist — danach greift {@see needsRebalance()}.
	 */
	public const STEP = 65536;

	/**
	 * Die Position zwischen zwei Nachbarn.
	 *
	 * Beide Nachbarn sind `null`-faehig, und das deckt die Raender ab: kein
	 * Vorgaenger heisst „ganz nach oben", kein Nachfolger „ganz nach unten",
	 * keiner von beiden „erstes Ticket der Spalte".
	 *
	 * **Die Positionen stammen aus der ungefilterten Liste** (§3.8). Wer die
	 * gefilterte naehme, sortierte ein Ticket je nach Betrachter woanders ein —
	 * und zwei Personen saehen dieselbe Spalte in verschiedener Reihenfolge.
	 *
	 * @param int|null $before Position des Tickets darueber
	 * @param int|null $after  Position des Tickets darunter
	 * @throws \InvalidArgumentException wenn die Nachbarn verkehrt herum stehen
	 */
	public function between(?int $before, ?int $after): int {
		if ($before === null && $after === null) {
			return self::STEP;
		}

		if ($before === null) {
			// Ganz nach oben. Nicht `$after - STEP`, weil das nach genuegend
			// Einfuegungen ins Negative laeuft und der Spielraum nach oben
			// endlich ist; die Haelfte des Vorhandenen halbiert stattdessen die
			// verbleibende Luecke.
			return intdiv((int)$after, 2);
		}

		if ($after === null) {
			return $before + self::STEP;
		}

		if ($after <= $before) {
			// Kein stiller Notausgang: Der Aufrufer hat Nachbarn in falscher
			// Reihenfolge geschickt, und irgendeine Position zu erfinden
			// verschoebe das Ticket sichtbar woandershin, als der Nutzer wollte.
			throw new \InvalidArgumentException(
				'Nachbarn stehen verkehrt herum: ' . $before . ' >= ' . $after,
			);
		}

		return $before + intdiv($after - $before, 2);
	}

	/**
	 * Ist die Luecke zwischen zwei Nachbarn aufgebraucht?
	 *
	 * Bei einem Abstand von 1 gibt es keine Ganzzahl mehr dazwischen —
	 * `between()` lieferte dann dieselbe Position wie der Vorgaenger, und die
	 * Reihenfolge haenge an der ID statt am Willen des Nutzers. Dann muss die
	 * Spalte neu nummeriert werden.
	 */
	public function needsRebalance(?int $before, ?int $after): bool {
		if ($before === null || $after === null) {
			// An den Raendern ist immer Platz: nach unten unbegrenzt, nach oben
			// solange die halbierte Luecke noch groesser als null ist.
			return $before === null && $after !== null && $after <= 1;
		}

		return $after - $before <= 1;
	}

	/**
	 * Die neuen Positionen einer Spalte, in der uebergebenen Reihenfolge.
	 *
	 * Nimmt Ticket-IDs **in Sollreihenfolge** und liefert je Ticket die neue
	 * Position. Ausdruecklich keine Datenbankarbeit: Der Aufrufer laedt die
	 * ungefilterte Reihenfolge und schreibt das Ergebnis, diese Klasse rechnet.
	 * Damit ist der Teil, der schiefgehen kann, ohne Container pruefbar.
	 *
	 * @param int[] $ticketIdsInOrder
	 * @return array<int, int> Ticket-ID => neue Position
	 */
	public function rebalance(array $ticketIdsInOrder): array {
		$positions = [];
		$next = self::STEP;

		foreach ($ticketIdsInOrder as $ticketId) {
			$positions[(int)$ticketId] = $next;
			$next += self::STEP;
		}

		return $positions;
	}
}
