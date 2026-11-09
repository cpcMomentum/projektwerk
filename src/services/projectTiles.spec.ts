/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { TileBoard } from '@/services/projectTiles'
import type { ProjectStatusRow } from '@/types/overview'

import { describe, expect, it } from 'vitest'
import { groupByProject, overviewProjectTiles, projectTiles, projectZustand, targetBoard } from '@/services/projectTiles'

function board(id: number, projectId: number | null, extra: Partial<TileBoard> = {}): TileBoard {
	return { id, title: `Board ${id}`, projectId, archived: false, customer: null, orgInternal: null, ...extra }
}

function row(boardId: number, extra: Partial<ProjectStatusRow> = {}): ProjectStatusRow {
	return { boardId, title: `Board ${boardId}`, org: '', neu: 0, offen: 0, wartet: 0, erledigt: 0, verworfen: 0, neuDieseWoche: 0, offenGesamt: 0, fortschritt: 0, zustand: 'gruen', ...extra }
}

describe('Projektkacheln', () => {
	it('gruppiert nach Projekt in der Reihenfolge des ersten Auftretens, Boards ohne Projekt einzeln', () => {
		const gruppen = groupByProject([board(1, 7), board(2, 8), board(3, 7), board(4, null), board(5, null)])

		expect(gruppen.map((g) => g.map((b) => b.id))).toEqual([[1, 3], [2], [4], [5]])
	})

	it('wählt das zuletzt genutzte aktive Board, sonst das erste aktive, sonst das erste', () => {
		const gruppe = [board(1, 7, { archived: true }), board(2, 7), board(3, 7)]

		expect(targetBoard(gruppe, { 7: 3 }).id).toBe(3)
		expect(targetBoard(gruppe, { 7: 1 }).id).toBe(2)
		expect(targetBoard(gruppe, {}).id).toBe(2)
		expect(targetBoard([board(1, 7, { archived: true })], {}).id).toBe(1)
	})

	it('färbt rot oder gelb durch, still erst ohne jede Bewegung', () => {
		expect(projectZustand(['gruen', 'rot', 'gelb'])).toBe('rot')
		expect(projectZustand(['grau', 'gelb'])).toBe('gelb')
		expect(projectZustand(['grau', 'gruen'])).toBe('gruen')
		expect(projectZustand(['grau', 'grau'])).toBe('grau')
		expect(projectZustand([])).toBe('gruen')
	})

	it('summiert die Zahlen, nimmt den Projektnamen und den Stern eines beliebigen Boards', () => {
		const [kachel] = projectTiles(
			[board(1, 7, { projectTitle: 'Website', customer: 'Kunde AG' }), board(2, 7, { pinned: true })],
			[row(1, { neu: 1, offenGesamt: 1, verworfen: 2 }), row(2, { offen: 3, wartet: 1, erledigt: 4, offenGesamt: 4, neuDieseWoche: 2, zustand: 'gelb' })],
			{},
			(b) => b.pinned === true,
		)

		expect(kachel).toMatchObject({
			key: 'p7',
			projectId: 7,
			boardId: 2,
			boardIds: [1, 2],
			title: 'Website',
			org: 'Kunde AG',
			neu: 1,
			offen: 3,
			wartet: 1,
			erledigt: 4,
			verworfen: 2,
			offenGesamt: 5,
			neuDieseWoche: 2,
			zustand: 'gelb',
			pinned: true,
			archived: false,
		})
	})

	it('zeigt ein archiviertes Projekt grau, auch wenn ein Board noch Bewegung hätte', () => {
		const [kachel] = projectTiles([board(1, 7, { projectArchived: true })], [row(1, { zustand: 'rot' })], {}, () => false)

		expect(kachel).toMatchObject({ archived: true, zustand: 'grau' })
	})

	it('nimmt im Überblick nur Boards mit Statuszeile und behilft sich ohne Boardliste mit der Zeile', () => {
		const kacheln = overviewProjectTiles(
			[row(1), row(2), row(9, { title: 'Unbekannt', org: 'cpc · Kunde' })],
			[board(1, 7, { projectTitle: 'P' }), board(2, 7, { projectTitle: 'P' }), board(3, 8, { projectTitle: 'Ohne Zeile' })],
			{},
		)

		expect(kacheln.map((k) => [k.title, k.boardIds])).toEqual([['P', [1, 2]], ['Unbekannt', [9]]])
		expect(kacheln[1].org).toBe('cpc · Kunde')
	})

	it('übergeht ein veraltetes Gedächtnis und nimmt dann das bevorzugte Board', () => {
		const gruppe = [board(1, 7), board(2, 7, { pinned: true })]

		expect(targetBoard(gruppe, { 7: 99 }, (b) => b.pinned === true).id).toBe(2)
		expect(targetBoard(gruppe, { 7: 1 }, (b) => b.pinned === true).id).toBe(1)
	})

	it('lässt ein leeres Board ein stillstehendes Projekt nicht grün färben', () => {
		const [kachel] = projectTiles(
			[board(1, 7), board(2, 7)],
			[row(1, { offenGesamt: 2, zustand: 'grau' }), row(2, { offenGesamt: 0, zustand: 'gruen' })],
			{},
			() => false,
		)

		expect(kachel.zustand).toBe('grau')
	})

	it('ordnet die Boards im Überblick nach der Boardliste, nicht nach der Dringlichkeit', () => {
		const [kachel] = overviewProjectTiles(
			[row(2, { zustand: 'rot' }), row(1)],
			[board(1, 7, { projectTitle: 'P' }), board(2, 7, { projectTitle: 'P' })],
			{},
		)

		expect(kachel.boardIds).toEqual([1, 2])
		expect(kachel.boardId).toBe(1)
	})

	it('lässt ein Board mit nur erledigten Vorgängen ein stillstehendes Projekt nicht grün färben', () => {
		const [kachel] = projectTiles(
			[board(1, 7), board(2, 7)],
			[row(1, { offenGesamt: 2, zustand: 'grau' }), row(2, { erledigt: 5, zustand: 'gruen' })],
			{},
			() => false,
		)

		expect(kachel.zustand).toBe('grau')
	})
})
