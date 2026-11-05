/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Eine zufällige Kennung; `crypto.randomUUID` gibt es nur in sicheren Kontexten.
 */
export function newId(): string {
	if (typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	const bytes = new Uint8Array(16)
	crypto.getRandomValues(bytes)

	return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}
