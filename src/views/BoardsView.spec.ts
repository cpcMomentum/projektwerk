/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Das Projekt-Verzeichnis (#276).
 *
 * Geprüft wird der Kern der Ansicht: der **Join** zweier Quellen. Welche
 * Projekte es gibt, weiß der `boardStore` (die Board-Liste, inkl. leerer); die
 * Statuszahlen liefert der `overviewStore`. Ein Projekt ohne Statuszeile
 * erscheint mit Nullwerten, angepinnte stehen vorn, sonst alphabetisch. Dazu die
 * beiden Wege hinaus: Kachel → Dashboard, Anlegen → Board.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BoardsView from '@/views/BoardsView.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({
	default: { name: 'NcEmptyContent', template: '<div class="empty"><slot name="action" /></div>' },
}))
vi.mock('@/components/CreateBoardWizard.vue', () => ({
	default: { name: 'CreateBoardWizard', template: '<div class="wizard" />' },
}))
// Der Initial-State „darf anlegen" (#280) — pro Test steuerbar.
let canCreate = true
vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => canCreate,
}))

// Die geteilte Kachel als schlanker Stub: hält ihre Props für die Assertions
// fest, ohne die Darstellung mitzuprüfen (das tut ProjectTile.spec).
const tileStub = {
	name: 'ProjectTile',
	props: {
		boardId: {},
		title: {},
		org: {},
		neu: {},
		offen: {},
		wartet: {},
		erledigt: {},
		neuDieseWoche: {},
		zustand: {},
		pinned: { type: Boolean },
		pinnable: { type: Boolean },
	},
	template: '<div class="tile" :data-title="title" :data-empty="String(neu + offen + wartet + erledigt === 0)" />',
}

const boardStore = {
	boards: [] as Array<Record<string, unknown>>,
	loading: false,
	loadBoards: vi.fn(),
	togglePin: vi.fn(),
	orgLine: (b: { orgInternal?: string | null, customer?: string | null }) => [b.orgInternal, b.customer].filter(Boolean).join(' · '),
	customerSuggestions: [] as string[],
}
const overviewStore = {
	projectStatusRows: [] as Array<Record<string, unknown>>,
	load: vi.fn(),
}

vi.mock('@/stores/boardStore', () => ({ useBoardStore: () => boardStore }))
vi.mock('@/stores/overviewStore', () => ({ useOverviewStore: () => overviewStore }))

function board(id: number, title: string, extra: Record<string, unknown> = {}) {
	return { id, title, pinned: false, orgInternal: null, customer: null, ...extra }
}

function statusRow(boardId: number, extra: Record<string, unknown> = {}) {
	return { boardId, neu: 0, offen: 0, wartet: 0, erledigt: 0, neuDieseWoche: 0, zustand: 'gruen', ...extra }
}

const push = vi.fn()

function mountView() {
	return mount(BoardsView, {
		global: {
			stubs: { ProjectTile: tileStub },
			mocks: { $router: { push } },
		},
	})
}

beforeEach(() => {
	boardStore.boards = []
	boardStore.loadBoards.mockClear()
	boardStore.togglePin.mockClear()
	overviewStore.projectStatusRows = []
	overviewStore.load.mockClear()
	push.mockClear()
	canCreate = true
})

