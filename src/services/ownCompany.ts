/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'
import { ref } from 'vue'
import { apiPut } from '@/services/api'

/**
 * Die eigene Firma der Instanz (#352). Kommt als Initial-State mit der Seite und
 * wird nach dem Speichern hier nachgeführt, damit der Projekt-Assistent ohne
 * Neuladen den neuen Namen vorbelegt.
 */
export const ownCompany = ref<string>(loadState<string>('projektwerk', 'ownCompany', ''))

/**
 * Speichert die eigene Firma (nur Administration). Leer entfernt sie.
 *
 * @param name Der Firmenname.
 */
export async function saveOwnCompany(name: string): Promise<string> {
	const result = await apiPut<{ ownCompany: string | null }, { ownCompany: string }>('/admin/own-company', { ownCompany: name })
	ownCompany.value = result.ownCompany ?? ''

	return ownCompany.value
}
