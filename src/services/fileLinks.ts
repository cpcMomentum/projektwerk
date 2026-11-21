/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FileLink } from '@/types/ticket'

import { apiDelete, apiPost } from '@/services/api'

/**
 * Eine Datei oder einen Ordner am Vorgang verknüpfen; das Ziel bleibt, wo es ist.
 *
 * @param boardId Kennung des Boards.
 * @param ticketId Kennung des Vorgangs.
 * @param path Pfad relativ zur eigenen Files-Wurzel.
 */
export async function createFileLink(boardId: number, ticketId: number, path: string): Promise<FileLink> {
	return apiPost<FileLink>(`/boards/${boardId}/tickets/${ticketId}/links`, { path })
}

/**
 * @param boardId Kennung des Boards.
 * @param linkId Kennung der Verknüpfung.
 */
export async function deleteFileLink(boardId: number, linkId: number): Promise<void> {
	await apiDelete(`/boards/${boardId}/links/${linkId}`)
}
