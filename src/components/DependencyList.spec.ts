/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Ticket, TicketLink } from '@/types/ticket'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DependencyList from '@/components/DependencyList.vue'

const addDependency = vi.fn()
const removeDependency = vi.fn()

vi.mock('@/services/tickets', () => ({
	addDependency: (...args: unknown[]) => addDependency(...args),
	removeDependency: (...args: unknown[]) => removeDependency(...args),
}))
vi.mock('@/services/toast', () => ({ showError: vi.fn(), showFailure: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['options', 'modelValue', 'multiple', 'inputId'],
		emits: ['update:modelValue'],
		template: '<div class="stub-select" :data-multiple="String(multiple)"><span v-for="o in options" :key="o.id" class="stub-option">{{ o.label }}</span></div>',
	},
}))

function link(id: number, number: number, title: string, closedOutcome: TicketLink['closedOutcome'] = null): TicketLink {
	return { id, number, title, columnId: 1, closedOutcome } as TicketLink
}

function ticket(id: number, number: number, title: string): Ticket {
	return { id, number, title } as Ticket
}

const BOARD = [ticket(10, 1, 'Eins'), ticket(11, 2, 'Zwei'), ticket(12, 3, 'Drei')]

function lesen(dependsOn: TicketLink[] = [], neededBy: TicketLink[] = []) {
	return mount(DependencyList, {
		props: { boardId: 7, ticketId: 11, dependsOn, neededBy, boardTickets: BOARD, columns: [{ id: 1, title: 'In Arbeit' }] as never },
	})
}

function bearbeiten(dependsOn: TicketLink[]) {
	return mount(DependencyList, { props: { boardId: 7, ticketId: 11, dependsOn, boardTickets: BOARD, editing: true } })
}

beforeEach(() => {
	vi.clearAllMocks()
	addDependency.mockResolvedValue({ dependsOn: [], neededBy: [] })
	removeDependency.mockResolvedValue({ dependsOn: [], neededBy: [] })
})

describe('DependencyList', () => {
	it('zeigt ohne Abhängigkeit nur „keine“ in einer Zeile', () => {
		const w = lesen()

		expect(w.find('.pw-deps__keine').text()).toBe('keine')
		expect(w.find('.pw-deps__liste').exists()).toBe(false)
	})

	it('zeigt Abhängigkeiten als Links mit Status und öffnet sie', async () => {
		const w = lesen([link(10, 1, 'Eins'), link(12, 3, 'Drei', 'done')])

		expect(w.findAll('.pw-deps__zeile').map((z) => z.text())).toEqual(['#0001EinsIn Arbeit', '#0003DreiErledigt'])
		expect(w.findAll('.pw-deps__link--zu')).toHaveLength(1)

		await w.find('.pw-deps__link').trigger('click')
		expect(w.emitted('open')).toEqual([[10]])
	})

	it('nennt, wofür der Vorgang gebraucht wird', () => {
		const w = lesen([], [link(12, 3, 'Drei')])

		expect(w.find('.pw-deps__label--folge').exists()).toBe(true)
		expect(w.findAll('.pw-deps__zeile')).toHaveLength(1)
	})

	it('bietet beim Bearbeiten alle anderen Vorgänge als Mehrfachauswahl an', () => {
		const w = bearbeiten([link(10, 1, 'Eins')])

		expect(w.find('.stub-select').attributes('data-multiple')).toBe('true')
		expect(w.findAll('.stub-option').map((o) => o.text())).toEqual(['#0001 Eins', '#0003 Drei'])
	})

	it('speichert Hinzufügen und Entfernen sofort und meldet den neuen Stand', async () => {
		const w = bearbeiten([link(10, 1, 'Eins')])

		w.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', [{ id: 12, label: '#0003 Drei' }])
		await flushPromises()

		expect(addDependency).toHaveBeenCalledWith(7, 11, 12)
		expect(removeDependency).toHaveBeenCalledWith(7, 11, 10)
		expect(w.emitted('changed')).toHaveLength(2)
	})
})
