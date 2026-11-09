/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { TicketReference } from '@/types/reference'

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TicketReferenceWidget from './TicketReferenceWidget.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars?: Record<string, string>) => vars ? text.replace(/\{(\w+)\}/g, (_m, k) => vars[k] ?? '') : text,
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url: string, params: Record<string, string>) => url.replace('{user}', params.user),
	imagePath: (app: string, file: string) => `/apps/${app}/img/${file}`,
}))

function karte(overrides: Partial<TicketReference> = {}): TicketReference {
	return {
		id: 219489,
		number: '#0001',
		title: 'Logo tauschen',
		project: 'Relaunch',
		board: '',
		column: 'In Arbeit',
		closedOutcome: null,
		responsible: { id: 'anna', name: 'Anna Ahrens' },
		dueDate: null,
		link: 'https://cloud.example/apps/projektwerk/t/219489',
		...overrides,
	}
}

describe('TicketReferenceWidget', () => {
	it('zeigt Nummer, Titel, Projekt, Spalte und Zuständige und verlinkt den Vorgang', () => {
		const w = mount(TicketReferenceWidget, { props: { ticket: karte() } })

		expect(w.text()).toContain('#0001')
		expect(w.text()).toContain('Logo tauschen')
		expect(w.text()).toContain('Relaunch')
		expect(w.find('.pw-ref__status').text()).toBe('In Arbeit')
		expect(w.text()).toContain('Anna Ahrens')
		expect(w.find('a').attributes('href')).toBe('https://cloud.example/apps/projektwerk/t/219489')
	})

	it('nennt bei mehreren Boards Projekt und Board', () => {
		const w = mount(TicketReferenceWidget, { props: { ticket: karte({ board: 'Website' }) } })

		expect(w.find('.pw-ref__projekt').text()).toBe('Relaunch · Website')
	})

	it('zeigt den Abschluss statt der Spalte', () => {
		const w = mount(TicketReferenceWidget, { props: { ticket: karte({ closedOutcome: 'done', column: 'Erledigt-Spalte' }) } })

		expect(w.find('.pw-ref__status').text()).toBe('Erledigt')
		expect(w.find('.pw-ref__status').classes()).toContain('pw-ref__status--done')
	})

	it('markiert eine überfällige Frist nur bei offenen Vorgängen', () => {
		const offen = mount(TicketReferenceWidget, { props: { ticket: karte({ dueDate: '2020-01-01' }) } })
		const zu = mount(TicketReferenceWidget, { props: { ticket: karte({ dueDate: '2020-01-01', closedOutcome: 'discarded' }) } })

		expect(offen.find('.pw-ref__frist--ueber').exists()).toBe(true)
		expect(zu.find('.pw-ref__frist--ueber').exists()).toBe(false)
	})

	it('lässt Person und Frist weg, wenn nichts gesetzt ist', () => {
		const w = mount(TicketReferenceWidget, { props: { ticket: karte({ responsible: null }) } })

		expect(w.find('.pw-ref__person').exists()).toBe(false)
		expect(w.find('.pw-ref__frist').exists()).toBe(false)
	})
})
