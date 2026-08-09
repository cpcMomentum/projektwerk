/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Das Ziel eines fragmentfreien Deep-Links, aus dem Initial State.
 *
 * Die Route `/apps/projektwerk/t/{id}` liefert dieselbe Hülle wie die
 * Startseite und legt daneben, welcher Vorgang gemeint war. Erst hier wird
 * daraus eine Hash-Route — der Server schiebt keine, weil ein Fragment den
 * Login-Umweg nicht überlebt und der Link dann ausgerechnet bei den Gästen
 * versagte, die ihn am dringendsten brauchen.
 */

import { loadState } from '@nextcloud/initial-state'

export interface DeepLinkTarget {
	ticketId: number
	/** Fehlt, wenn der Vorgang für diesen Betrachter nicht sichtbar ist. */
	boardId?: number
	available: boolean
}

/**
 * Das Ziel, oder `null` auf der normalen Startseite.
 *
 * `loadState` wirft, wenn der Schlüssel fehlt — und fehlen ist hier der
 * Normalfall, nicht der Fehler: Jeder Aufruf über `page#index` kommt ohne.
 * Deshalb der Vorgabewert statt eines try/catch, das den Unterschied zwischen
 * „nicht da" und „kaputt" verwischen würde.
 */
export function deepLinkTarget(): DeepLinkTarget | null {
	return loadState<DeepLinkTarget | null>('projektwerk', 'deepLink', null)
}
