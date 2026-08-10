/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Endpunkte der Kommentare.
 *
 * Gelesen werden Kommentare **nicht** hier: Sie kommen über `ticket#show` mit,
 * aus derselben gefilterten Ticketmenge wie die Arbeitsschritte. Ein eigener
 * Leseweg wäre der zweite Ort, an dem die Sichtbarkeit stimmen müsste.
 */

import type { Comment } from '@/types/ticket'

import { apiDelete, apiPatch, apiPost } from '@/services/api'

/**
 * Ein neuer Kommentar am Ende des Verlaufs.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Vorgangs.
 * @param body Der Text, Markdown.
 */
export async function createComment(boardId: number, ticketId: number, body: string): Promise<Comment> {
	return apiPost<Comment, { body: string }>(`/boards/${boardId}/tickets/${ticketId}/comments`, { body })
}

/**
 * Den eigenen Kommentar ändern.
 *
 * Der Server lehnt fremde Kommentare mit 403 ab — die Oberfläche bietet das
 * Ändern gar nicht erst an, aber verlassen tut sie sich darauf nicht.
 *
 * @param boardId Kennung des Projekts.
 * @param commentId Kennung des Kommentars.
 * @param body Der neue Text.
 */
export async function updateComment(boardId: number, commentId: number, body: string): Promise<Comment> {
	return apiPatch<Comment, { body: string }>(`/boards/${boardId}/comments/${commentId}`, { body })
}

/**
 * Den eigenen Kommentar löschen — endgültig, kein Papierkorb.
 *
 * @param boardId Kennung des Projekts.
 * @param commentId Kennung des Kommentars.
 */
export async function deleteComment(boardId: number, commentId: number): Promise<Comment> {
	return apiDelete<Comment>(`/boards/${boardId}/comments/${commentId}`)
}
