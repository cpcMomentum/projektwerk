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

/** Zähler je Ticket-ID, aus derselben gefilterten Menge wie die Tickets. */
export type CountsByTicketId = Record<number, number>

export interface TicketList {
	tickets: Ticket[]
	counts: {
		comments: CountsByTicketId
		steps: CountsByTicketId
		attachments: CountsByTicketId
		collaborators: CountsByTicketId
	}
}

export interface TicketDetail {
	ticket: Ticket
	comments: unknown[]
	steps: unknown[]
	attachments: unknown[]
	collaborators: unknown[]
}
