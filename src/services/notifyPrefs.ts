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

/**
 * Ein Schalter, wie ihn der Server kennt.
 *
 * Zwei Arten, und sie gelten verschieden weit (Entscheidung 2026-08-11):
 *
 * - **Kanäle** (`mail`, `bell`) — *wie* benachrichtigt wird. Nur global.
 * - **Anlässe** (`ticket_assigned`, …) — *wovon*. Global und je Projekt.
 *
 * Der Feldname heißt `prefKey` und nicht mehr `channel` (#98): Er trägt beides,
 * und „Kanal" benannte davon die kleinere Hälfte.
 */
export type Channel = 'bell' | 'mail'

/** Die fünf Anlässe — drei aus §21 der Produktbeschreibung, zwei aus #98. */
export type NotifyEvent = 'ticket_assigned' | 'step_assigned' | 'ticket_created' | 'comment_added' | 'comment_mention' | 'ticket_closed'

/** Was in einer Zeile der Tabelle steht — Anlass oder Kanal. */
export type PrefKey = Channel | NotifyEvent

/**
 * Der **gespeicherte** Stand, nicht der aufgelöste.
 *
 * Ein fehlender Eintrag heißt „hier steht nichts" — und damit „es gilt die
 * globale Einstellung, sonst an". Die Oberfläche muss das unterscheiden
 * können: Ein geerbtes „an" sieht sonst aus wie ein gesetztes, und ein Klick
 * darauf täte nichts Sichtbares.
 */
export interface NotifyPrefs {
	global: Partial<Record<PrefKey, boolean>>
	boards: Record<number, Partial<Record<NotifyEvent, boolean>>>
}

/** Der gespeicherte Stand der eigenen Kanalschalter. */
export async function fetchNotifyPrefs(): Promise<NotifyPrefs> {
	return apiGet<NotifyPrefs>('/notify-prefs')
}

/**
 * Einen Schalter setzen.
 *
 * @param prefKey Kanal oder Anlass.
 * @param enabled Neuer Stand.
 * @param boardId Projekt, oder 0 für global.
 */
export async function setNotifyPref(prefKey: PrefKey, enabled: boolean, boardId = 0): Promise<NotifyPrefs> {
	return apiPut<NotifyPrefs, { prefKey: PrefKey, enabled: boolean, boardId: number }>(
		'/notify-prefs',
		{ prefKey, enabled, boardId },
	)
}

/**
 * Alle Projekt-Ausnahmen wegräumen — danach gilt überall die globale
 * Einstellung.
 */
export async function clearNotifyOverrides(): Promise<NotifyPrefs> {
	return apiDelete<NotifyPrefs>('/notify-prefs/overrides')
}
