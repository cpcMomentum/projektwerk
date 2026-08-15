/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Formen der Ticket-Endpunkte.
 *
 * `position` fehlt hier, und zwar mit Absicht: Der Server liefert sie nicht.
 * Das Ticket ist die einzige je Betrachter gefilterte Entität, und eine
 * ausgelieferte Sortierposition verriete die Lücken (§5.8). Die Reihenfolge
 * steckt in der Reihenfolge des Feldes, und Verschieben nennt Nachbar-IDs.
 */

import type { MemberRole, Visibility } from '@/types/board'

export interface Ticket {
	id: number
	boardId: number
	columnId: number
	/** Boardweit fortlaufend. Lücken sind offengelegt (§11) — sie trägt Dateinamen. */
	number: number
	title: string
	description: string | null
	visibility: Visibility
	creatorUserId: string
	/** Am Ticket eingefroren, nicht zur Laufzeit ermittelt. */
	creatorRole: MemberRole
	responsibleUserId: string | null
	/** „Bis wann ist die Sache fertig" (#72). `JJJJ-MM-TT` ohne Uhrzeit, oder `null`. */
	dueDate: string | null
	closedAt: string | null
	/** Für die Konflikterkennung; unverändert zurückschicken. */
	version: number
	/**
	 * Wer den aktuellen `version`-Stand verursacht hat.
	 *
	 * `null` heißt: seit dem Anlegen unverändert. Wer es angelegt hat, steht in
	 * `creatorUserId` — die beiden Felder sagen bewusst Verschiedenes.
	 */
	lastEditorUserId: string | null
	githubIssueNumber: number | null
	githubIssueUrl: string | null
	createdAt: string | null
	updatedAt: string | null
}

/**
 * Ein Kommentar am Vorgang.
 *
 * **Ohne eigene Sichtbarkeit** — er erbt sie vollständig vom Ticket. Deshalb
 * gibt es hier kein Feld dafür und im Browser keine Bedingung darauf.
 *
 * `updatedAt` ist beim Anlegen gleich `createdAt`; ein späterer Wert heißt
 * genau „wurde nachträglich geändert".
 */
export interface Comment {
	id: number
	ticketId: number
	authorUserId: string
	/** Markdown. Wird mit `NcRichText` gerendert, ohne `interactive`. */
	body: string
	createdAt: string | null
	updatedAt: string | null
}

/**
 * Ein Anhang — ein **Verweis** auf eine Datei, keine Kopie.
 *
 * Führend ist `fileId`. `filePath` dient der Anzeige und darf veralten: Wer den
 * Projektordner umbenennt, lässt eine Beschriftung falsch werden und keine
 * Verknüpfung reißen (§5.18).
 */
export interface Attachment {
	id: number
	ticketId: number
	/** Die Datei-ID in Nextcloud — der einzige führende Wert. */
	fileId: number
	/** Nur zur Anzeige, kann veraltet sein. */
	filePath: string | null
	fileName: string
	/** 'public' | 'internal' — in welchem der beiden Projektordner sie liegt. */
	location: string
	uploadedBy: string
	createdAt: string | null
	/**
	 * Ob die Datei im Dateibaum noch da ist (#9). `true` heißt: gelöscht oder
	 * weggeschoben — die Zeile zeigt „nicht mehr vorhanden" statt eines Links.
	 * Vom Server je Betrachter beim Öffnen des Vorgangs bestimmt.
	 */
	missing?: boolean
}

export interface Step {
	id: number
	ticketId: number
	title: string
	assignedUserId: string | null
	/** Bei der Zuweisung **kopiert**, nicht zur Laufzeit ermittelt. */
	assignedRole: MemberRole | null
	assignedAt: string | null
	done: boolean
	doneAt: string | null
	/** JJJJ-MM-TT oder null. */
	dueDate: string | null
	position: number
	createdAt: string | null
}

/**
 * „Wartet auf Kunde" — gerechnet, nie gespeichert.
 *
 * Kommt mit jeder Ticketabfrage aus denselben Schritten, die auch die Zähler
 * speisen. Ein gespeichertes Feld müsste bei jedem Zuweisen, Erledigen und
 * Rollenwechsel mitgepflegt werden.
 */
export interface WaitState {
	/** Das **kleinste** `assignedAt` der wartenden Schritte, nicht das jüngste. */
	since: string
	userIds: string[]
}

/** Zähler je Ticket-ID, aus derselben gefilterten Menge wie die Tickets. */
export type CountsByTicketId = Record<number, number>

export interface TicketList {
	tickets: Ticket[]
	counts: {
		comments: CountsByTicketId
		steps: CountsByTicketId
		stepsDone: CountsByTicketId
		attachments: CountsByTicketId
		collaborators: CountsByTicketId
	}
	/** Nur die wartenden Tickets stehen drin. */
	waiting: Record<number, WaitState>
	/**
	 * „Seit deinem Blick geändert" (#79) — nur die geänderten Vorgänge stehen
	 * drin, und nur solche, die du schon einmal geöffnet hast.
	 */
	changed: Record<number, boolean>
}

export interface TicketDetail {
	ticket: Ticket
	waiting: WaitState | null
	comments: Comment[]
	steps: Step[]
	attachments: Attachment[]
	collaborators: unknown[]
}
