/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { LIMITS } from '@/utils/limits'

function phpConstant(name: string): number {
	const php = readFileSync(resolve(__dirname, '../../lib/Service/ContentLimits.php'), 'utf8')
	const match = php.match(new RegExp(`const ${name} = (\\d+);`))
	if (match === null) {
		throw new Error(`Konstante ${name} fehlt in ContentLimits.php`)
	}
	return Number(match[1])
}

describe('LIMITS', () => {
	it('stimmt mit den Grenzen des Servers überein', () => {
		expect(LIMITS.ticketTitle).toBe(phpConstant('TICKET_TITLE'))
		expect(LIMITS.stepTitle).toBe(phpConstant('STEP_TITLE'))
	})
})
