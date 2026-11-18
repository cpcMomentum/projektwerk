/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { confirmPassword } from './passwordConfirmation.ts'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const laufzeit = globalThis as { OC?: unknown }

describe('confirmPassword', () => {
	afterEach(() => {
		delete laufzeit.OC
	})

	it('wartet auf die Bestätigung', async () => {
		let bestaetigen = (): void => {}
		laufzeit.OC = { PasswordConfirmation: { requirePasswordConfirmation: (done: () => void) => { bestaetigen = done } } }

		let fertig = false
		const warten = confirmPassword().then(() => { fertig = true })
		await Promise.resolve()
		expect(fertig).toBe(false)

		bestaetigen()
		await warten
		expect(fertig).toBe(true)
	})

	it('bricht mit einer Meldung ab, wenn die Person den Dialog schließt', async () => {
		laufzeit.OC = { PasswordConfirmation: { requirePasswordConfirmation: (_done: () => void, _options: unknown, rejected: () => void) => rejected() } }

		await expect(confirmPassword()).rejects.toThrow('Die Passwortbestätigung wurde abgebrochen.')
	})

	it('läuft ohne den Dialog der Laufzeit weiter', async () => {
		await expect(confirmPassword()).resolves.toBeUndefined()
	})
})
