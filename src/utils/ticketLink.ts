/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'

/**
 * Der absolute Direktlink auf einen Vorgang; er trägt die Kennung, weil die angezeigte Nummer nur je Projekt eindeutig ist.
 *
 * @param ticketId Kennung des Vorgangs, nicht seine Nummer.
 */
export function ticketLink(ticketId: number): string {
	return window.location.origin + generateUrl('/apps/projektwerk/t/{id}', { id: ticketId })
}
