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
	/** Die eigene Kennung — für „Meine Vorgänge" (#120). */
	me: string
	/**
	 * Kennungen der Vorgänge mit mindestens einem offenen Schritt (#119).
	 *
	 * Grundlage für „liegt bei niemandem": ohne Verantwortlichen und ohne
	 * offenen Schritt.
	 */
	withOpenSteps: number[]
	/**
	 * Board-Kennung => Zähler der **abgeschlossenen** Vorgänge (#226).
	 *
	 * Die offenen Zahlen (Neu/Offen/Wartet) leitet das Dashboard aus `tickets`
	 * und `waiting` ab; nur die erledigten fehlen dort, weil der Überblick
	 * geschlossene Vorgänge bewusst nicht lädt. `done` trägt „Erledigt" und den
	 * Fortschritt, `discarded` bleibt aus dem Fortschritts-Nenner.
	 */
	closedCounts: Record<number, { done: number, discarded: number }>
	/**
	 * Board-Kennung => Kennung der ersten Spalte (#226).
	 *
	 * Für den Status „Neu": ein offener Vorgang, dessen `columnId` gleich der
	 * ersten Spalte seines Boards ist, liegt noch in der Eingangsspalte.
	 */
	firstColumn: Record<number, number>
	/**
	 * Der Durchsatz (#226/#232): neu und erledigt in den letzten sieben Tagen,
	 * mit der Veränderung zur Vorwoche (`*Delta`), dazu die Tages-Zeitreihe der
	 * letzten 30 Tage für die Verlaufs-Kurven (`*Reihe`, älteste zuerst).
	 */
	durchsatz: {
		neu: number
		neuDelta: number
		erledigt: number
		erledigtDelta: number
		/** Ein Zähler je Tag über 30 Tage, älteste zuerst. */
		neuReihe: number[]
		/** Ein Zähler je Tag über 30 Tage, älteste zuerst. */
		erledigtReihe: number[]
	}
	/**
	 * Board-Kennung => neue Vorgänge der letzten sieben Tage (#232).
	 *
	 * Die Marke „N diese Woche" an der Projekt-Kachel — der Durchsatz oben nennt
	 * die Summe über alle Projekte, dies bricht sie auf das einzelne herunter.
	 * Nur Projekte mit mindestens einem neuen Vorgang stehen drin.
	 */
	neuDieseWoche: Record<number, number>
}

/**
 * Eine Zeile in den Ballbesitz-Abschnitten „Meine Vorgänge" (#120) und „Liegt
 * bei niemandem" (#119) — der Vorgang und sein Projekt, mehr braucht es nicht.
 */
export interface OverviewTicketRow {
	ticket: Ticket
	board: TaskBoard | null
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

/**
 * Eine Zeile der Projekt-Status-Tabelle im Dashboard (#226).
 *
 * Fasst je Projekt die kanonischen Status zusammen (Neu/Offen/Wartet aus der
 * offenen Menge, Erledigt aus `closedCounts`) und leitet daraus Fortschritt und
 * ein **Zustandssignal** ab — getrennt vom Status, „wo liegt der Ball".
 */
export interface ProjectStatusRow {
	boardId: number
	title: string
	/** Beide Firmennamen, wie in {@see ProjectRow}. */
	org: string
	/** Offen, noch in der Eingangsspalte — neu reingekommen. */
	neu: number
	/** Offen, in Arbeit (nicht neu, wartet nicht). */
	offen: number
	/** Offen, wartet auf die Kundenseite. */
	wartet: number
	/** Abgeschlossen mit Ergebnis erledigt. */
	erledigt: number
	/** Abgeschlossen mit Ergebnis verworfen — nicht im Fortschritts-Nenner. */
	verworfen: number
	/**
	 * Neue Vorgänge der letzten sieben Tage in diesem Projekt (#232) — die
	 * Marke „▲ N diese Woche". `0`, wenn diese Woche nichts dazukam; die Kachel
	 * zeigt die Marke dann nicht.
	 */
	neuDieseWoche: number
	/** Offene Vorgänge gesamt (neu + offen + wartet). */
	offenGesamt: number
	/** Anteil erledigt an (erledigt + offen), 0..1; 0 wenn nichts vorliegt. */
	fortschritt: number
	/**
	 * „Wo liegt der Ball" — aus den Daten abgeleitet, nicht gepflegt:
	 * `rot` überfällig, `gelb` beim Kunden, `grau` steht still, `gruen` läuft.
	 */
	zustand: 'rot' | 'gelb' | 'grau' | 'gruen'
}
