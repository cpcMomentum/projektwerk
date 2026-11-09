/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { App } from 'vue'
import type { TicketReference } from '@/types/reference'

import { registerWidget } from '@nextcloud/vue/functions/reference'
import { createApp } from 'vue'
import TicketReferenceWidget from './components/TicketReferenceWidget.vue'

const apps = new WeakMap<HTMLElement, App>()

registerWidget(
	'projektwerk-ticket',
	(el, { richObject }) => {
		const app = createApp(TicketReferenceWidget, { ticket: richObject as unknown as TicketReference })
		app.mount(el)
		apps.set(el, app)
	},
	(el) => {
		apps.get(el)?.unmount()
		apps.delete(el)
	},
	{ hasInteractiveView: false },
)
