/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, markRaw } from 'vue'
import PwSettingsNav from '@/components/PwSettingsNav.vue'

vi.mock(import('@nextcloud/l10n'), async (importOriginal) => ({
	...(await importOriginal()),
	t: (_app: string, text: string) => text,
}))

describe('PwSettingsNav', () => {
	it('zeigt flache Bereiche und meldet die Wahl', async () => {
		const wrapper = mount(PwSettingsNav, {
			props: { sections: [{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }], modelValue: 'a' },
		})

		expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['A', 'B'])
		expect(wrapper.find('button[aria-current="page"]').text()).toBe('A')
		await wrapper.findAll('button')[1].trigger('click')
		expect(wrapper.emitted('update:modelValue')).toEqual([['b']])
	})

	it('gliedert in benannte Gruppen, damit gleichnamige Einträge unterscheidbar bleiben', async () => {
		const wrapper = mount(PwSettingsNav, {
			props: {
				groups: [
					{ key: 'projekt', label: 'Projekt', items: [{ key: 'projekt-archiv', label: 'Archiv' }] },
					{ key: 'board', label: 'Board', items: [{ key: 'board-archiv', label: 'Archiv' }] },
				],
				modelValue: 'board-archiv',
			},
			slots: { 'group-board': '<select id="auswahl" />' },
		})

		const gruppen = wrapper.findAll('[role="group"]')
		expect(gruppen).toHaveLength(2)
		expect(gruppen[1].attributes('aria-labelledby')).toBe('pw-subnav-board')
		expect(wrapper.find('#pw-subnav-board').text()).toBe('Board')
		expect(gruppen[1].find('#auswahl').exists()).toBe(true)
		expect(gruppen[0].find('#auswahl').exists()).toBe(false)
		expect(gruppen[1].find('button').attributes('aria-current')).toBe('page')

		await gruppen[0].find('button').trigger('click')
		expect(wrapper.emitted('update:modelValue')).toEqual([['projekt-archiv']])
	})

	it('zeigt das Symbol eines Bereichs in 18 px vor dem Namen', () => {
		const Symbol = markRaw(defineComponent({ props: ['size'], template: '<span class="symbol" :data-size="size" />' }))
		const wrapper = mount(PwSettingsNav, {
			props: { sections: [{ key: 'a', label: 'A', icon: Symbol }, { key: 'b', label: 'B' }], modelValue: 'a' },
		})

		const [mit, ohne] = wrapper.findAll('button')
		expect(mit.find('.symbol').attributes('data-size')).toBe('18')
		expect(ohne.find('.symbol').exists()).toBe(false)
	})
})
