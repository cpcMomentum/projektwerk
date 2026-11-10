/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import BoardDragLayer from '@/components/board/BoardDragLayer.vue'

describe('BoardDragLayer', () => {
	it('reicht beim Abschließen das gewählte Ergebnis und das Archivieren an das Board weiter', async () => {
		setActivePinia(createPinia())
		const ticket = { id: 1, columnId: 1, title: 'X', closedAt: null }
		const w = shallowMount(BoardDragLayer, {
			props: { tickets: [ticket], columnId: 1 },
			global: { stubs: { VueDraggable: { template: '<div><slot /></div>' } } },
		})
		const karte = w.findComponent({ name: 'TicketCard' })

		karte.vm.$emit('toggleclosed', ticket, 'discarded')
		karte.vm.$emit('togglearchived', ticket)

		expect(w.emitted('toggleclosed')?.[0]).toEqual([ticket, 'discarded'])
		expect(w.emitted('togglearchived')?.[0]).toEqual([ticket])
	})
})
