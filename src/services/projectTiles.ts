/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Eine Kachel je Projekt: Projektliste, Überblick und Seitenleiste rechnen hier, nirgends sonst.

import type { Board } from '@/types/board'
import type { ProjectStatusRow } from '@/types/overview'

export type Zustand = ProjectStatusRow['zustand']

/** Die Felder eines Boards, die eine Projektkachel liest. */
export type TileBoard = Pick<Board, 'id' | 'title' | 'projectId' | 'archived' | 'customer' | 'orgInternal'>
	& Partial<Pick<Board, 'projectTitle' | 'projectArchived' | 'pinned'>>

export interface ProjectTileData {
	key: string
	projectId: number | null
	/** Das Board, das die Kachel öffnet. */
	boardId: number
	boardIds: number[]
	archived: boolean
	title: string
	org: string
	neu: number
	offen: number
	wartet: number
	erledigt: number
	verworfen: number
	neuDieseWoche: number
	offenGesamt: number
	zustand: Zustand
	pinned: boolean
}

/** Rot oder gelb an einem Board färbt das Projekt; still steht es erst, wenn sich auf keinem Board etwas bewegt. */
const ZUSTAND_RANG: Zustand[] = ['rot', 'gelb', 'gruen', 'grau']

/** Reihenfolge der Aufmerksamkeit im Überblick: Problemfälle oben, Stillstand vor dem, was läuft. */
export const AUFMERKSAMKEIT: Record<Zustand, number> = { rot: 0, gelb: 1, grau: 2, gruen: 3 }

export function projectKey(board: Pick<Board, 'id' | 'projectId'>): string {
	return board.projectId === null || board.projectId === undefined ? `b${board.id}` : `p${board.projectId}`
}

// Die Boards je Projekt, in der Reihenfolge ihres ersten Auftretens.
export function groupByProject<T extends Pick<Board, 'id' | 'projectId'>>(boards: T[]): T[][] {
	const gruppen = new Map<string, T[]>()
	for (const board of boards) {
		const key = projectKey(board)
		const gruppe = gruppen.get(key)
		if (gruppe === undefined) {
			gruppen.set(key, [board])
		} else {
			gruppe.push(board)
		}
	}
	return [...gruppen.values()]
}

// Zuletzt genutzt, sonst bevorzugt (etwa angepinnt), sonst das erste aktive, sonst das erste; ein veraltetes Gedächtnis zählt nicht.
export function targetBoard<T extends Pick<Board, 'id' | 'projectId' | 'archived'>>(boards: T[], lastBoards: Record<string, number>, prefer: (board: T) => boolean = () => false): T {
	const aktive = boards.filter((b) => !b.archived)
	const projectId = boards[0].projectId
	const zuletzt = projectId === null || projectId === undefined ? undefined : lastBoards[String(projectId)]
	return aktive.find((b) => b.id === zuletzt) ?? aktive.find(prefer) ?? aktive[0] ?? boards[0]
}

export function projectZustand(zustaende: Zustand[]): Zustand {
	return ZUSTAND_RANG.find((z) => zustaende.includes(z)) ?? 'gruen'
}

// Je Projekt eine Kachel, unsortiert.
export function projectTiles(
	boards: TileBoard[],
	rows: ProjectStatusRow[],
	lastBoards: Record<string, number>,
	isPinned: (board: TileBoard) => boolean,
): ProjectTileData[] {
	const status = new Map(rows.map((row) => [row.boardId, row]))

	return groupByProject(boards).map((gruppe): ProjectTileData => {
		const erstes = gruppe[0]
		const zeilen = gruppe.map((b) => status.get(b.id)).filter((r): r is ProjectStatusRow => r !== undefined)
		// Nur offene Vorgänge tragen Bewegung; ein leeres oder fertiges Board darf ein stillstehendes Projekt nicht grün färben.
		const mitInhalt = zeilen.filter((r) => r.offenGesamt > 0)
		const summe = (feld: 'neu' | 'offen' | 'wartet' | 'erledigt' | 'verworfen' | 'neuDieseWoche' | 'offenGesamt'): number => zeilen.reduce((n, r) => n + (r[feld] ?? 0), 0)
		const archived = erstes.projectArchived === true || gruppe.every((b) => b.archived)

		return {
			key: projectKey(erstes),
			projectId: erstes.projectId ?? null,
			boardId: targetBoard(gruppe, lastBoards, isPinned).id,
			boardIds: gruppe.map((b) => b.id),
			archived,
			title: erstes.projectTitle ?? erstes.title,
			org: erstes.customer ?? erstes.orgInternal ?? '',
			neu: summe('neu'),
			offen: summe('offen'),
			wartet: summe('wartet'),
			erledigt: summe('erledigt'),
			verworfen: summe('verworfen'),
			neuDieseWoche: summe('neuDieseWoche'),
			offenGesamt: summe('offenGesamt'),
			zustand: archived ? 'grau' : projectZustand((mitInhalt.length > 0 ? mitInhalt : zeilen).map((r) => r.zustand)),
			pinned: gruppe.some(isPinned),
		}
	})
}

// Die Projektkacheln des Überblicks: nur Boards mit Statuszeile, Projekt-Angaben aus der Boardliste.
export function overviewProjectTiles(rows: ProjectStatusRow[], boards: TileBoard[], lastBoards: Record<string, number>): ProjectTileData[] {
	const mitZeile = new Set(rows.map((row) => row.boardId))
	const bekannt = new Set(boards.map((b) => b.id))
	// Reihenfolge der Boardliste, damit das Zielboard überall dasselbe ist.
	const eingehend: TileBoard[] = [...boards.filter((b) => mitZeile.has(b.id)), ...rows.filter((row) => !bekannt.has(row.boardId)).map((row) => ({
		id: row.boardId,
		title: row.title,
		projectId: null,
		archived: false,
		customer: null,
		orgInternal: row.org === '' ? null : row.org,
	}))]

	return projectTiles(eingehend, rows, lastBoards, (b) => b.pinned === true)
}
