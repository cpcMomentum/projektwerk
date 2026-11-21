/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ensureFolder, standardFolderPath } from '@/services/folders'

const request = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { request } }))
vi.mock('@nextcloud/router', () => ({ generateRemoteUrl: (p: string) => 'https://nc.test/remote.php/' + p }))

function httpError(status: number): Error {
	return Object.assign(new Error('Request failed with status code ' + status), { isAxiosError: true, response: { status } })
}

describe('Standardordner', () => {
	beforeEach(() => {
		request.mockReset()
		document.head.setAttribute('data-user', 'alice')
	})

	it.each([
		['11_Projekte/AAF_Nextcloud', 'public', '11_Projekte/AAF_Nextcloud/90_Austausch'],
		['/11_Projekte/AAF_Nextcloud/', 'internal', '11_Projekte/AAF_Nextcloud/91_Tickets_intern'],
	] as const)('standardFolderPath(%s, %s)', (root, slot, expected) => {
		expect(standardFolderPath(root, slot)).toBe(expected)
	})

	it('legt einen fehlenden Ordner an', async () => {
		request.mockResolvedValueOnce({})

		await ensureFolder('Kunde A/90_Austausch')

		expect(request).toHaveBeenCalledTimes(1)
		expect(request.mock.calls[0][0]).toMatchObject({
			method: 'MKCOL',
			url: 'https://nc.test/remote.php/dav/files/alice/Kunde%20A/90_Austausch/',
		})
	})

	it('übernimmt einen vorhandenen Ordner, ohne Fehler und ohne zweiten Ordner', async () => {
		request.mockRejectedValueOnce(httpError(405))

		await expect(ensureFolder('Kunde A/91_Tickets_intern')).resolves.toBeUndefined()
		expect(request).toHaveBeenCalledTimes(1)
	})

	it.each([
		[403, 'Im Projektordner fehlen dir die Schreibrechte, um diesen Ordner anzulegen.'],
		[409, 'Den Projektordner gibt es unter diesem Pfad nicht mehr. Bitte lade die Seite neu.'],
		[507, 'Der Ordner konnte nicht angelegt werden.'],
	])('meldet HTTP %i verständlich statt mit dem Status', async (status, message) => {
		request.mockRejectedValueOnce(httpError(status))

		await expect(ensureFolder('Kunde A/90_Austausch')).rejects.toThrow(message)
	})
})
