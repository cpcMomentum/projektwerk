/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * „Was ist neu?"-Fenster (#315) — Anbindung an die App-API.
 */

import type { WhatsNewPayload } from '@/types/whatsnew'

import { apiGet, apiPost } from '@/services/api'

/** Noch nicht gesehene Neuerungen der laufenden Version. */
export function getWhatsNew(): Promise<WhatsNewPayload> {
	return apiGet<WhatsNewPayload>('/whatsnew')
}

/** Quittiert das Fenster; es kommt fuer diese Version nicht wieder. */
export function markWhatsNewSeen(): Promise<void> {
	return apiPost<void, Record<string, never>>('/whatsnew/seen', {})
}
