/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { showFailure } from '@/services/toast'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const error = vi.fn()

describe('showFailure', () => {
	beforeEach(() => {
		error.mockReset()
		vi.stubGlobal('OCP', { Toast: { error } })
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('an einer Grenze: Meldung mit Link „Mit WerkPlus freischalten“, nur app=projektwerk, neuer Tab ohne Referer', () => {
		showFailure({ message: 'Im freien Umfang sind 10 aktive Projekte enthalten.', data: { werkplusLimit: true } }, 'Fallback')

		const [html, options] = error.mock.calls[0]
		expect(options).toMatchObject({ isHTML: true })
		expect(html).toContain('Im freien Umfang sind 10 aktive Projekte enthalten.')
		expect(html).toMatch(/<a href="https:\/\/werkwolke\.de\/preis\?app=projektwerk" target="_blank" rel="noopener noreferrer"[^>]*>Mit WerkPlus freischalten<\/a>/)
	})

	it('die Servermeldung landet escaped im HTML', () => {
		showFailure({ message: '<img src=x onerror=alert(1)>', data: { werkplusLimit: true } }, 'Fallback')

		expect(error.mock.calls[0][0]).toContain('&lt;img src=x onerror=alert(1)&gt;')
		expect(error.mock.calls[0][0]).not.toContain('<img')
	})

	it('ein 402 ohne Flag bekommt den Link trotzdem', () => {
		showFailure({ status: 402, message: 'Eine künftige Grenze.', data: {} }, 'Fallback')

		expect(error.mock.calls[0][0]).toContain('Mit WerkPlus freischalten')
	})

	it('ohne Grenze: reine Textmeldung ohne Link', () => {
		showFailure({ message: 'Speichern fehlgeschlagen.', data: {} }, 'Fallback')

		expect(error).toHaveBeenCalledWith('Speichern fehlgeschlagen.')
	})

	it('ohne Servermeldung der eigene Text', () => {
		showFailure(undefined, 'Fallback')

		expect(error).toHaveBeenCalledWith('Fallback')
	})
})
