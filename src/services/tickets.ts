/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Ticket-Endpunkte, getippt. Alles geht durch `api.ts` und damit durch den
 * Nicht-JSON-Wächter.
 */

import type { Visibility } from '@/types/board'
import type { Ticket, TicketDetail, TicketList } from '@/types/ticket'

import { apiGet, apiPatch, apiPost, apiPut } from '@/services/api'

/**
 * Die sichtbaren Tickets eines Boards, mit den Zählern ihrer Kinder.
 *
 * @param boardId Kennung des Projekts.
 * @param columnId Auf eine Spalte einschränken; das ist keine zweite Berechtigungsfrage.
 */
export async function fetchTickets(boardId: number, columnId?: number): Promise<TicketList> {
	const query = columnId === undefined ? '' : `?columnId=${columnId}`
	return apiGet<TicketList>(`/boards/${boardId}/tickets${query}`)
}

/**
 * Ein Ticket mit seinen Kindern.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Tickets.
 */
export async function fetchTicket(boardId: number, ticketId: number): Promise<TicketDetail> {
	return apiGet<TicketDetail>(`/boards/${boardId}/tickets/${ticketId}`)
}

/**
 * Ein neues Ticket.
 *
 * `visibility` ist Pflicht und hat keine serverseitige Vorbelegung: §9 verlangt
 * die Zeile fest sichtbar im Formular mit der Vorauswahl „Alle Beteiligten".
 * Ein stiller Vorgabewert im Server wäre genau die eingeklappte Variante, die
 * §9 verhindern will.
 *
 * @param boardId Kennung des Projekts.
 * @param data Felder des neuen Tickets.
 * @param data.title
 * @param data.columnId
 * @param data.visibility
 * @param data.description
 * @param data.responsibleUserId
 */
export async function createTicket(boardId: number, data: {
	title: string
	columnId: number
	visibility: Visibility
	description?: string | null
	responsibleUserId?: string | null
}): Promise<Ticket> {
	return apiPost<Ticket, typeof data>(`/boards/${boardId}/tickets`, data)
}

/**
 * Titel, Beschreibung, Zuständigkeit, geschlossen.
 *
 * Die Sichtbarkeit ist bewusst nicht dabei — sie hat eine eigene Regel und
 * deshalb einen eigenen Weg.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Tickets.
 * @param version Der zuletzt gelesene Stand; bei Abweichung antwortet der Server mit 409.
 * @param changes Nur die Felder, die sich ändern sollen.
 * @param changes.title
 * @param changes.description
 * @param changes.responsibleUserId
 * @param changes.closed
 */
export async function updateTicket(
	boardId: number,
	ticketId: number,
	version: number,
	changes: { title?: string, description?: string | null, responsibleUserId?: string | null, closed?: boolean },
): Promise<Ticket> {
	return apiPatch<Ticket, typeof changes & { version: number }>(
		`/boards/${boardId}/tickets/${ticketId}`,
		{ ...changes, version },
	)
}

/**
 * Ein Ticket verschieben — mit Nachbar-IDs, nie mit einer Position.
 *
 * Menüeintrag „Verschieben nach …", Tastaturweg und später Drag & Drop rufen
 * dieselbe Funktion. Damit ist die Tastaturbedienung strukturell erfüllt statt
 * nachgerüstet.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Tickets.
 * @param version Der zuletzt gelesene Stand.
 * @param target Zielspalte und die beiden Nachbarn aus der eigenen Sicht; `null` heißt Rand.
 * @param target.targetColumnId
 * @param target.beforeId
 * @param target.afterId
 */
export async function moveTicket(
	boardId: number,
	ticketId: number,
	version: number,
	target: { targetColumnId: number, beforeId: number | null, afterId: number | null },
): Promise<Ticket> {
	return apiPost<Ticket, typeof target & { version: number }>(
		`/boards/${boardId}/tickets/${ticketId}/move`,
		{ ...target, version },
	)
}

/**
 * Was ein Sichtbarkeitswechsel kosten würde.
 *
 * Für den Rückfragedialog aus §9, der konkrete Zahlen und Namen nennen soll
 * statt einer allgemeinen Warnung.
 *
 * **Bewusst ein Serveraufruf**, obwohl das Frontend die Mitgliederliste schon
 * hat: Wer den Zugriff verliert, folgt aus der Sichtbarkeitsregel — und die
 * gehört an genau eine Stelle. Im Browser nachzurechnen wäre eine zweite
 * Umsetzung derselben Regel.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Tickets.
 * @param visibility Die angedachte neue Stufe.
 */
export async function fetchVisibilityImpact(
	boardId: number,
	ticketId: number,
	visibility: Visibility,
): Promise<{ losing: string[], comments: number, attachments: number }> {
	return apiGet(`/boards/${boardId}/tickets/${ticketId}/visibility-impact?visibility=${visibility}`)
}

/**
 * Die Sichtbarkeit ändern.
 *
 * Antwortet mit 403, wenn das Ticket der anderen Seite gehört — nicht mit 404:
 * Der Betrachter sieht es ja, zu verbergen gibt es nichts mehr.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Tickets.
 * @param version Der zuletzt gelesene Stand.
 * @param visibility Die neue Stufe.
 */
export async function changeVisibility(
	boardId: number,
	ticketId: number,
	version: number,
	visibility: Visibility,
): Promise<Ticket> {
	return apiPut<Ticket, { version: number, visibility: Visibility }>(
		`/boards/${boardId}/tickets/${ticketId}/visibility`,
		{ version, visibility },
	)
}
