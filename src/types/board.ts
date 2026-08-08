/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Formen, die `/api/v1/boards` liefert.
 *
 * Enum-Werte sind ASCII und englisch (`internal`/`external`), deutsche Texte
 * entstehen erst in der Anzeige — sonst stünde die Rolle in der Datenbank auf
 * Deutsch und ließe sich nie wieder vergleichen.
 */

export type MemberRole = 'internal' | 'external'

export type Visibility = 'public' | 'internal' | 'private'

export interface Board {
	id: number
	title: string
	description: string | null
	ownerUserId: string
	/**
	 * Die Namen der beiden Seiten — am Board, nicht an der Person.
	 *
	 * Ein Board kennt genau zwei Parteien, deshalb genügen zwei Felder. Sie
	 * stehen unter *jedem* Namen, auch unter den internen: In der
	 * Personenauswahl eines öffentlichen Tickets erscheinen beide Seiten
	 * gemeinsam und ohne Trennung. Trüge nur die Kundenseite eine Firma, wäre
	 * die interne stumm „der Normalfall".
	 */
	orgInternal: string | null
	orgExternal: string | null
	folderPublicId: number | null
	folderPublicPath: string | null
	folderInternalId: number | null
	folderInternalPath: string | null
	/** Ohne Adresse entfällt der Knopf „Zum Projektchat" ersatzlos. */
	chatUrl: string | null
	githubEnabled: boolean
	archived: boolean
	createdAt: string | null
	updatedAt: string | null
}

export interface Member {
	id: number
	boardId: number
	userId: string
	role: MemberRole
	/** Nur an interne Mitglieder vergebbar; der Eigentümer behält es immer. */
	isManager: boolean
	/**
	 * Vor- und Nachname für dieses Board.
	 *
	 * `null` heißt: den Anzeigenamen aus Nextcloud verwenden. Nötig, weil der
	 * dort oft ein Kürzel ist — und weil ein Gastkonto ohne gepflegten Namen
	 * sonst mit seiner E-Mail-Adresse auf jeder Karte steht.
	 */
	displayName: string | null
	addedBy: string
	addedAt: string | null
}

export interface Column {
	id: number
	boardId: number
	title: string
	position: number
	color: string | null
}

/**
 * Die eigene Rolle im Board.
 *
 * Kommt vom Server mit, damit das Frontend sie nicht aus der Mitgliederliste
 * zurückrechnet — und damit die Kennzeichnung öffentlicher Tickets (die nur
 * interne Betrachter sehen) eine Quelle hat statt einer Herleitung.
 */
export interface ViewerInfo {
	userId: string
	role: MemberRole
	isManager: boolean
}

export interface BoardDetail {
	board: Board
	members: Member[]
	columns: Column[]
	viewer: ViewerInfo
}
