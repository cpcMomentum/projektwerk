/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { lastBoards, rememberLastBoard } from '@/services/lastBoard'

describe('Zuletzt genutztes Board', () => {
	let speicher: Record<string, string>

	beforeEach(() => {
		speicher = {}
		vi.stubGlobal('localStorage', {
			getItem: (k: string) => speicher[k] ?? null,
			setItem: (k: string, v: string) => {
				speicher[k] = v
			},
		})
	})

	afterEach(() => vi.unstubAllGlobals())

	it('merkt sich je Projekt das zuletzt geöffnete Board', () => {
		rememberLastBoard(1, 5)
		rememberLastBoard(2, 9)
		rememberLastBoard(1, 6)

		expect(lastBoards()[1]).toBe(6)
		expect(lastBoards()[2]).toBe(9)
		expect(lastBoards()[3]).toBeUndefined()
	})

	it('liefert null bei kaputtem Inhalt und übersteht einen gesperrten Speicher', () => {
		speicher['projektwerk.lastBoard'] = '{kaputt'
		expect(lastBoards()[1]).toBeUndefined()

		vi.stubGlobal('localStorage', {
			getItem: () => {
				throw new Error('gesperrt')
			},
			setItem: () => {
				throw new Error('gesperrt')
			},
		})
		expect(() => rememberLastBoard(1, 5)).not.toThrow()
		expect(lastBoards()[1]).toBeUndefined()
	})
})
