/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const KEY = 'projektwerk.lastBoard'

function read(): Record<string, number> {
	try {
		const raw = window.localStorage.getItem(KEY)
		const parsed: unknown = raw === null ? {} : JSON.parse(raw)
		return parsed !== null && typeof parsed === 'object' ? parsed as Record<string, number> : {}
	} catch {
		return {}
	}
}

/** Projekt-ID → zuletzt genutztes Board, nur in diesem Browser gemerkt. */
export function lastBoards(): Record<string, number> {
	return read()
}

export function rememberLastBoard(projectId: number, boardId: number): void {
	try {
		window.localStorage.setItem(KEY, JSON.stringify({ ...read(), [String(projectId)]: boardId }))
	} catch {
		// Ohne Speicher öffnet die Kachel eben das erste Board.
	}
}
