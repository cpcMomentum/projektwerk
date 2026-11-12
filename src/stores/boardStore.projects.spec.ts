/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useBoardStore } from '@/stores/boardStore'

const remember = vi.hoisted(() => vi.fn())
vi.mock('@/services/lastBoard', () => ({ lastBoards: () => ({}), rememberLastBoard: remember }))
const fetchBoardSpy = vi.hoisted(() => vi.fn())
vi.mock('@/services/boards', () => ({
	fetchBoards: () => Promise.resolve([]),
	fetchBoard: (id: number) => fetchBoardSpy(id) ?? Promise.resolve({
		board: { id, projectId: 3, title: 'B', archived: false },
		members: [],
		columns: [],
		viewer: null,
		siblings: [
			{ id, title: 'B', archived: false },
			{ id: 99, title: 'Alt', archived: true },
		],
	}),
	setBoardPin: () => Promise.resolve(),
}))
const ticketsApi = vi.hoisted(() => ({ fetchTickets: vi.fn<(board: number, column?: number, archived?: boolean) => Promise<unknown>>(() => Promise.resolve({ tickets: [], counts: {}, waiting: {}, changed: {} })) }))
vi.mock('@/services/tickets', () => ticketsApi)
vi.mock('@/services/toast', () => ({ showError: () => {} }))

