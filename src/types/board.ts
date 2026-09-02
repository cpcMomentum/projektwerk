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
	/**
	 * Das Projekt, zu dem dieses Board gehört (#246). Mehrere Boards teilen sich
	 * ein Projekt — und damit Mitglieder, Ordner und Nummernkreis. Das Frontend
	 * gruppiert die Board-Liste danach.
	 */
	projectId: number | null
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
	/**
	 * Das Ziel-Repository der GitHub-Überführung als „owner/repo" (#12).
	 *
	 * `null`, solange keins gesetzt ist — ein aktiviertes Board ohne Repo ist
	 * ein zulässiger Zwischenzustand, die Überführungs-Aktion bleibt dann aus.
	 */
	githubRepo: string | null
	archived: boolean
	/**
	 * Ob **dieser** Nutzer das Projekt in die Seitenleiste gepinnt hat (#115).
	 *
	 * Optional, weil nur die Projektliste (`board#index`) es mitliefert; die
	 * Detailansicht (`board#show`) kennt es nicht — sie braucht es auch nicht.
	 */
	pinned?: boolean
	/**
	 * Die eigene Rolle in **diesem** Projekt (#234) — `internal`/`external`.
	 *
	 * Optional aus demselben Grund wie `pinned`: Nur die Projektliste
	 * (`board#index`) liefert sie mit, die Detailansicht kennt sie unter
	 * `viewer.role`. Sie trägt das Gäste-Gate: Wer in allen seinen Projekten
	 * extern ist, landet nicht auf dem Überblick, sondern auf seinem Board.
	 */
	viewerRole?: MemberRole
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
	 * Vor- und Nachname für dieses Board — ein **Übersteuern**.
	 *
	 * `null` heißt: den Anzeigenamen aus Nextcloud verwenden. Nötig, weil der
	 * dort oft ein Kürzel ist — und weil ein Gastkonto ohne gepflegten Namen
	 * sonst mit seiner E-Mail-Adresse auf jeder Karte steht.
	 *
	 * Zum **Anzeigen** ist `resolvedName` gemeint, nicht dieses Feld. Hier steht
	 * nur, was die Mitgliederverwaltung eingetragen hat.
	 */
	displayName: string | null
	/**
	 * Der Name, der anzuzeigen ist: Übersteuern, sonst Nextcloud, sonst Kennung.
	 *
	 * Kommt fertig vom Server, weil nur er ihn hat: Nextclouds Personensuche
	 * liefert in einer Gast-Sitzung prinzipbedingt eine leere Liste, ein
	 * Nachschlagen im Browser bliebe also ausgerechnet beim Kunden stumm.
	 */
	resolvedName: string
	addedBy: string
	addedAt: string | null
}

export interface Column {
	id: number
	boardId: number
	title: string
	position: number
	color: string | null
	/**
	 * Endspalten-Ergebnis (#172): `'done'` (erledigt) oder `'discarded'`
	 * (verworfen) markiert die Spalte als Endspalte mit diesem Ergebnis; `null`
	 * heißt „keine Endspalte". Zieht man eine Karte hinein, bietet die App
	 * „Auch abschließen?" an.
	 */
	finalOutcome: 'done' | 'discarded' | null
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
