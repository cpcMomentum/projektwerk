/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Aggregation eines Projekts fürs Projekt-Dashboard (#227, Ebene 2).
 *
 * **Rein und ohne Vue**, damit die Ableitung ohne Browser prüfbar ist: Sie
 * entscheidet, in welchem Topf ein Vorgang zählt, und genau da entstehen die
 * stillen Fehler (Wartendes als Neu gezählt, Verworfenes im Fortschritt,
 * Geschlossenes als überfällig). Die Statusregel ist **dieselbe** wie im
 * Überblick (`overviewStore.projectStatusRows`) — Wartet vor Neu vor Offen —,
 * hier nur auf die volle Vorgangsmenge **eines** Boards angewendet, geschlossene
 * eingeschlossen.
 *
 * **Kein neuer Lesepfad:** Die Daten kommen aus `board#show` und `ticket#index`,
 * beide schon in der Leak-Matrix. Diese Datei rechnet nur, sie liest nichts.
 *
 * **Nicht auf ein Board festgenagelt** (Konzept, Mehr-Board-Zukunft): Die
 * Funktionen nehmen Vorgänge, Spalten und Wartezustände entgegen — woher die
 * stammen (ein Board oder später mehrere eines Projekts), ist ihnen gleich.
 */

import type { Column } from '@/types/board'
import type { Ticket, WaitState } from '@/types/ticket'

/** Der kanonische Status eines Vorgangs. */
export type ProjectStatus = 'neu' | 'offen' | 'wartet' | 'erledigt' | 'verworfen'

/** Wie viele „zuletzt bearbeitet" / „zuletzt abgestellt" höchstens erscheinen. */
export const RECENT_LIMIT = 5

export interface ProjectSummary {
	/** Zähler je Status über die ganze Menge. */
	counts: Record<ProjectStatus, number>
	/** Überfällige je **offenem** Status — quer zu den Zählern, nur die Offenen. */
	overdue: { neu: number, offen: number, wartet: number }
	/** Offen gesamt (neu + offen + wartet). */
	offenGesamt: number
	/** Überfällig gesamt (Summe der drei offenen). */
	overdueGesamt: number
	/** Anteil erledigt an (erledigt + offen), 0..1; 0 wenn nichts vorliegt. */
	fortschritt: number
	/** Je Spalte in Board-Reihenfolge, wie viele Vorgänge darin liegen. */
	phasen: Array<{ id: number, title: string, count: number, finalOutcome: Column['finalOutcome'] }>
}

/**
 * Die Kennung der ersten Spalte — die mit der kleinsten Position (Eingang).
 *
 * `null`, wenn es keine Spalte gibt. Bei Gleichstand entscheidet die kleinere
 * Kennung, damit die Wahl nicht von der Sortierstabilität abhängt.
 *
 * @param columns Die Spalten des Boards.
 */
export function firstColumnId(columns: Column[]): number | null {
	if (columns.length === 0) {
		return null
	}
	return columns.reduce((a, b) => (b.position < a.position || (b.position === a.position && b.id < a.id) ? b : a)).id
}

/**
 * Der Status eines einzelnen Vorgangs — dieselbe Präzedenz wie im Überblick.
 *
 * Geschlossen schlägt alles: `discarded` → verworfen, sonst erledigt (auch ein
 * ohne Ergebnis geschlossener Altvorgang, wie in `countClosedByBoard`). Offen:
 * **wartet vor neu** — ein wartender Vorgang in der Eingangsspalte zählt als
 * wartend, der Ball liegt schon beim Kunden.
 *
 * @param ticket Der Vorgang.
 * @param firstCol Kennung der Eingangsspalte, oder null.
 * @param waiting Die Wartezustände (nur wartende Vorgänge stehen drin).
 */
export function ticketStatus(ticket: Ticket, firstCol: number | null, waiting: Record<number, WaitState>): ProjectStatus {
	if (ticket.closedAt !== null) {
		return ticket.closedOutcome === 'discarded' ? 'verworfen' : 'erledigt'
	}
	if (waiting[ticket.id] !== undefined) {
		return 'wartet'
	}
	if (firstCol !== null && ticket.columnId === firstCol) {
		return 'neu'
	}
	return 'offen'
}

/**
 * Ob ein Vorgang überfällig ist: **offen** und die echte Frist ist verstrichen.
 *
 * Ein geschlossener Vorgang ist nie überfällig — er ist vom Tisch. Verglichen
 * auf den festgehaltenen Tag, damit es über Mitternacht nicht springt.
 *
 * @param ticket Der Vorgang.
 * @param today Der heutige Tag als `JJJJ-MM-TT`.
 */
export function isOverdue(ticket: Ticket, today: string): boolean {
	return ticket.closedAt === null && ticket.dueDate !== null && ticket.dueDate < today
}

/**
 * Alles, was die Kacheln, der Fortschritt und der Phasen-Balken zeigen.
 *
 * @param tickets Die sichtbaren Vorgänge (offen **und** geschlossen).
 * @param columns Die Spalten des Boards, in Board-Reihenfolge.
 * @param waiting Die Wartezustände.
 * @param today Der heutige Tag als `JJJJ-MM-TT`.
 */
export function projectSummary(tickets: Ticket[], columns: Column[], waiting: Record<number, WaitState>, today: string): ProjectSummary {
	const firstCol = firstColumnId(columns)
	const counts: Record<ProjectStatus, number> = { neu: 0, offen: 0, wartet: 0, erledigt: 0, verworfen: 0 }
	const overdue = { neu: 0, offen: 0, wartet: 0 }
	const proSpalte = new Map<number, number>()

	for (const ticket of tickets) {
		const status = ticketStatus(ticket, firstCol, waiting)
		counts[status] += 1

		if ((status === 'neu' || status === 'offen' || status === 'wartet') && isOverdue(ticket, today)) {
			overdue[status] += 1
		}

		proSpalte.set(ticket.columnId, (proSpalte.get(ticket.columnId) ?? 0) + 1)
	}

	const offenGesamt = counts.neu + counts.offen + counts.wartet
	const nenner = counts.erledigt + offenGesamt

	return {
		counts,
		overdue,
		offenGesamt,
		overdueGesamt: overdue.neu + overdue.offen + overdue.wartet,
		fortschritt: nenner > 0 ? counts.erledigt / nenner : 0,
		// In Board-Reihenfolge: Die Spalten tragen die Ordnung, nicht die
		// Vorgänge. Eine Spalte ohne Vorgang steht mit 0 da — der Balken lässt
		// sie später weg, aber die Zahl ist ehrlich.
		phasen: columns.map((column) => ({
			id: column.id,
			title: column.title,
			count: proSpalte.get(column.id) ?? 0,
			finalOutcome: column.finalOutcome,
		})),
	}
}

/**
 * Die offenen Vorgänge, für die Tabelle sortiert: überfällige zuerst, dann nach
 * Frist (früheste zuerst, fristlose zuletzt), dann zuletzt bewegte oben.
 *
 * @param tickets Die sichtbaren Vorgänge.
 * @param today Der heutige Tag als `JJJJ-MM-TT`.
 */
export function openTickets(tickets: Ticket[], today: string): Ticket[] {
	return tickets
		.filter((ticket) => ticket.closedAt === null)
		.sort((a, b) => {
			const oa = isOverdue(a, today) ? 0 : 1
			const ob = isOverdue(b, today) ? 0 : 1
			if (oa !== ob) {
				return oa - ob
			}
			// Frist: früheste zuerst, `null` ans Ende.
			if (a.dueDate !== b.dueDate) {
				if (a.dueDate === null) {
					return 1
				}
				if (b.dueDate === null) {
					return -1
				}
				return a.dueDate < b.dueDate ? -1 : 1
			}
			return (b.updatedAt ?? '').localeCompare(a.updatedAt ?? '')
		})
}

/**
 * Die zuletzt bearbeiteten Vorgänge (offen und geschlossen), jüngste zuerst,
 * höchstens `RECENT_LIMIT`.
 *
 * @param tickets Die sichtbaren Vorgänge.
 */
export function recentlyUpdated(tickets: Ticket[]): Ticket[] {
	return [...tickets]
		.filter((ticket) => ticket.updatedAt !== null)
		.sort((a, b) => (b.updatedAt ?? '').localeCompare(a.updatedAt ?? ''))
		.slice(0, RECENT_LIMIT)
}

/**
 * Die zuletzt **erledigten** Vorgänge (nicht verworfen), nach Schließdatum
 * absteigend, höchstens `RECENT_LIMIT`. Zeigt, was zuletzt vom Tisch kam.
 *
 * @param tickets Die sichtbaren Vorgänge.
 */
export function recentlyDone(tickets: Ticket[]): Ticket[] {
	return tickets
		.filter((ticket) => ticket.closedAt !== null && ticket.closedOutcome !== 'discarded')
		.sort((a, b) => (b.closedAt ?? '').localeCompare(a.closedAt ?? ''))
		.slice(0, RECENT_LIMIT)
}