describe('BoardsView', () => {
	it('lädt beim Öffnen beide Quellen', () => {
		mountView()
		expect(boardStore.loadBoards).toHaveBeenCalled()
		expect(overviewStore.load).toHaveBeenCalled()
	})

	it('zeigt „Neues Projekt", wenn die Person anlegen darf', () => {
		canCreate = true
		boardStore.boards = [board(1, 'Alpha')]

		expect(mountView().text()).toContain('Neues Projekt')
	})

	it('blendet „Neues Projekt" für Gäste aus (#280)', () => {
		canCreate = false
		boardStore.boards = [board(1, 'Alpha')]

		expect(mountView().text()).not.toContain('Neues Projekt')
	})

	it('joint die Statuszahlen per boardId an die Board-Liste', () => {
		boardStore.boards = [board(3, 'Alpha', { orgInternal: 'cpc', customer: 'Kunde' })]
		overviewStore.projectStatusRows = [statusRow(3, { neu: 2, offen: 5, wartet: 1, erledigt: 8, zustand: 'rot' })]

		const w = mountView()
		const tile = w.findComponent(tileStub)

		expect(tile.props()).toMatchObject({
			boardId: 3,
			title: 'Alpha',
			// Ist ein Kunde gepflegt, trägt die Kachel ihn allein — die
			// Firmenzeile ist nur der Rückfall (eigener Test unten).
			org: 'Kunde',
			neu: 2,
			offen: 5,
			wartet: 1,
			erledigt: 8,
			zustand: 'rot',
		})
	})

	it('zeigt ein Projekt ohne Statuszeile als leere Kachel (alle Zahlen 0)', () => {
		boardStore.boards = [board(9, 'Frisch')]
		overviewStore.projectStatusRows = [] // keine Zeile für 9

		const w = mountView()
		const tile = w.findComponent(tileStub)

		expect(tile.props()).toMatchObject({ boardId: 9, neu: 0, offen: 0, wartet: 0, erledigt: 0, zustand: 'gruen' })
		expect(tile.attributes('data-empty')).toBe('true')
	})

	it('sortiert angepinnte nach vorn, den Rest alphabetisch', () => {
		boardStore.boards = [
			board(1, 'Zebra'),
			board(2, 'Apfel', { pinned: true }),
			board(3, 'Birne'),
		]

		const w = mountView()
		const titel = w.findAllComponents(tileStub).map((t) => t.props('title'))

		expect(titel).toEqual(['Apfel', 'Birne', 'Zebra'])
	})

	it('gibt jede Kachel als anpinnbar aus und reicht den Pin-Toggle an den Store', async () => {
		boardStore.boards = [board(4, 'Gamma', { pinned: true })]

		const w = mountView()
		const tile = w.findComponent(tileStub)
		expect(tile.props('pinnable')).toBe(true)
		expect(tile.props('pinned')).toBe(true)

		tile.vm.$emit('togglePin', 4)
		await w.vm.$nextTick()
		expect(boardStore.togglePin).toHaveBeenCalledWith(4)
	})

	it('zeigt den Kunden als Kachel-Zeile, mit Rückfall auf die Firmenzeile (#309)', () => {
		boardStore.boards = [
			board(1, 'MitKunde', { customer: 'MI', orgInternal: 'cpc' }),
			board(2, 'OhneKunde', { orgInternal: 'cpc' }),
		]

		const w = mountView()
		const orgs = w.findAllComponents(tileStub).map((t) => t.props('org'))

		expect(orgs).toContain('MI')
		expect(orgs).toContain('cpc')
	})

	it('filtert die Kacheln nach ausgewähltem Kunden (#309)', async () => {
		boardStore.boards = [
			board(1, 'Alpha', { customer: 'MI' }),
			board(2, 'Beta', { customer: 'BMW' }),
		]

		const w = mountView()
		expect(w.findAllComponents(tileStub)).toHaveLength(2)

		;(w.vm as unknown as { customerFilter: string }).customerFilter = 'MI'
		await w.vm.$nextTick()

		const tiles = w.findAllComponents(tileStub)
		expect(tiles).toHaveLength(1)
		expect(tiles[0].props('title')).toBe('Alpha')
	})

	it('führt beim Kachel-Klick ins Projekt-Dashboard', () => {
		const w = mountView()
		;(w.vm as unknown as { openDashboard: (id: number) => void }).openDashboard(5)

		expect(push).toHaveBeenCalledWith({ name: 'project-dashboard', params: { boardId: '5' } })
	})

	it('führt nach dem Anlegen direkt ins Board', () => {
		const w = mountView()
		;(w.vm as unknown as { openCreated: (id: number) => void }).openCreated(6)

		expect(push).toHaveBeenCalledWith({ name: 'board', params: { boardId: '6' } })
	})
})
