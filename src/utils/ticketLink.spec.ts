/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { ticketLink } from './ticketLink.ts'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url: string, params: Record<string, unknown>) => '/nextcloud/index.php' + url.replace('{id}', String(params.id)),
}))

describe('ticketLink', () => {
	it('baut den absoluten, fragmentfreien Link über die Kennung', () => {
		expect(ticketLink(219489)).toBe(window.location.origin + '/nextcloud/index.php/apps/projektwerk/t/219489')
	})

	it('enthält kein Fragment, das beim Login-Umweg verloren ginge', () => {
		expect(ticketLink(7)).not.toContain('#')
	})
})
