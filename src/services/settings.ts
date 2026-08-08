/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Schreibwege der Board-Einstellungen.
 *
 * Es gibt hier bewusst keine Lesefunktion: Die Einstellungsseite liest über
 * `fetchBoard()`, das Board, Mitglieder und Spalten in einem Zug liefert. Ein
 * zweiter Lesepfad für dieselben Daten wäre genau der, gegen den die Bauform
 * gerichtet ist.
 *
 * Alle Endpunkte antworten mit 403, wenn der Betrachter kein internes Mitglied
 * mit Verwaltungsrecht ist — nicht mit 404: Er ist Mitglied und sieht das
 * Board, zu verbergen gibt es nichts mehr.
 */

import type { Board, Column, Member, MemberRole } from '@/types/board'

import { apiPatch, apiPost, apiPut } from '@/services/api'

/**
 * Ein neues Projekt. Wer anlegt, wird Eigentümer und interner Verwalter.
 *
 * @param data Titel und optional Beschreibung sowie die Namen der beiden Seiten.
 * @param data.title
 * @param data.description
 * @param data.orgInternal
 * @param data.orgExternal
 */
export async function createBoard(data: {
	title: string
	description?: string | null
	orgInternal?: string | null
	orgExternal?: string | null
}): Promise<Board> {
	return apiPost<Board, typeof data>('/boards', data)
}

/**
 * Titel, Beschreibung, Firmennamen, Chat-Adresse.
 *
 * Nur genannte Felder ändern sich. Ein leeres Feld wird zu `null` — der Knopf
 * „Zum Projektchat" entfällt dann ersatzlos.
 *
 * @param boardId Kennung des Projekts.
 * @param changes Die zu ändernden Felder.
 * @param changes.title
 * @param changes.description
 * @param changes.orgInternal
 * @param changes.orgExternal
 * @param changes.chatUrl
 */
export async function updateBoard(boardId: number, changes: {
	title?: string
	description?: string | null
	orgInternal?: string | null
	orgExternal?: string | null
	chatUrl?: string | null
}): Promise<Board> {
	return apiPatch<Board, typeof changes>(`/boards/${boardId}`, changes)
}

/**
 * Archivieren heißt: aus der Startseite verschwunden, nicht gelöscht.
 *
 * @param boardId Kennung des Projekts.
 * @param archived Neuer Zustand.
 */
export async function setBoardArchived(boardId: number, archived: boolean): Promise<Board> {
	return apiPut<Board, { archived: boolean }>(`/boards/${boardId}/archived`, { archived })
}

/**
 * Eine neue Spalte, hinten angehängt.
 *
 * @param boardId Kennung des Projekts.
 * @param title Name der Spalte — beide Seiten sehen denselben.
 * @param color Optionale Farbe.
 */
export async function createColumn(boardId: number, title: string, color?: string | null): Promise<Column> {
	return apiPost<Column, { title: string, color?: string | null }>(`/boards/${boardId}/columns`, { title, color })
}

/**
 * @param boardId Kennung des Projekts.
 * @param columnId Kennung der Spalte.
 * @param title Neuer Name.
 */
export async function renameColumn(boardId: number, columnId: number, title: string): Promise<Column> {
	return apiPatch<Column, { title: string }>(`/boards/${boardId}/columns/${columnId}`, { title })
}

/**
 * Die Reihenfolge neu setzen — mit **allen** Spalten des Boards.
 *
 * Eine unvollständige Liste wird abgewiesen. Sonst entschiede über die nicht
 * genannten Spalten der Zufall.
 *
 * @param boardId Kennung des Projekts.
 * @param columnIds Alle Spalten in Sollreihenfolge.
 */
export async function reorderColumns(boardId: number, columnIds: number[]): Promise<Column[]> {
	return apiPut<Column[], { columnIds: number[] }>(`/boards/${boardId}/columns/order`, { columnIds })
}

/**
 * Eine Person hinzufügen — personenweise, keine Gruppen.
 *
 * @param boardId Kennung des Projekts.
 * @param data Kennung, Rolle und optional Verwaltungsrecht sowie Name für dieses Board.
 * @param data.userId
 * @param data.role
 * @param data.isManager
 * @param data.displayName
 */
export async function addMember(boardId: number, data: {
	userId: string
	role: MemberRole
	isManager?: boolean
	displayName?: string | null
}): Promise<Member> {
	return apiPost<Member, typeof data>(`/boards/${boardId}/members`, data)
}

/**
 * Rolle, Verwaltungsrecht und Name einer Mitgliedschaft ändern.
 *
 * Beim Rollenwechsel bleibt `creator_role` an bestehenden Tickets eingefroren —
 * von dieser Person angelegte interne Tickets verbleiben bei der bisherigen
 * Seite. Der Dialog weist darauf hin; eine Zahl nennt er bewusst nicht.
 *
 * @param boardId Kennung des Projekts.
 * @param userId Kennung der Person.
 * @param changes Die zu ändernden Felder.
 * @param changes.role
 * @param changes.isManager
 * @param changes.displayName
 */
export async function updateMember(boardId: number, userId: string, changes: {
	role?: MemberRole
	isManager?: boolean
	displayName?: string | null
}): Promise<Member> {
	return apiPatch<Member, typeof changes>(`/boards/${boardId}/members/${encodeURIComponent(userId)}`, changes)
}
