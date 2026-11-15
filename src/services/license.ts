/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { apiDelete, apiGet, apiPost, apiPut } from '@/services/api'

export type LicenseState = 'none' | 'invalid' | 'valid' | 'grace' | 'expired'

export interface LicenseStatus {
	instanceUuid: string
	keySet: boolean
	keyHint: string | null
	state: LicenseState
	tier: 'free' | 'werkplus'
	features: string[]
	licensedEmployees: number | null
	expiresAt: string | null
	graceUntil: string | null
	lastCheckAt: string | null
	lastError: { code: string, message: string, at: string | null } | null
}

/** Der Lizenzstand (nur Administration). */
export function getLicense(): Promise<LicenseStatus> {
	return apiGet<LicenseStatus>('/admin/license')
}

/**
 * @param key Der neue Schlüssel; leer prüft den gespeicherten.
 */
export function activateLicense(key: string): Promise<LicenseStatus> {
	return apiPut<LicenseStatus, { key: string }>('/admin/license', { key })
}

/** Startet den Testzeitraum; der Schlüssel steht nur in dieser Antwort. */
export function startTrial(): Promise<{ key: string, status: LicenseStatus }> {
	return apiPost<{ key: string, status: LicenseStatus }>('/admin/license/trial', {})
}

/** Das Token jetzt erneuern. */
export function refreshLicense(): Promise<LicenseStatus> {
	return apiPost<LicenseStatus>('/admin/license/refresh', {})
}

/** Schlüssel und Token entfernen. */
export function removeLicense(): Promise<LicenseStatus> {
	return apiDelete<LicenseStatus>('/admin/license')
}
