/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Ticket } from '@/types/ticket'

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import TicketCard from '@/components/TicketCard.vue'

vi.mock(import('@nextcloud/l10n'), async (importOriginal) => ({
	...(await importOriginal()),
	t: (_app: string, text: string) => text,
	n: (_app: string, _sing: string, plural: string, count: number) => plural.replace('%n', String(count)),
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url: string, params: Record<string, unknown>) => '/index.php' + url.replace('{id}', String(params.id)),
}))

function vorgang(id: number): Ticket {
	return {
		id,
		boardId: 7,
		columnId: 1,
		number: 3,
		title: 'Logo tauschen',
		description: null,
		visibility: 'public',
		creatorUserId: 'anna',
		creatorRole: 'internal',
		responsibleUserId: null,
		dueDate: null,
		closedAt: null,
		closedOutcome: null,
		version: 1,
		lastEditorUserId: null,
		githubIssueNumber: null,
		githubIssueUrl: null,
		createdAt: null,
		updatedAt: null,
	}
}

const open = vi.spyOn(window, 'open').mockImplementation(() => null)

afterEach(() => {
	open.mockClear()
})

describe('TicketCard', () => {
	it('öffnet per Mittelklick den Direktlink in einem neuen Tab', async () => {
		const w = mount(TicketCard, { props: { ticket: vorgang(219489) } })
		await w.find('.pw-card__open').trigger('auxclick', { button: 1 })

		expect(open).toHaveBeenCalledWith(window.location.origin + '/index.php/apps/projektwerk/t/219489', '_blank', 'noopener')
		expect(w.emitted('open')).toBeUndefined()
	})

	it('öffnet per Strg-Klick einen neuen Tab statt des Fensters', async () => {
		const w = mount(TicketCard, { props: { ticket: vorgang(219489) } })
		await w.find('.pw-card__open').trigger('click', { ctrlKey: true })

		expect(open).toHaveBeenCalledTimes(1)
		expect(w.emitted('open')).toBeUndefined()
	})

	it('öffnet beim normalen Klick das Fenster im selben Tab', async () => {
		const w = mount(TicketCard, { props: { ticket: vorgang(219489) } })
		await w.find('.pw-card__open').trigger('click')

		expect(open).not.toHaveBeenCalled()
		expect(w.emitted('open')).toHaveLength(1)
	})

	it('öffnet einen ausstehenden Vorgang ohne Kennung im selben Tab', async () => {
		const w = mount(TicketCard, { props: { ticket: vorgang(-1) } })
		await w.find('.pw-card__open').trigger('auxclick', { button: 1 })
		await w.find('.pw-card__open').trigger('click', { metaKey: true })

		expect(open).not.toHaveBeenCalled()
		expect(w.emitted('open')).toHaveLength(1)
	})
})
