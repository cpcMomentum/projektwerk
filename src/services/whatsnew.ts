/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * „Was ist neu?"-Fenster (#315) — Anbindung an die App-API.
 */

import type { WhatsNewArchive, WhatsNewPayload } from '@/types/whatsnew'

import { apiGet, apiPost } from '@/services/api'

/** Noch nicht gesehene Neuerungen der laufenden Version. */
export function getWhatsNew(): Promise<WhatsNewPayload> {
	return apiGet<WhatsNewPayload>('/whatsnew')
}

/** Alle bisherigen Neuerungen, nach Version gruppiert (#329) — fürs Archiv-Menü. */
export function getWhatsNewArchive(): Promise<WhatsNewArchive> {
	return apiGet<WhatsNewArchive>('/whatsnew/all')
}

/** Quittiert das Fenster; es kommt fuer diese Version nicht wieder. */
export function markWhatsNewSeen(): Promise<void> {
	return apiPost<void, Record<string, never>>('/whatsnew/seen', {})
}
