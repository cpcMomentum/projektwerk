<!--
  - SPDX-FileCopyrightText: 2026 cpcMomentum
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<a
		class="pw-ref"
		:href="ticket.link"
		target="_blank"
		rel="noopener noreferrer">
		<span class="pw-ref__kopf">
			<img class="pw-ref__icon" :src="iconUrl" alt="">
			<span class="pw-ref__num">{{ ticket.number }}</span>
			<span class="pw-ref__projekt">{{ projectLine }}</span>
		</span>
		<span class="pw-ref__titel">{{ ticket.title }}</span>
		<span class="pw-ref__fuss">
			<span class="pw-ref__status" :class="statusClass">{{ status }}</span>
			<span v-if="ticket.responsible" class="pw-ref__person">
				<img class="pw-ref__avatar" :src="avatarUrl" alt="">
				{{ ticket.responsible.name }}
			</span>
			<span v-if="dueLabel" class="pw-ref__frist" :class="{ 'pw-ref__frist--ueber': overdue }">
				{{ dueLabel }}
			</span>
		</span>
	</a>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { TicketReference } from '@/types/reference'

import { t } from '@nextcloud/l10n'
import { generateUrl, imagePath } from '@nextcloud/router'
import { defineComponent } from 'vue'
import { asDate, isOverdue } from '@/utils/date'

export default defineComponent({
	name: 'TicketReferenceWidget',

	props: {
		ticket: { type: Object as PropType<TicketReference>, required: true },
	},

	computed: {
		iconUrl(): string {
			return imagePath('projektwerk', 'app-dark.svg')
		},

		avatarUrl(): string {
			return generateUrl('/avatar/{user}/40', { user: this.ticket.responsible?.id ?? '' })
		},

		projectLine(): string {
			return this.ticket.board ? `${this.ticket.project} · ${this.ticket.board}` : this.ticket.project
		},

		status(): string {
			if (this.ticket.closedOutcome === 'done') {
				return t('projektwerk', 'Erledigt')
			}
			if (this.ticket.closedOutcome === 'discarded') {
				return t('projektwerk', 'Verworfen')
			}
			return this.ticket.column
		},

		statusClass(): string {
			return this.ticket.closedOutcome ? `pw-ref__status--${this.ticket.closedOutcome}` : ''
		},

		overdue(): boolean {
			return this.ticket.closedOutcome === null && isOverdue(this.ticket.dueDate)
		},

		dueLabel(): string {
			const date = asDate(this.ticket.dueDate)
			if (date === null) {
				return ''
			}
			return t('projektwerk', 'fällig {date}', { date: date.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric' }) })
		},
	},
})
</script>

<style scoped>
.pw-ref {
	display: flex;
	flex-direction: column;
	gap: 6px;
	width: 100%;
	padding: 12px 14px;
	color: var(--color-main-text);
	text-decoration: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 1px 2px rgba(0, 0, 0, .06), 0 1px 1px rgba(0, 0, 0, .04);
}

.pw-ref:hover,
.pw-ref:focus-visible {
	box-shadow: 0 2px 4px rgba(0, 0, 0, .1), 0 4px 12px rgba(0, 0, 0, .06);
}

.pw-ref__kopf {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.pw-ref__icon {
	width: 16px;
	height: 16px;
	flex: 0 0 auto;
}

.pw-ref__num {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

.pw-ref__projekt {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pw-ref__titel {
	font-size: 15px;
	font-weight: 600;
	line-height: 1.35;
	overflow-wrap: anywhere;
}

.pw-ref__fuss {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.pw-ref__status {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.pw-ref__status--done {
	background: var(--color-success);
	color: var(--color-success-text);
}

.pw-ref__status--discarded {
	text-decoration: line-through;
}

.pw-ref__person {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.pw-ref__avatar {
	width: 20px;
	height: 20px;
	border-radius: 50%;
}

.pw-ref__frist--ueber {
	color: var(--color-error-text);
	font-weight: 600;
}
</style>
