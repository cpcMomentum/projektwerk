/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Der Konfliktfall darf nicht als beliebiger Serverfehler durchgehen.
 *
 * Ein 409 heißt: Jemand anders war schneller, und ein zweiter Versuch mit
 * denselben Daten scheitert garantiert wieder. Wer ihn wie jeden anderen
 * Fehler meldet, schickt die Person in genau diese Wiederholung — mit der
 * Servermeldung als einzigem Hinweis, die von einer Fehlfunktion nicht zu
 * unterscheiden ist.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const showError = vi.fn()

vi.mock('@/services/toast', () => ({
	showError: (...args: unknown[]) => showError(...args),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

const { isConflict, reportWriteError } = await import('@/services/writeError')

beforeEach(() => {
	vi.clearAllMocks()
})

describe('reportWriteError', () => {
	it('erkennt den Konflikt und sagt es dem Aufrufer', () => {
		const konflikt = reportWriteError({ status: 409, message: 'Zwischenzeitlich geändert' }, 'egal')

		expect(konflikt).toBe(true)
		expect(showError).toHaveBeenCalledWith('Der Vorgang wurde zwischenzeitlich geändert. Bitte neu laden.')
	})

	it('sagt beim Nachladen etwas anderes als beim Neuladen-Hinweis', () => {
		// Wer den Stand selbst nachlädt, darf nicht zum Neuladen auffordern —
		// die Person suchte sonst nach einem Knopf, den es nicht braucht.
		reportWriteError({ status: 409 }, 'egal', true)

		expect(showError).toHaveBeenCalledWith('Der Vorgang wurde zwischenzeitlich geändert. Der Stand wurde neu geladen.')
	})

	it('reicht die Servermeldung durch, wo es keine ist', () => {
		const konflikt = reportWriteError({ status: 403, message: 'Die Seite gehört der anderen Partei.' }, 'Ersatz')

		expect(konflikt).toBe(false)
		expect(showError).toHaveBeenCalledWith('Die Seite gehört der anderen Partei.')
	})

	it('fällt auf den eigenen Text zurück, wenn der Server schweigt', () => {
		reportWriteError({}, 'Verschieben fehlgeschlagen')

		expect(showError).toHaveBeenCalledWith('Verschieben fehlgeschlagen')
	})

	it('stolpert nicht über einen Fehler, der gar keiner ist', () => {
		// Ein abgebrochener Aufruf wirft manchmal `undefined`; eine Meldung muss
		// trotzdem kommen, sonst verschwindet der Fehlschlag spurlos.
		expect(isConflict(undefined)).toBe(false)
		reportWriteError(undefined, 'Verschieben fehlgeschlagen')

		expect(showError).toHaveBeenCalledWith('Verschieben fehlgeschlagen')
	})
})
