/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import BoardSettingsView from '@/views/BoardSettingsView.vue'

const state = vi.hoisted(() => ({
	boards: [] as Array<{ id: number, projectId: number, title: string, archived: boolean }>,
	viewer: { userId: 'anna', role: 'internal', isManager: true, isBoardCreator: false },
	archivedBoard: false,
	projectArchived: false,
	boardsFail: false,
}))

const api = vi.hoisted(() => ({
	updateBoard: vi.fn<(boardId: number, changes: object) => Promise<object>>(() => Promise.resolve({})),
	setBoardArchived: vi.fn(() => Promise.resolve({})),
	setProjectArchived: vi.fn(() => Promise.resolve({ archived: true })),
}))

vi.mock(import('@nextcloud/l10n'), async (importOriginal) => ({
	...(await importOriginal()),
	t: (_app: string, text: string, vars?: unknown) => text.replace(/\{(\w+)\}/g, (_m, k: string) => String((vars as Record<string, unknown> | undefined)?.[k] ?? '')),
	n: (_app: string, _s: string, p: string) => p,
}))
vi.mock('@/services/boards', () => ({
	fetchBoards: () => (state.boardsFail ? Promise.reject(new Error('Netz')) : Promise.resolve(state.boards)),
	fetchBoard: (id: number) => Promise.resolve({
		board: { id, projectId: 1, title: `Board ${id}`, archived: state.archivedBoard, customer: 'Kunde AG', githubEnabled: false },
		members: [],
		columns: [],
		viewer: state.viewer,
		memberBoardsAllowed: false,
		projectTitle: 'Website',
		projectDescription: null,
		projectArchived: state.projectArchived,
	}),
	setBoardPin: () => Promise.resolve(),
}))
vi.mock('@/services/tickets', () => ({
	fetchTickets: () => Promise.resolve({ tickets: [], counts: {}, waiting: {}, changed: {} }),
}))
vi.mock('@/services/settings', () => ({
	...api,
	addMember: vi.fn(),
	createColumn: vi.fn(),
	deleteColumn: vi.fn(),
	memberRemovalImpact: vi.fn(),
	removeMember: vi.fn(),
	renameColumn: vi.fn(),
	reorderColumns: vi.fn(),
	searchCandidates: vi.fn(),
	setColumnFinalOutcome: vi.fn(),
	updateMember: vi.fn(),
}))
vi.mock('@/services/toast', () => ({ showError: () => {}, showUndo: () => {} }))
vi.mock('@/components/FolderPicker.vue', () => ({ default: { name: 'FolderPicker', template: '<div />' } }))

const route = reactive({ name: 'board-settings', params: { boardId: '5' }, query: {} as Record<string, string> })
const router = { push: vi.fn(), replace: vi.fn() }

async function mountView() {
	const wrapper = shallowMount(BoardSettingsView, {
		global: { mocks: { $route: route, $router: router }, stubs: { RouterLink: { template: '<a><slot /></a>' } } },
	})
	await flushPromises()
	return wrapper
}

type Groups = Array<{ key: string, label: string, items: Array<{ key: string, label: string }> }>
const groupsOf = (wrapper: Awaited<ReturnType<typeof mountView>>): Groups => wrapper.findComponent({ name: 'PwSettingsNav' }).props('groups') as Groups
const itemKeys = (wrapper: Awaited<ReturnType<typeof mountView>>) => Object.fromEntries(groupsOf(wrapper).map((g) => [g.key, g.items.map((i) => i.key)]))
const vm = (wrapper: Awaited<ReturnType<typeof mountView>>) => wrapper.vm as unknown as { activeSection: string, archiveDialog: string | null, confirmArchive: () => Promise<void>, saveProject: () => Promise<void> }

