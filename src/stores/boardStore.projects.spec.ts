/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useBoardStore } from '@/stores/boardStore'

const remember = vi.hoisted(() => vi.fn())
vi.mock('@/services/lastBoard', () => ({ lastBoards: () => ({}), rememberLastBoard: remember }))
vi.mock('@/services/boards', () => ({
	fetchBoards: () => Promise.resolve([]),
	fetchBoard: (id: number) => Promise.resolve({
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
vi.mock('@/services/tickets', () => ({
	fetchTickets: () => Promise.resolve({ tickets: [], counts: {}, waiting: {}, changed: {} }),
}))
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
})
