/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Ob die Adresse als Link gezeigt werden darf; Vue prüft ein gebundenes `href` nicht selbst.
 *
 * @param url Gespeicherte Adresse.
 */
export function isWebAddress(url: string | null | undefined): boolean {
	return /^https?:\/\/[^\s/]/i.test(url ?? '')
}
