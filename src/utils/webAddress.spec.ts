/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { isWebAddress } from './webAddress.ts'

describe('isWebAddress', () => {
	it.each(['https://chat.example.org/pw', 'http://chat.example.org', 'HTTPS://chat.example.org'])('lässt %s zu', (url) => {
		expect(isWebAddress(url)).toBe(true)
	})

	it.each(['javascript:alert(1)', 'data:text/html,x', '//chat.example.org', 'chat.example.org/pw', 'https://', ' https://chat.example.org', '', null, undefined])('weist %s ab', (url) => {
		expect(isWebAddress(url)).toBe(false)
	})
})
