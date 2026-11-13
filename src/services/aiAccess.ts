/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { apiGet, apiPut } from '@/services/api'

export interface AiAccess {
	publicWrite: boolean
}

/** Der Schalter für KI-Schreiben in kundensichtbare Vorgänge (nur Administration). */
export function fetchAiAccess(): Promise<AiAccess> {
	return apiGet<AiAccess>('/admin/ai-access')
}

/**
 * @param publicWrite Ob die KI in kundensichtbare Vorgänge schreiben darf.
 */
export function saveAiAccess(publicWrite: boolean): Promise<AiAccess> {
	return apiPut<AiAccess, AiAccess>('/admin/ai-access', { publicWrite })
}
