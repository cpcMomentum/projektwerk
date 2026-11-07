/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Endpunkte der Anhänge.
 *
 * Gelesen wird hier nichts: Die Liste kommt über `ticket#show` mit, aus
 * derselben gefilterten Ticketmenge wie Kommentare und Arbeitsschritte. Und
 * heruntergeladen wird hier auch nichts — die Datei holt der Browser bei
 * Nextcloud, nicht bei uns.
 */

import type { WriteOptions } from '@/services/api'
import type { Attachment } from '@/types/ticket'

import { apiDelete, apiUpload } from '@/services/api'

/**
 * Eine Datei an einen Vorgang hängen.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Vorgangs.
 * @param file Die gewählte Datei.
 * @param options Kennung der Anlage für Wiederholungen.
 */
export async function createAttachment(boardId: number, ticketId: number, file: File, options?: WriteOptions): Promise<Attachment> {
	return apiUpload<Attachment>(`/boards/${boardId}/tickets/${ticketId}/attachments`, file, options)
}

/**
 * Die Verknüpfung lösen — **die Datei bleibt liegen** (§5.18).
 *
 * @param boardId Kennung des Projekts.
 * @param attachmentId Kennung des Anhangs.
 */
export async function deleteAttachment(boardId: number, attachmentId: number): Promise<Attachment> {
	return apiDelete<Attachment>(`/boards/${boardId}/attachments/${attachmentId}`)
}
