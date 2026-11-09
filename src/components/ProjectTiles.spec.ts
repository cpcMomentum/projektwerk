/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KennzahlenCard from '@/components/KennzahlenCard.vue'
import ProjectTiles from '@/components/ProjectTiles.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
	n: (_app: string, _s: string, p: string, count: number) => p.replace('%n', String(count)),
}))

const overview = {
	projectStatusRows: [] as Array<Record<string, unknown>>,
	waitingRows: [],
	nobodyRows: [],
	durchsatz: { neu: 0, neuDelta: 0, neuReihe: [], erledigt: 0, erledigtDelta: 0, erledigtReihe: [] },
}
const boards = { boards: [] as Array<Record<string, unknown>>, lastBoards: {} as Record<string, number> }
vi.mock('@/stores/overviewStore', () => ({ useOverviewStore: () => overview }))
vi.mock('@/stores/boardStore', () => ({ useBoardStore: () => boards }))
vi.mock('@/stores/taskStore', () => ({ useTaskStore: () => ({ measureRows: [] }) }))

const tileStub = {
	name: 'ProjectTile',
	props: ['boardId', 'title', 'org', 'neu', 'offen', 'wartet', 'erledigt', 'neuDieseWoche', 'zustand', 'pinned'],
	template: '<div class="tile" />',
}

function row(boardId: number, extra: Record<string, unknown> = {}) {
	return { boardId, title: `Board ${boardId}`, org: '', neu: 0, offen: 0, wartet: 0, erledigt: 0, verworfen: 0, neuDieseWoche: 0, offenGesamt: 1, fortschritt: 0, zustand: 'gruen', ...extra }
}

function board(id: number, projectId: number, extra: Record<string, unknown> = {}) {
	return { id, title: `Board ${id}`, projectId, projectTitle: `Projekt ${projectId}`, archived: false, customer: null, orgInternal: null, pinned: false, ...extra }
}

const push = vi.fn()
const mountTiles = () => mount(ProjectTiles, { global: { stubs: { ProjectTile: tileStub }, mocks: { $router: { push } } } })
const titles = (w: ReturnType<typeof mountTiles>) => w.findAllComponents(tileStub).map((t) => t.props('title'))

describe('Überblick: eine Kachel je Projekt', () => {
	beforeEach(() => {
		overview.projectStatusRows = []
		boards.boards = []
		boards.lastBoards = {}
		push.mockClear()
	})

	it('zeigt ein Projekt mit zwei Boards einmal mit Projektnamen und summierten Zahlen', () => {
		boards.boards = [board(1, 7), board(2, 7), board(3, 8)]
		overview.projectStatusRows = [row(1, { offen: 2, offenGesamt: 2 }), row(2, { wartet: 1, offenGesamt: 1, zustand: 'gelb' }), row(3)]

		const kacheln = mountTiles().findAllComponents(tileStub)

		expect(kacheln.map((t) => t.props('title'))).toEqual(['Projekt 7', 'Projekt 8'])
		expect(kacheln[0].props()).toMatchObject({ offen: 2, wartet: 1, zustand: 'gelb' })
	})

	it('lässt Projekte ohne Inhalt weg und stellt angepinnte vor dringende', () => {
		boards.boards = [board(1, 7), board(2, 8, { pinned: true }), board(3, 9)]
		overview.projectStatusRows = [row(1, { zustand: 'rot' }), row(2), row(3, { offenGesamt: 0 })]

		expect(titles(mountTiles())).toEqual(['Projekt 8', 'Projekt 7'])
	})

	it('öffnet das zuletzt genutzte Board des Projekts', () => {
		boards.boards = [board(1, 7), board(2, 7)]
		boards.lastBoards = { 7: 2 }
		overview.projectStatusRows = [row(1), row(2)]

		const kachel = mountTiles().findComponent(tileStub)
		expect(kachel.props('boardId')).toBe(2)
	})

	it('zählt in den Kennzahlen stillstehende Projekte, nicht Boards', () => {
		boards.boards = [board(1, 7), board(2, 7), board(3, 8), board(4, 8)]
		overview.projectStatusRows = [row(1, { zustand: 'grau' }), row(2, { zustand: 'gruen' }), row(3, { zustand: 'grau' }), row(4, { zustand: 'grau' })]

		const karte = shallowMount(KennzahlenCard)
		const still = (karte.vm as unknown as { hotspots: Array<{ key: string, count: number }> }).hotspots.find((h) => h.key === 'stalled')

		expect(still?.count).toBe(1)
	})
})
