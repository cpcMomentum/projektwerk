/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Das Suchfeld im Board-Kopf (#373) — der Teil, der in der Ansicht lebt.
 *
 * Die Filterregel prüft `boardStore.spec`. Hier geht es um das Feld und den
 * Store, die nie auseinanderlaufen dürfen: Ein leeres Feld über gefilterten
 * Karten ließe Vorgänge ohne sichtbaren Grund verschwinden.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import BoardView from '@/views/BoardView.vue'
import { useBoardStore } from '@/stores/boardStore'

vi.mock(import('@nextcloud/l10n'), async (importOriginal) => ({
	...(await importOriginal()),
	t: (_app: string, text: string) => text,
	n: (_app: string, _s: string, p: string) => p,
}))
vi.mock('@/services/boards', () => ({
	fetchBoards: () => Promise.resolve([]),
	fetchBoard: (id: number) => Promise.resolve({ board: { id }, members: [], columns: [], viewer: null }),
}))
vi.mock('@/services/tickets', () => ({
	fetchTickets: () => Promise.resolve({ tickets: [], counts: {}, waiting: {}, changed: {} }),
	fetchTicket: () => Promise.reject(new Error('nicht gebraucht')),
	moveTicket: () => Promise.resolve(),
	markTicketRead: () => Promise.resolve(),
}))
vi.mock('@/services/toast', () => ({ showError: () => {}, showUndo: () => {} }))
vi.mock('@/components/TicketDetail.vue', () => ({ default: { name: 'TicketDetail', template: '<div />' } }))
vi.mock('@/components/CreateTicketDialog.vue', () => ({ default: { name: 'CreateTicketDialog', template: '<div />' } }))
vi.mock('@/components/board/BoardDragLayer.vue', () => ({ default: { name: 'BoardDragLayer', template: '<div />' } }))

const route = reactive({ params: { boardId: '5' }, query: {} as Record<string, string> })

async function mountView() {
	const wrapper = shallowMount(BoardView, {
		global: { mocks: { $route: route, $router: { push: vi.fn() } } },
	})
	await flushPromises()
	return wrapper
}

const field = (wrapper: Awaited<ReturnType<typeof mountView>>) => wrapper.findComponent({ name: 'NcTextField' })

describe('Suchfeld im Board (#373)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		route.params.boardId = '5'
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('gibt den Suchtext entprellt an den Store', async () => {
		const wrapper = await mountView()
		const store = useBoardStore()

		field(wrapper).vm.$emit('update:modelValue', 'l')
		vi.advanceTimersByTime(150)
		field(wrapper).vm.$emit('update:modelValue', 'logo')
		vi.advanceTimersByTime(199)
		expect(store.search).toBe('')

		vi.advanceTimersByTime(1)
		expect(store.search).toBe('logo')
	})

	it('leert Feld und Store sofort mit Esc', async () => {
		const wrapper = await mountView()
		const store = useBoardStore()
		field(wrapper).vm.$emit('update:modelValue', 'logo')
		vi.advanceTimersByTime(200)

		await field(wrapper).trigger('keydown', { key: 'Escape' })

		expect(store.search).toBe('')
		expect((wrapper.vm as unknown as { searchInput: string }).searchInput).toBe('')
	})

	it('lässt einen schwebenden Suchtext nach dem Board-Wechsel nicht nachlaufen', async () => {
		const wrapper = await mountView()
		const store = useBoardStore()

		field(wrapper).vm.$emit('update:modelValue', 'logo')
		route.params.boardId = '6'
		await flushPromises()
		vi.advanceTimersByTime(500)

		expect(store.search).toBe('')
		wrapper.unmount()
	})

	it('startet nach dem Zurückkommen auf dasselbe Board ohne alte Suche', async () => {
		// Board 5 offen, „logo" gesucht, dann in die Einstellungen: Die Ansicht
		// wird abgebaut, der Store behält die Suche, `open(5)` leert sie nicht.
		const first = await mountView()
		const store = useBoardStore()
		field(first).vm.$emit('update:modelValue', 'logo')
		vi.advanceTimersByTime(200)
		first.unmount()
		expect(store.search).toBe('logo')

		const again = await mountView()

		expect(store.search).toBe('')
		expect((again.vm as unknown as { searchInput: string }).searchInput).toBe('')
	})

	it('räumt den Timer beim Verlassen der Ansicht weg', async () => {
		const wrapper = await mountView()
		const store = useBoardStore()

		field(wrapper).vm.$emit('update:modelValue', 'logo')
		wrapper.unmount()
		vi.advanceTimersByTime(500)

		expect(store.search).toBe('')
	})
})

describe('Board löschen', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		route.params.boardId = '5'
	})

	async function mountWith(boards: number, deletable: boolean, isManager = true) {
		const push = vi.fn()
		const wrapper = shallowMount(BoardView, {
			global: {
				mocks: { $route: route, $router: { push } },
				stubs: {
					NcActions: { template: '<div><slot /></div>' },
					NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				},
			},
		})
		await flushPromises()
		const store = useBoardStore()
		store.board = { id: 5, projectId: 1, title: 'Versehen' } as never
		store.boards = Array.from({ length: boards }, (_, i) => ({ id: 5 + i, projectId: 1, title: 'B' + i })) as never
		store.viewer = { isManager, isBoardCreator: false } as never
		store.deletable = deletable
		await flushPromises()
		return { wrapper, store, push }
	}

	const deleteItem = (wrapper: Awaited<ReturnType<typeof mountWith>>['wrapper']) => wrapper.findComponent({ name: 'NcActionButton' })

	it('zeigt kein Menü bei nur einem Board', async () => {
		const { wrapper } = await mountWith(1, true)
		expect(deleteItem(wrapper).exists()).toBe(false)
	})

	it('zeigt kein Menü ohne Einrichte-Recht', async () => {
		const { wrapper } = await mountWith(2, true, false)
		expect(deleteItem(wrapper).exists()).toBe(false)
	})

	it('graut den Eintrag aus, solange das Board nicht löschbar ist', async () => {
		const { wrapper } = await mountWith(2, false)
		expect(deleteItem(wrapper).props('disabled')).toBe(true)
	})

	it('löscht nach der Rückfrage und wechselt auf das verbleibende Board', async () => {
		const { wrapper, store, push } = await mountWith(2, true)
		expect(deleteItem(wrapper).props('disabled')).toBe(false)
		const spy = vi.spyOn(store, 'deleteCurrentBoard').mockResolvedValue(6)

		deleteItem(wrapper).vm.$emit('click')
		await flushPromises()
		const confirm = wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.props('variant') === 'error')
		expect(confirm).toBeDefined()
		confirm!.vm.$emit('click')
		await flushPromises()

		expect(spy).toHaveBeenCalledOnce()
		expect(push).toHaveBeenCalledWith({ name: 'board', params: { boardId: '6' } })
	})

	it('schließt die Rückfrage bei Ablehnung und lädt das Board neu', async () => {
		const { wrapper, store, push } = await mountWith(2, true)
		vi.spyOn(store, 'deleteCurrentBoard').mockRejectedValue(new Error('Dieses Board kann nicht gelöscht werden.'))
		const open = vi.spyOn(store, 'open').mockResolvedValue()

		deleteItem(wrapper).vm.$emit('click')
		await flushPromises()
		wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.props('variant') === 'error')!.vm.$emit('click')
		await flushPromises()

		expect(open).toHaveBeenCalledWith(5)
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.findAllComponents({ name: 'NcButton' }).some((b) => b.props('variant') === 'error')).toBe(false)
	})
})
