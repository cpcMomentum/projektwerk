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

import { apiDelete, apiGet, apiPatch, apiPost, apiPut } from '@/services/api'

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
 * @param changes.folderPublicPath
 * @param changes.folderInternalPath
 * @param changes.githubEnabled
 * @param changes.githubRepo
 */
export async function updateBoard(boardId: number, changes: {
	title?: string
	description?: string | null
	orgInternal?: string | null
	orgExternal?: string | null
	chatUrl?: string | null
	/**
	 * Die beiden Projektordner als **Pfad**.
	 *
	 * Gespeichert wird daraus die Datei-ID; der Pfad benennt den Ordner nur
	 * (§5.18). Zurück kommt der aufgelöste, kanonische Pfad — nicht der
	 * getippte. Der leere String entfernt die Zuordnung.
	 */
	folderPublicPath?: string
	folderInternalPath?: string
	/** Ist dieses Projekt ein Softwareprojekt — schaltet die GitHub-Überführung frei (#12). */
	githubEnabled?: boolean
	/** Ziel-Repository „owner/repo"; leerer String entfernt das Ziel. */
	githubRepo?: string | null
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
 * Eine Spalte als Endspalte markieren (#172).
 *
 * @param boardId Kennung des Projekts.
 * @param columnId Kennung der Spalte.
 * @param finalOutcome `'done'` / `'discarded'` macht sie zur Endspalte mit diesem Ergebnis, `null` nimmt die Markierung.
 */
export async function setColumnFinalOutcome(boardId: number, columnId: number, finalOutcome: 'done' | 'discarded' | null): Promise<Column> {
	return apiPatch<Column, { finalOutcome: 'done' | 'discarded' | null }>(
		`/boards/${boardId}/columns/${columnId}/final-outcome`,
		{ finalOutcome },
	)
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
 * Eine Spalte entfernen — **immer mit Zielspalte**.
 *
 * Es wird nichts gelöscht, sondern verschoben: Die Spalte enthält womöglich
 * Vorgänge, die der Löschende nicht sehen darf. Eine Rückfrage könnte dann nur
 * zwischen zwei Fehlern wählen — eine Zahl über alle verriete Verborgenes, eine
 * Zahl über die sichtbaren löschte ungefragt mehr, als sie ankündigt.
 *
 * Nur der Eigentümer des Projekts darf das; Verwaltungsrecht allein reicht
 * nicht und ergibt 403.
 *
 * @param boardId Kennung des Projekts.
 * @param columnId Die Spalte, die wegfällt.
 * @param targetColumnId Wohin ihre Vorgänge wandern — Pflicht, keine Vorbelegung.
 */
export async function deleteColumn(boardId: number, columnId: number, targetColumnId: number): Promise<void> {
	await apiDelete<void, { targetColumnId: number }>(
		`/boards/${boardId}/columns/${columnId}`,
		{ targetColumnId },
	)
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

/**
 * Wie viele private Vorgänge das Entfernen dieses Mitglieds löschen würde (§5.29)
 * — für die bezifferte Rückfrage.
 *
 * @param boardId Kennung des Projekts.
 * @param userId Kennung der Person.
 */
export async function memberRemovalImpact(boardId: number, userId: string): Promise<{ privateTickets: number }> {
	return apiGet<{ privateTickets: number }>(`/boards/${boardId}/members/${encodeURIComponent(userId)}/removal-impact`)
}

/**
 * Ein Mitglied aus dem Projekt entfernen (§5.29).
 *
 * @param boardId Kennung des Projekts.
 * @param userId Kennung der Person.
 */
export async function removeMember(boardId: number, userId: string): Promise<void> {
	await apiDelete<void>(`/boards/${boardId}/members/${encodeURIComponent(userId)}`)
}

/** Ein Nextcloud-Konto, das noch nicht Mitglied ist. */
export interface Candidate {
	userId: string
	displayName: string
}

/**
 * Konten suchen, um sie hinzuzufügen.
 *
 * **Nicht Nextclouds Personensuche**, sondern ein eigener Endpunkt. Der Grund
 * steht dort: Nextclouds Sucher liefert in Gast-Sitzungen prinzipbedingt eine
 * leere Liste, und ein Aufruf mit dieser Eigenschaft im Frontend wäre
 * irgendwann an eine Stelle kopiert, wo Gäste hinkommen.
 *
 * Der Endpunkt weist ohne Verwaltungsrecht mit 403 ab — nicht mit einer leeren
 * Liste, die wie „niemand gefunden" aussähe.
 *
 * @param boardId Kennung des Projekts.
 * @param search Suchbegriff.
 */
export async function searchCandidates(boardId: number, search: string): Promise<Candidate[]> {
	const antwort = await apiGet<{ users: Candidate[] }>(`/boards/${boardId}/member-search?search=${encodeURIComponent(search)}`)

	return antwort.users
}
