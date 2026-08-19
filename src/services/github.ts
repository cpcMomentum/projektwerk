/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die GitHub-Überführung (#12, Stufe 1) — einseitig „Vorgang → Issue".
 *
 * Zwei Dinge liegen hier: der **eigene Token** (user-scoped, ohne Board im Pfad,
 * wie die Kanalschalter) und die **Überführung** eines Vorgangs. Der Token
 * verlässt den Server nie — abfragbar ist allein, ob einer hinterlegt ist.
 */

import type { Ticket } from '@/types/ticket'

import { apiDelete, apiGet, apiPost, apiPut } from '@/services/api'

export interface GithubTokenStatus {
	/** Ob die angemeldete Person einen Token hinterlegt hat — nie der Token selbst. */
	present: boolean
}

/** Ob ein Token hinterlegt ist. */
export function fetchGithubTokenStatus(): Promise<GithubTokenStatus> {
	return apiGet<GithubTokenStatus>('/my/github-token')
}

/**
 * Einen Token hinterlegen (oder ersetzen). Ein leerer Token wird vom Server
 * abgewiesen; zum Entfernen dient `clearGithubToken`.
 *
 * @param token Der persönliche GitHub-Token (fine-grained PAT).
 */
export function setGithubToken(token: string): Promise<GithubTokenStatus> {
	return apiPut<GithubTokenStatus, { token: string }>('/my/github-token', { token })
}

/** Den Token wieder entfernen. */
export function clearGithubToken(): Promise<GithubTokenStatus> {
	return apiDelete<GithubTokenStatus>('/my/github-token')
}

/**
 * Einen Vorgang nach GitHub überführen. Legt ein Issue im am Board hinterlegten
 * Repository an und gibt den aktualisierten Vorgang zurück — mit Nummer und
 * Adresse des Issues.
 *
 * Fehler (kein Token, falsches Repo, GitHub nicht erreichbar) kommen als
 * Servermeldung zurück; der Vorgang bleibt dann unverändert.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Vorgangs.
 */
export function transferTicketToGithub(boardId: number, ticketId: number): Promise<Ticket> {
	return apiPost<Ticket, Record<string, never>>(
		`/boards/${boardId}/tickets/${ticketId}/github`,
		{},
	)
}
