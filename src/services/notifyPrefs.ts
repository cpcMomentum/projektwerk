/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die eigenen Kanalschalter.
 *
 * **Ohne Projekt in der Adresse.** Die Grenze ist die Benutzerkennung aus der
 * Sitzung — jeder liest und schreibt nur die eigenen Schalter.
 */

import { apiDelete, apiGet, apiPut } from '@/services/api'

/** Ein Kanal, wie ihn der Server kennt. */
export type Channel = 'bell' | 'mail'

/**
 * Der **gespeicherte** Stand, nicht der aufgelöste.
 *
 * Ein fehlender Eintrag heißt „hier steht nichts" — und damit „es gilt die
 * globale Einstellung, sonst an". Die Oberfläche muss das unterscheiden
 * können: Ein geerbtes „an" sieht sonst aus wie ein gesetztes, und ein Klick
 * darauf täte nichts Sichtbares.
 */
export interface NotifyPrefs {
	global: Partial<Record<Channel, boolean>>
	boards: Record<number, Partial<Record<Channel, boolean>>>
}

/** Der gespeicherte Stand der eigenen Kanalschalter. */
export async function fetchNotifyPrefs(): Promise<NotifyPrefs> {
	return apiGet<NotifyPrefs>('/notify-prefs')
}

/**
 * Einen Schalter setzen.
 *
 * @param channel Glocke oder Mail.
 * @param enabled Neuer Stand.
 * @param boardId Projekt, oder 0 für global.
 */
export async function setNotifyPref(channel: Channel, enabled: boolean, boardId = 0): Promise<NotifyPrefs> {
	return apiPut<NotifyPrefs, { channel: Channel, enabled: boolean, boardId: number }>(
		'/notify-prefs',
		{ channel, enabled, boardId },
	)
}

/**
 * Alle Projekt-Ausnahmen wegräumen — danach gilt überall die globale
 * Einstellung.
 */
export async function clearNotifyOverrides(): Promise<NotifyPrefs> {
	return apiDelete<NotifyPrefs>('/notify-prefs/overrides')
}
