<template>
	<article class="pw-pending" :class="'pw-pending--' + entry.payload.visibility">
		<button type="button" class="pw-pending__open" @click="$emit('open', entry)">
			<span class="pw-pending__status" :class="{ 'pw-pending__status--failed': entry.state === 'failed' }">
				{{ entry.state === 'failed'
					? t('projektwerk', 'Nicht gespeichert: {reason}', { reason: entry.error ?? '' })
					: t('projektwerk', 'Wird gespeichert …') }}
			</span>
			<span class="pw-pending__title">{{ entry.payload.title }}</span>
		</button>
		<div v-if="entry.state === 'failed'" class="pw-pending__aktionen">
			<NcButton variant="tertiary" @click="outbox.retry(entry.id)">
				{{ t('projektwerk', 'Erneut versuchen') }}
			</NcButton>
			<NcButton variant="tertiary" @click="outbox.discard(entry.id)">
				{{ t('projektwerk', 'Verwerfen') }}
			</NcButton>
		</div>
	</article>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { TicketEntry } from '@/stores/outboxStore'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useOutboxStore } from '@/stores/outboxStore'

/**
 * Ein Vorgang, der angelegt, aber noch nicht beim Server gespeichert ist; ohne Nummer, ohne Ziehen.
 */
export default defineComponent({
	name: 'PendingTicketCard',

	components: { NcButton },

	props: {
		entry: { type: Object as PropType<TicketEntry>, required: true },
	},

	emits: ['open'],

	setup() {
		return { outbox: useOutboxStore() }
	},

	methods: {
		t,
	},
})
</script>

<style scoped>
.pw-pending {
	background: var(--color-main-background);
	border: 1px dashed var(--color-border-dark);
	border-top: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	padding: 4px;
}

.pw-pending--internal {
	border-top-color: var(--color-warning);
}

.pw-pending--private {
	border-top-color: var(--color-text-maxcontrast);
}

.pw-pending__open {
	all: unset;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	gap: 4px;
	width: 100%;
	min-height: var(--default-clickable-area);
	padding: 8px;
	cursor: pointer;
	border-radius: var(--border-radius);
}

.pw-pending__open:focus-visible {
	outline: 2px solid var(--color-primary-element);
}

.pw-pending__status {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.pw-pending__status--failed {
	color: var(--color-error-text);
}

.pw-pending__title {
	font-weight: 500;
	overflow-wrap: anywhere;
}

.pw-pending__aktionen {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	padding: 0 4px 4px;
}
</style>
