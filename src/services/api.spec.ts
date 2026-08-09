/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Der Nicht-JSON-Wächter.
 *
 * Der wichtigste Fall ist `guestsForbidden`: Spike S1 hat am 2026-08-07
 * gemessen, dass Nextcloud einen abgewiesenen Gast mit **500 und HTML**
 * beantwortet — nicht mit 403. Ein Wächter, der auf 403 prüft, schwiege genau
 * dann, wenn er gebraucht wird. Deshalb steht dieser Statuscode hier als
 * Testdatum und nicht als Annahme im Code.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const showError = vi.fn()
const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args: unknown[]) => get(...args),
		post: vi.fn(),
		put: vi.fn(),
		patch: vi.fn(),
		delete: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => path,
}))
vi.mock('@/services/toast', () => ({
	showError: (...args: unknown[]) => showError(...args),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

const { apiGet } = await import('@/services/api')

/** Die HTML-Fehlerseite, die ein abgewiesener Gast tatsächlich bekommt. */
const GUEST_ERROR_PAGE
	= '<!DOCTYPE html><html><body>Access to this resource (projektwerk) is forbidden for guests</body></html>'

interface FakeResponse {
	status: number
	headers: Record<string, string>
	data: unknown
}

function resolveWith(response: FakeResponse): void {
	get.mockResolvedValueOnce(response)
}

function rejectWith(response: FakeResponse): void {
	get.mockRejectedValueOnce({ response, message: 'Request failed' })
}

describe('Nicht-JSON-Wächter', () => {
	beforeEach(() => {
		showError.mockClear()
		get.mockReset()
	})

	it('meldet die Freigabeliste bei 500 mit HTML — dem in S1 gemessenen Fall', async () => {
		rejectWith({
			status: 500,
			headers: { 'content-type': 'text/html; charset=UTF-8' },
			data: GUEST_ERROR_PAGE,
		})

		await expect(apiGet('/boards')).rejects.toMatchObject({ notJson: true, status: 500 })
		expect(showError).toHaveBeenCalledOnce()
		expect(showError.mock.calls[0][0]).toContain('Freigabeliste')
	})

	it('hängt nicht am Statuscode — 403 mit HTML meldet dasselbe', async () => {
		rejectWith({
			status: 403,
			headers: { 'content-type': 'text/html' },
			data: GUEST_ERROR_PAGE,
		})

		await expect(apiGet('/boards')).rejects.toMatchObject({ notJson: true })
		expect(showError).toHaveBeenCalledOnce()
	})

	it('erkennt HTML auch ohne aussagekräftigen Inhaltstyp', async () => {
		rejectWith({ status: 500, headers: {}, data: GUEST_ERROR_PAGE })

		await expect(apiGet('/boards')).rejects.toMatchObject({ notJson: true })
		expect(showError).toHaveBeenCalledOnce()
	})

	it('greift auch im Erfolgsfall — 200 mit HTML ist kein Erfolg', async () => {
		resolveWith({
			status: 200,
			headers: { 'content-type': 'text/html' },
			data: GUEST_ERROR_PAGE,
		})

		await expect(apiGet('/boards')).rejects.toMatchObject({ notJson: true })
		expect(showError).toHaveBeenCalledOnce()
	})

	it('meldet genau einmal, nicht zweimal', async () => {
		resolveWith({ status: 200, headers: { 'content-type': 'text/html' }, data: GUEST_ERROR_PAGE })

		await expect(apiGet('/boards')).rejects.toMatchObject({ notJson: true })

		// Der Erfolgspfad wirft einen fertigen ApiError; liefe der noch einmal
		// durch die Fehlerbehandlung, erschiene die Meldung doppelt.
		expect(showError).toHaveBeenCalledTimes(1)
	})
})

describe('normale Antworten', () => {
	beforeEach(() => {
		showError.mockClear()
		get.mockReset()
	})

	it('gibt JSON unverändert zurück und meldet nichts', async () => {
		resolveWith({
			status: 200,
			headers: { 'content-type': 'application/json' },
			data: [{ id: 1, title: 'Projekt' }],
		})

		await expect(apiGet('/boards')).resolves.toEqual([{ id: 1, title: 'Projekt' }])
		expect(showError).not.toHaveBeenCalled()
	})

	it('reicht einen JSON-Fehler durch, ohne die Freigabeliste zu erwähnen', async () => {
		rejectWith({
			status: 404,
			headers: { 'content-type': 'application/json' },
			data: { error: 'Nicht gefunden' },
		})

		await expect(apiGet('/boards/9')).rejects.toMatchObject({
			status: 404,
			message: 'Nicht gefunden',
			notJson: false,
		})
		expect(showError).not.toHaveBeenCalled()
	})

	/**
	 * Ein Netzfehler hat gar keine Antwort. Ihn als Freigabelisten-Fall zu
	 * melden wäre die falsche Fährte — und der Fall tritt bei jedem
	 * Verbindungsabbruch auf, also oft.
	 */
	it('meldet einen Netzfehler nicht als Freigabelisten-Problem', async () => {
		get.mockRejectedValueOnce({ message: 'Network Error' })

		await expect(apiGet('/boards')).rejects.toMatchObject({ status: 0, notJson: false })
		expect(showError).not.toHaveBeenCalled()
	})
})
