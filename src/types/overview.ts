/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Form des Überblicks — der Einstieg in die App (#76).
 *
 * **Eine Antwort, zwei Abschnitte, und beide aus derselben Menge.** Was bei der
 * Kundenseite liegt und welche Projekte Bewegung haben, sind zwei Blicke auf
 * dieselben Vorgänge. Zwei Abfragen dafür hießen, dass sich die beiden Hälften
 * einer Seite widersprechen können.
 */

import type { TaskBoard } from '@/types/task'
import type { Ticket, WaitState } from '@/types/ticket'

export interface OverviewData {
	/** Alles Sichtbare über alle Projekte, nur Offenes. */
	tickets: Ticket[]
	/**
	 * Ticket-Kennung => Wartezustand.
	 *
	 * Nur für Vorgänge, die wirklich warten — der Server lässt die übrigen weg.
	 */
	waiting: Record<number, WaitState>
	/** Board-Kennung => Herkunftszeile. Einmal je Board, nicht je Vorgang. */
	boards: Record<number, TaskBoard>
	/**
	 * Board-Kennung => Kennung => Anzeigename.
	 *
	 * **Nach Projekt geschachtelt**, weil `display_name` an der Mitgliedschaft
	 * ein Übersteuern je Projekt ist: Dieselbe Person kann in einem Projekt
	 * unter ihrem Firmennamen stehen und in einem anderen unter ihrem eigenen.
	 * Ein Vorgang gehört zu genau einem Projekt, also ist die Zuordnung
	 * eindeutig — flach wäre sie es nicht.
	 */
	names: Record<number, Record<string, string>>
}

/**
 * Eine Zeile im Abschnitt „Wartet auf die Kundenseite".
 *
 * Der Vorgang und sein Projekt stehen dabei: Auf einer projektübergreifenden
 * Seite ist der Ort die halbe Information.
 */
export interface WaitingRow {
	ticket: Ticket
	board: TaskBoard | null
	/** Aufgelöste Namen der Wartenden, nicht ihre Kennungen (#104). */
	names: string[]
	/** Seit wann, als `JJJJ-MM-TT`. */
	since: string
	/** Wie viele Tage das her ist — die Zahl, die die Zeile ordnet. */
	days: number
}

/**
 * Eine Zeile im Abschnitt „Projekte mit Bewegung".
 *
 * **Dieselbe Zeilenform wie oben**, nur mit dem Projektnamen an der Stelle des
 * Vorgangstitels. Das Mockup hatte hier eine Kachelreihe — drei verschiedene
 * Formen auf einer Seite waren Axels Befund „zu unstrukturiert" (2026-08-13).
 */
export interface ProjectRow {
	boardId: number
	title: string
	/** Beide Firmennamen, nicht nur einer. */
	org: string
	open: number
	waiting: number
	/**
	 * Tage seit der letzten Bewegung im Projekt (#116) — aus dem jüngsten
	 * `updatedAt` seiner offenen Vorgänge, oder `null`, wenn keiner eins trägt.
	 *
	 * Trägt den „steht still"-Hinweis: Ein Projekt, das nicht auf den Kunden
	 * wartet und trotzdem lange ruht, ist das, was man übersieht.
	 */
	lastMovementDays: number | null
}