describe('Einstellungen mit Gruppen Projekt und Board', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		route.params.boardId = '5'
		route.query = {}
		state.viewer = { userId: 'anna', role: 'internal', isManager: true, isBoardCreator: false }
		state.boards = [{ id: 5, projectId: 1, title: 'Board 5', archived: false }]
		state.archivedBoard = false
		state.projectArchived = false
		state.boardsFail = false
	})

	it('zeigt bei einem Board keine Auswahl, keinen Boardnamen und kein Board-Archiv', async () => {
		const wrapper = await mountView()

		expect(itemKeys(wrapper)).toEqual({
			projekt: ['projekt', 'mitglieder', 'dateiablage', 'projekt-archiv'],
			board: ['spalten', 'github'],
		})
		expect(wrapper.find('#pw-set-boardpick').exists()).toBe(false)
		expect(wrapper.find('#pw-set-title').exists()).toBe(false)
	})

	it('bietet ab zwei Boards Auswahl, Boardnamen und Board-Archiv, archivierte unter „Archiviert“', async () => {
		state.boards.push(
			{ id: 6, projectId: 1, title: 'Board 6', archived: false },
			{ id: 7, projectId: 1, title: 'Alt', archived: true },
			{ id: 9, projectId: 2, title: 'Fremdes Projekt', archived: false },
		)
		const wrapper = await mountView()

		expect(itemKeys(wrapper).board).toEqual(['board', 'spalten', 'github', 'board-archiv'])
		const vmAny = wrapper.vm as unknown as { activeProjectBoards: Array<{ id: number }>, archivedProjectBoards: Array<{ id: number }> }
		expect(vmAny.activeProjectBoards.map((b) => b.id)).toEqual([5, 6])
		expect(vmAny.archivedProjectBoards.map((b) => b.id)).toEqual([7])
	})

	it('zeigt dem Board-Ersteller nur die Board-Gruppe ohne Auswahl und ohne GitHub', async () => {
		state.viewer = { userId: 'carla', role: 'external', isManager: false, isBoardCreator: true }
		state.boards.push({ id: 6, projectId: 1, title: 'Board 6', archived: false })
		const wrapper = await mountView()

		expect(itemKeys(wrapper)).toEqual({ board: ['board', 'spalten', 'board-archiv'] })
		expect((wrapper.vm as unknown as { showBoardPicker: boolean }).showBoardPicker).toBe(false)
		expect(vm(wrapper).activeSection).toBe('board')
	})

	it('öffnet den Bereich aus der Adresse und fällt bei fehlendem Recht auf den ersten zurück', async () => {
		route.query = { bereich: 'spalten' }
		expect(vm(await mountView()).activeSection).toBe('spalten')

		route.query = { bereich: 'mitglieder' }
		state.viewer = { userId: 'carla', role: 'external', isManager: false, isBoardCreator: true }
		state.boards.push({ id: 6, projectId: 1, title: 'Board 6', archived: false })
		expect(vm(await mountView()).activeSection).toBe('board')
	})

	it('speichert Projektname und Beschreibung als Projektfelder', async () => {
		const wrapper = await mountView()
		await wrapper.find('#pw-set-ptitle').setValue('  Neue Website ')

		await vm(wrapper).saveProject()

		expect(api.updateBoard).toHaveBeenCalledWith(5, expect.objectContaining({ projectTitle: 'Neue Website', projectDescription: null }))
		expect(api.updateBoard.mock.calls[0][1]).not.toHaveProperty('title')
	})

	it('archiviert das Projekt erst nach der Rückfrage mit Namen', async () => {
		const wrapper = await mountView()
		vm(wrapper).archiveDialog = 'project'
		await flushPromises()

		expect((wrapper.vm as unknown as { archiveText: string }).archiveText).toContain('Das Projekt „Website“ wird mit allen Boards archiviert.')
		expect(api.setProjectArchived).not.toHaveBeenCalled()

		await vm(wrapper).confirmArchive()

		expect(api.setProjectArchived).toHaveBeenCalledWith(5, true)
		expect(api.setBoardArchived).not.toHaveBeenCalled()
		expect(vm(wrapper).archiveDialog).toBeNull()
	})

	it('archiviert beim Board nur das Board und nennt seinen Namen', async () => {
		state.boards.push({ id: 6, projectId: 1, title: 'Board 6', archived: false })
		const wrapper = await mountView()
		vm(wrapper).archiveDialog = 'board'
		await flushPromises()

		expect((wrapper.vm as unknown as { archiveText: string }).archiveText).toContain('Nur das Board „Board 5“ wird archiviert.')
		await vm(wrapper).confirmArchive()

		expect(api.setBoardArchived).toHaveBeenCalledWith(5, true)
		expect(api.setProjectArchived).not.toHaveBeenCalled()
	})

	it('lässt ein archiviertes Einzelboard aus dem Altbestand zurückholen', async () => {
		state.archivedBoard = true
		const wrapper = await mountView()

		expect(itemKeys(wrapper).board).toContain('board-archiv')
	})

	it('folgt einem geänderten Bereich in der Adresse, ohne neu zu laden', async () => {
		const wrapper = await mountView()
		route.query = { bereich: 'projekt-archiv' }
		await flushPromises()

		expect(vm(wrapper).activeSection).toBe('projekt-archiv')
	})

	it('führt archivierte Boards hinten mit Zusatz und wechselt über die Auswahl', async () => {
		state.boards.push({ id: 7, projectId: 1, title: 'Alt', archived: true }, { id: 6, projectId: 1, title: 'Board 6', archived: false })
		const wrapper = await mountView()
		const ansicht = wrapper.vm as unknown as {
			boardOptions: unknown[]
			selectedBoardOption: unknown
			switchSettingsBoard: (o: { id: number } | null) => void
		}

		expect(ansicht.boardOptions).toEqual([
			{ id: 5, label: 'Board 5' },
			{ id: 6, label: 'Board 6' },
			{ id: 7, label: 'Alt (archiviert)' },
		])
		expect(ansicht.selectedBoardOption).toEqual({ id: 5, label: 'Board 5' })

		ansicht.switchSettingsBoard({ id: 5 })
		ansicht.switchSettingsBoard(null)
		expect(router.replace).not.toHaveBeenCalledWith(expect.objectContaining({ name: 'board-settings' }))

		ansicht.switchSettingsBoard({ id: 7 })
		expect(router.replace).toHaveBeenCalledWith(expect.objectContaining({ name: 'board-settings', params: { boardId: '7' } }))
	})

	it('behält Menü und Formular beim Board-Wechsel, statt sie neu aufzubauen', async () => {
		state.boards.push({ id: 6, projectId: 1, title: 'Board 6', archived: false })
		const wrapper = await mountView()
		const nav = wrapper.findComponent({ name: 'PwSettingsNav' })

		route.params.boardId = '6'
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent({ name: 'PwSettingsNav' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'PwSettingsNav' }).vm).toBe(nav.vm)
	})

	it('hält ein Projekt mit mehreren Boards nicht für ein Ein-Board-Projekt, wenn die Liste nicht lädt', async () => {
		state.boardsFail = true
		const store = (await import('@/stores/boardStore')).useBoardStore()
		store.boards = [
			{ id: 5, projectId: 1, title: 'Board 5', archived: false },
			{ id: 6, projectId: 1, title: 'Board 6', archived: false },
		] as never
		const wrapper = await mountView()

		expect(itemKeys(wrapper).board).toContain('board-archiv')
	})

	it('behält beim Speichern eines Bereichs die ungespeicherten Eingaben der anderen', async () => {
		const wrapper = await mountView()
		const ansicht = wrapper.vm as unknown as { board: Record<string, unknown>, saveGithub: () => Promise<void> }
		ansicht.board.projectTitle = 'Noch nicht gespeichert'
		ansicht.board.githubRepo = 'cpc/neu'

		await ansicht.saveGithub()

		expect(api.updateBoard).toHaveBeenCalledWith(5, { githubEnabled: false, githubRepo: 'cpc/neu' })
		expect(ansicht.board.projectTitle).toBe('Noch nicht gespeichert')
	})

	it('führt aus der Übersicht kommend dorthin zurück', async () => {
		route.query = { bereich: 'projekt', zurueck: 'uebersicht' }
		const wrapper = await mountView()
		const ansicht = wrapper.vm as unknown as { ausUebersicht: boolean, crumbLabel: string }

		expect(ansicht.ausUebersicht).toBe(true)
		expect(ansicht.crumbLabel).toBe('Übersicht')
	})

	it('behält bei mehreren Boards den ungespeicherten Boardnamen, wenn das Projekt gespeichert wird', async () => {
		state.boards.push({ id: 6, projectId: 1, title: 'Board 6', archived: false })
		const wrapper = await mountView()
		const ansicht = wrapper.vm as unknown as { board: Record<string, unknown>, saveProject: () => Promise<void> }
		ansicht.board.title = 'Neuer Boardname'

		await ansicht.saveProject()

		expect(ansicht.board.title).toBe('Neuer Boardname')
	})
})