describe('Projekt-Sicht im Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		remember.mockClear()
	})

	it('nimmt die Board-Reiter aus board#show und lässt archivierte weg', async () => {
		const store = useBoardStore()
		await store.open(5)

		expect(store.siblings.map((b) => b.id)).toEqual([5, 99])
		expect(store.siblingBoards.map((b) => b.id)).toEqual([5])
	})

	it('zeigt je angepinntem Projekt genau einen Eintrag mit dem Projektnamen', () => {
		const store = useBoardStore()
		store.boards = [
			{ id: 1, projectId: 7, title: 'Board A', projectTitle: 'Website', pinned: true },
			{ id: 2, projectId: 7, title: 'Board B', projectTitle: 'Website', pinned: true },
			{ id: 3, projectId: 8, title: 'Solo', projectTitle: 'Solo', pinned: false },
			{ id: 4, projectId: null, title: 'Altbestand', pinned: true },
		] as never

		expect(store.pinnedProjects).toEqual([
			{ boardId: 1, projectId: 7, title: 'Website' },
			{ boardId: 4, projectId: null, title: 'Altbestand' },
		])
	})

	it('verlinkt ein angepinntes Projekt auf das zuletzt genutzte Board', () => {
		const store = useBoardStore()
		store.boards = [
			{ id: 1, projectId: 7, title: 'A', projectTitle: 'P', pinned: true },
			{ id: 2, projectId: 7, title: 'B', projectTitle: 'P', pinned: false },
		] as never
		expect(store.pinnedProjects[0].boardId).toBe(1)

		store.rememberBoard(7, 2)

		expect(store.pinnedProjects).toEqual([{ boardId: 2, projectId: 7, title: 'P' }])
		expect(remember).toHaveBeenCalledWith(7, 2)
	})

	it('behält die Reiter beim Board-Wechsel und leert sie nur, wenn das Öffnen scheitert', async () => {
		const store = useBoardStore()
		await store.open(5)
		const offen = store.open(6)
		expect(store.siblings.map((b) => b.id)).toEqual([5, 99])
		await offen
		expect(store.siblings.map((b) => b.id)).toEqual([6, 99])
	})

	it('öffnet ohne gemerktes Board das angepinnte Board, nicht das erste', () => {
		const store = useBoardStore()
		store.boards = [
			{ id: 1, projectId: 7, title: 'A', projectTitle: 'P', pinned: false, archived: false },
			{ id: 2, projectId: 7, title: 'B', projectTitle: 'P', pinned: true, archived: false },
		] as never

		expect(store.pinnedProjects).toEqual([{ boardId: 2, projectId: 7, title: 'P' }])
	})

	it('öffnet bei veraltetem Gedächtnis das angepinnte Board', () => {
		const store = useBoardStore()
		store.boards = [
			{ id: 1, projectId: 7, title: 'A', projectTitle: 'P', pinned: false, archived: false },
			{ id: 2, projectId: 7, title: 'B', projectTitle: 'P', pinned: true, archived: false },
		] as never
		store.lastBoards = { 7: 99 }

		expect(store.pinnedProjects[0].boardId).toBe(2)
	})

	it('lädt mit dem Schalter archivierte mit und setzt ihn beim Board-Wechsel zurück', async () => {
		const store = useBoardStore()
		await store.open(5)
		await store.setShowArchived(true)

		expect(ticketsApi.fetchTickets).toHaveBeenLastCalledWith(5, undefined, true)

		await store.open(6)
		expect(store.showArchived).toBe(false)
		expect(ticketsApi.fetchTickets).toHaveBeenLastCalledWith(6, undefined, false)
	})

	it('nimmt einen archivierten Vorgang ohne Schalter vom Board, mit Schalter bleibt er', () => {
		const store = useBoardStore()
		store.columnOrder = new Map([[1, [10, 11]]])
		const archiviert = { id: 10, columnId: 1, archivedAt: '2026-11-02T10:00:00+00:00' } as never

		store.replaceTicket(archiviert)
		expect(store.columnOrder.get(1)).toEqual([11])

		store.columnOrder = new Map([[1, [10, 11]]])
		store.showArchived = true
		store.replaceTicket(archiviert)
		expect(store.columnOrder.get(1)).toEqual([10, 11])
	})

	it('lädt beim Schalter nur die Vorgänge neu, nicht das ganze Board', async () => {
		const store = useBoardStore()
		await store.open(5)
		fetchBoardSpy.mockClear()

		await store.setShowArchived(true)

		expect(fetchBoardSpy).not.toHaveBeenCalled()
		expect(ticketsApi.fetchTickets).toHaveBeenLastCalledWith(5, undefined, true)
	})

	it('holt einen zurückgeholten Vorgang wieder auf das Board', () => {
		const store = useBoardStore()
		store.columnOrder = new Map([[1, [10, 11]]])
		store.replaceTicket({ id: 10, columnId: 1, archivedAt: '2026-11-02T10:00:00+00:00' } as never)
		expect(store.columnOrder.get(1)).toEqual([11])

		store.replaceTicket({ id: 10, columnId: 1, archivedAt: null } as never)

		expect(store.columnOrder.get(1)).toEqual([11, 10])
	})

	it('stellt einen archivierten Vorgang aus einem Direktlink ohne Schalter nicht aufs Board', () => {
		const store = useBoardStore()
		store.columnOrder = new Map([[1, [11]]])

		store.addTicket({ id: 10, columnId: 1, archivedAt: '2026-11-02T10:00:00+00:00' } as never)

		expect(store.columnOrder.get(1)).toEqual([11])
		expect(store.tickets.has(10)).toBe(true)
	})

	it('verwirft beim schnellen Umschalten die überholte Antwort', async () => {
		const store = useBoardStore()
		await store.open(5)
		let freigeben: (v: unknown) => void = () => {}
		ticketsApi.fetchTickets.mockImplementationOnce(() => new Promise((r) => {
			freigeben = r
		}))
		const an = store.setShowArchived(true)
		await store.setShowArchived(false)
		freigeben({ tickets: [{ id: 77, columnId: 1, closedAt: '2026-11-01T10:00:00+00:00', archivedAt: '2026-11-02T10:00:00+00:00' }], counts: {}, waiting: {}, changed: {} })
		await an

		expect(store.showArchived).toBe(false)
		expect(store.tickets.has(77)).toBe(false)
	})

	it('klappt eingeblendete archivierte Vorgänge nicht unter „ältere“ weg', () => {
		const store = useBoardStore()
		const zu = (id: number, tag: number, archiviert = false) => ({ id, columnId: 1, closedAt: `2026-10-${String(tag).padStart(2, '0')}T10:00:00+00:00`, archivedAt: archiviert ? '2026-11-01T10:00:00+00:00' : null })
		const spalte = [...Array.from({ length: 11 }, (_, i) => zu(i + 1, i + 10)), zu(99, 1, true)] as never[]

		const sichtbar = store.withoutOlderClosed(spalte).map((t: { id: number }) => t.id)

		expect(sichtbar).toContain(99)
		expect(sichtbar).not.toContain(1)
	})
})
