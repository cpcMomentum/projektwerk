/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiPost, apiPut } from './api.ts'
import { confirmPassword } from './passwordConfirmation.ts'
import { saveReplyMailbox, testReplyMailbox } from './replyMailbox.ts'

vi.mock('./api.ts', () => ({ apiGet: vi.fn(), apiPost: vi.fn(), apiPut: vi.fn() }))
vi.mock('./passwordConfirmation.ts', () => ({ confirmPassword: vi.fn() }))

const zugang = { imapHost: 'imap.example.org', imapPort: 993, imapSecurity: 'ssl', imapUser: 'projekte', imapFolder: 'INBOX' }
const aufrufe = [
	['Speichern', () => saveReplyMailbox({ ...zugang, replyEnabled: true, replyAddress: 'projekte@example.org' }), apiPut],
	['Testen', () => testReplyMailbox(zugang), apiPost],
] as const

describe('Antwort-Postfach', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it.each(aufrufe)('%s schickt erst nach der Passwortbestätigung', async (_name, aufruf, senden) => {
		let bestaetigen = (): void => {}
		vi.mocked(confirmPassword).mockReturnValue(new Promise<void>((resolve) => { bestaetigen = resolve }))

		const laeuft = aufruf()
		await Promise.resolve()
		expect(senden).not.toHaveBeenCalled()

		bestaetigen()
		await laeuft
		expect(senden).toHaveBeenCalledOnce()
	})

	it.each(aufrufe)('%s schickt nichts, wenn die Bestätigung abgebrochen wird', async (_name, aufruf, senden) => {
		vi.mocked(confirmPassword).mockRejectedValue(new Error('abgebrochen'))

		await expect(aufruf()).rejects.toThrow('abgebrochen')
		expect(senden).not.toHaveBeenCalled()
	})
})
