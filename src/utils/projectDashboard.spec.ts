/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Aggregation fürs Projekt-Dashboard (#227).
 *
 * Geprüft wird, was still falsch werden kann: die Zuordnung eines Vorgangs zu
 * seinem Topf (Wartet vor Neu, Geschlossenes schlägt alles), die Trennung von
 * überfällig (nur offen) und die Sortierungen der drei Listen. Ein Fehler hier
 * bricht nichts — er entstellt die Zahlen, und das fällt ohne Test niemandem auf.
 */

import type { Column } from '@/types/board'
import type { Ticket, WaitState } from '@/types/ticket'

import { describe, expect, it } from 'vitest'
import { firstColumnId, isOverdue, openTickets, projectSummary, recentlyDone, recentlyUpdated, ticketStatus } from '@/utils/projectDashboard'

const TODAY = '2026-08-30'

function col(id: number, position: number, finalOutcome: Column['finalOutcome'] = null): Column {
	return { id, boardId: 1, title: 'Spalte ' + id, position, color: null, finalOutcome }
}

function tk(t: Partial<Ticket>): Ticket {
	return {
		id: 1,
		boardId: 1,
		columnId: 10,
		closedAt: null,
		closedOutcome: null,
		dueDate: null,
		responsibleUserId: null,
		updatedAt: null,
		...t,
	} as unknown as Ticket
}

const NOWAIT: Record<number, WaitState> = {}

describe('firstColumnId', () => {
	it('nimmt die Spalte mit der kleinsten Position, bei Gleichstand die kleinere Kennung', () => {
		expect(firstColumnId([col(3, 2), col(1, 0), col(2, 0)])).toBe(1)
	})
	it('ist null ohne Spalten', () => {
		expect(firstColumnId([])).toBeNull()
	})
})

describe('ticketStatus', () => {
	it('geschlossen ohne Ergebnis zählt als erledigt', () => {
		expect(ticketStatus(tk({ closedAt: '2026-08-20T10:00:00+00:00' }), 10, NOWAIT)).toBe('erledigt')
	})
	it('geschlossen mit discarded zählt als verworfen', () => {
		expect(ticketStatus(tk({ closedAt: '2026-08-20T10:00:00+00:00', closedOutcome: 'discarded' }), 10, NOWAIT)).toBe('verworfen')
	})
	it('wartet schlägt neu — ein Wartender in der Eingangsspalte ist wartend', () => {
		expect(ticketStatus(tk({ id: 5, columnId: 10 }), 10, { 5: {} as WaitState })).toBe('wartet')
	})
	it('offen in der Eingangsspalte ist neu', () => {
		expect(ticketStatus(tk({ columnId: 10 }), 10, NOWAIT)).toBe('neu')
	})
	it('offen anderswo ist offen', () => {
		expect(ticketStatus(tk({ columnId: 20 }), 10, NOWAIT)).toBe('offen')
	})
})

describe('isOverdue', () => {
	it('offen mit verstrichener Frist ist überfällig', () => {
		expect(isOverdue(tk({ dueDate: '2026-08-29' }), TODAY)).toBe(true)
	})
	it('geschlossen ist nie überfällig, auch mit alter Frist', () => {
		expect(isOverdue(tk({ dueDate: '2026-08-01', closedAt: '2026-08-20T10:00:00+00:00' }), TODAY)).toBe(false)
	})
	it('ohne Frist nie überfällig; heute fällig ist noch nicht überfällig', () => {
		expect(isOverdue(tk({ dueDate: null }), TODAY)).toBe(false)
		expect(isOverdue(tk({ dueDate: TODAY }), TODAY)).toBe(false)
	})
})

describe('projectSummary', () => {
	const columns = [col(10, 0), col(20, 1), col(30, 2, 'done')]
	const tickets = [
		tk({ id: 1, columnId: 10 }), // neu
		tk({ id: 2, columnId: 20 }), // offen
		tk({ id: 3, columnId: 20, dueDate: '2026-08-25' }), // offen + überfällig
		tk({ id: 4, columnId: 20 }), // wartet (per waiting)
		tk({ id: 5, columnId: 10, dueDate: '2026-08-01' }), // neu + überfällig
		tk({ id: 6, columnId: 30, closedAt: '2026-08-20T10:00:00+00:00' }), // erledigt
		tk({ id: 7, columnId: 30, closedAt: '2026-08-21T10:00:00+00:00', closedOutcome: 'discarded' }), // verworfen
	]
	const waiting: Record<number, WaitState> = { 4: {} as WaitState }
	const s = projectSummary(tickets, columns, waiting, TODAY)

	it('zählt jeden Status', () => {
		expect(s.counts).toEqual({ neu: 2, offen: 2, wartet: 1, erledigt: 1, verworfen: 1 })
	})
	it('zählt überfällig je offenem Status, nicht das Geschlossene', () => {
		expect(s.overdue).toEqual({ neu: 1, offen: 1, wartet: 0 })
		expect(s.overdueGesamt).toBe(2)
	})
	it('Fortschritt = erledigt / (erledigt + offen gesamt), verworfen bleibt draußen', () => {
		// offenGesamt = 2+2+1 = 5, erledigt = 1 → 1/6
		expect(s.offenGesamt).toBe(5)
		expect(s.fortschritt).toBeCloseTo(1 / 6, 5)
	})
	it('verteilt die Vorgänge je Spalte in Board-Reihenfolge', () => {
		expect(s.phasen.map((p) => [p.id, p.count])).toEqual([[10, 2], [20, 3], [30, 2]])
	})
})

describe('openTickets', () => {
	it('sortiert überfällige zuerst, dann nach Frist, fristlose zuletzt', () => {
		const list = openTickets([
			tk({ id: 1, dueDate: null }),
			tk({ id: 2, dueDate: '2026-09-10' }),
			tk({ id: 3, dueDate: '2026-08-20' }), // überfällig
			tk({ id: 4, dueDate: '2026-08-10' }), // überfällig, früher
		], TODAY)
		expect(list.map((t) => t.id)).toEqual([4, 3, 2, 1])
	})
	it('lässt geschlossene weg', () => {
		const list = openTickets([tk({ id: 1 }), tk({ id: 2, closedAt: '2026-08-20T10:00:00+00:00' })], TODAY)
		expect(list.map((t) => t.id)).toEqual([1])
	})
})

describe('recentlyUpdated / recentlyDone', () => {
	it('nimmt die jüngsten 5 nach updatedAt', () => {
		const tickets = Array.from({ length: 7 }, (_, i) => tk({ id: i + 1, updatedAt: `2026-08-${String(10 + i).padStart(2, '0')}T10:00:00+00:00` }))
		const list = recentlyUpdated(tickets)
		expect(list).toHaveLength(5)
		expect(list[0].id).toBe(7) // jüngstes zuerst
	})
	it('recentlyDone nimmt nur erledigte, nicht verworfene, nach closedAt', () => {
		const list = recentlyDone([
			tk({ id: 1, closedAt: '2026-08-20T10:00:00+00:00' }),
			tk({ id: 2, closedAt: '2026-08-28T10:00:00+00:00' }),
			tk({ id: 3, closedAt: '2026-08-29T10:00:00+00:00', closedOutcome: 'discarded' }),
			tk({ id: 4 }), // offen
		])
		expect(list.map((t) => t.id)).toEqual([2, 1])
	})
})
