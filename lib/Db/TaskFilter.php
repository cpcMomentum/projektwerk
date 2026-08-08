<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

/**
 * Die Einschraenkungen der boarduebergreifenden Sicht „Meine Aufgaben".
 *
 * Heute traegt dieser Typ genau ein Feld — und ist trotzdem kein Umweg um ein
 * `bool`. Der Grund steht in der Signatur von
 * {@see TicketMapper::findVisibleAcrossBoards()}: Phase 4 bringt Sortierung,
 * Faelligkeit und Boardauswahl dazu. Kaeme das als weitere Parameter, muesste
 * jede Aufrufstelle mit — hier waechst der Typ, die Aufrufe bleiben.
 *
 * Was **nicht** hier hineingehoert: alles, was die Sichtbarkeit betrifft. Die
 * Regel steht in {@see \OCA\Projektwerk\Access\TicketScope} und nimmt keine
 * Parameter von aussen entgegen. Ein Filter, der sie aufweichen koennte, waere
 * der zweite Ort, an dem sie stimmen muesste.
 */
final readonly class TaskFilter {

	/**
	 * @param bool $includeClosed Geschlossene Tickets verschwinden aus „Meine
	 *                            Aufgaben" (§4). Der Schalter existiert fuer
	 *                            spaetere Sichten, nicht fuer die Startseite.
	 */
	private function __construct(
		public bool $includeClosed,
	) {
	}

	/** Die Startseite: nur Offenes. */
	public static function openOnly(): self {
		return new self(false);
	}

	public static function withClosed(): self {
		return new self(true);
	}
}
