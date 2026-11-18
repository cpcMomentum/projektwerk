<template>
	<div v-if="editing" class="pw-field pw-eckdaten__deps">
		<label :for="inputId">{{ t('projektwerk', 'Hängt ab von') }}</label>
		<NcSelect
			class="pw-deps__select"
			:inputId="inputId"
			:options="options"
			:modelValue="selected"
			label="label"
			:multiple="true"
			:labelOutside="true"
			:appendToBody="true"
			:disabled="busy"
			:placeholder="t('projektwerk', 'Vorgang suchen …')"
			@update:modelValue="change" />
	</div>

	<div v-else class="pw-deps">
		<span class="pw-deps__label">
			<LinkVariantIcon :size="18" />
			{{ t('projektwerk', 'Hängt ab von') }}
		</span>
		<span v-if="dependsOn.length === 0" class="pw-deps__keine">{{ t('projektwerk', 'keine') }}</span>
		<ul v-else class="pw-deps__liste">
			<li v-for="link in dependsOn" :key="link.id" class="pw-deps__zeile">
				<a
					href="#"
					class="pw-deps__link"
					:class="{ 'pw-deps__link--zu': link.closedOutcome !== null }"
					:title="link.title"
					@click.prevent="$emit('open', link.id)">
					<span class="pw-deps__nummer">#{{ number(link) }}</span>
					<span class="pw-deps__titel">{{ link.title }}</span>
				</a>
				<span class="pw-deps__status">{{ status(link) }}</span>
			</li>
		</ul>
		<template v-if="neededBy.length > 0">
			<span class="pw-deps__label pw-deps__label--folge">{{ t('projektwerk', 'Wird gebraucht für') }}</span>
			<ul class="pw-deps__liste">
				<li v-for="link in neededBy" :key="link.id" class="pw-deps__zeile">
					<a
						href="#"
						class="pw-deps__link"
						:class="{ 'pw-deps__link--zu': link.closedOutcome !== null }"
						@click.prevent="$emit('open', link.id)">
						<span class="pw-deps__nummer">#{{ number(link) }}</span>
						<span class="pw-deps__titel">{{ link.title }}</span>
					</a>
					<span class="pw-deps__status">{{ status(link) }}</span>
				</li>
			</ul>
		</template>
	</div>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column } from '@/types/board'
import type { Ticket, TicketLink, TicketLinks } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import { addDependency, removeDependency } from '@/services/tickets'
import { showFailure } from '@/services/toast'

interface Candidate {
	id: number
	label: string
}

export default defineComponent({
	name: 'DependencyList',

	components: { LinkVariantIcon, NcSelect },

	props: {
		boardId: { type: Number, required: true },
		ticketId: { type: Number, required: true },
		dependsOn: { type: Array as PropType<TicketLink[]>, default: () => [] },
		neededBy: { type: Array as PropType<TicketLink[]>, default: () => [] },
		boardTickets: { type: Array as PropType<Ticket[]>, default: () => [] },
		columns: { type: Array as PropType<Column[]>, default: () => [] },
		editing: { type: Boolean, default: false },
	},

	emits: ['open', 'changed'],

	data() {
		return {
			busy: false,
		}
	},

	computed: {
		inputId(): string {
			return `pw-deps-${this.ticketId}`
		},

		selected(): Candidate[] {
			return this.dependsOn.map((link) => ({ id: link.id, label: this.label(link.number, link.title) }))
		},

		// Gewählte stehen immer zur Auswahl, auch wenn sie nicht unter den geladenen Vorgängen des Boards sind.
		options(): Candidate[] {
			const fromBoard = this.boardTickets
				.filter((ticket) => ticket.id > 0 && ticket.id !== this.ticketId)
				.map((ticket) => ({ id: ticket.id, number: ticket.number, label: this.label(ticket.number, ticket.title) }))
			const ids = new Set(fromBoard.map((option) => option.id))
			const extra = this.dependsOn
				.filter((link) => !ids.has(link.id))
				.map((link) => ({ id: link.id, number: link.number, label: this.label(link.number, link.title) }))

			return [...fromBoard, ...extra]
				.sort((a, b) => a.number - b.number)
				.map(({ id, label }) => ({ id, label }))
		},
	},

	methods: {
		t,

		label(nummer: number, title: string): string {
			return `#${String(nummer).padStart(4, '0')} ${title}`
		},

		number(link: TicketLink): string {
			return String(link.number).padStart(4, '0')
		},

		status(link: TicketLink): string {
			if (link.closedOutcome === 'done') {
				return t('projektwerk', 'Erledigt')
			}
			if (link.closedOutcome === 'discarded') {
				return t('projektwerk', 'Verworfen')
			}

			return this.columns.find((column) => column.id === link.columnId)?.title ?? ''
		},

		async change(value: Candidate[] | Candidate | null): Promise<void> {
			const next = new Set((Array.isArray(value) ? value : value === null ? [] : [value]).map((option) => option.id))
			const current = new Set(this.dependsOn.map((link) => link.id))

			for (const id of next) {
				if (!current.has(id)) {
					await this.write(() => addDependency(this.boardId, this.ticketId, id), t('projektwerk', 'Die Abhängigkeit konnte nicht gespeichert werden.'))
				}
			}
			for (const id of current) {
				if (!next.has(id)) {
					await this.write(() => removeDependency(this.boardId, this.ticketId, id), t('projektwerk', 'Die Abhängigkeit konnte nicht entfernt werden.'))
				}
			}
		},

		async write(run: () => Promise<TicketLinks>, fallback: string): Promise<void> {
			this.busy = true
			try {
				this.$emit('changed', await run())
			} catch (e) {
				showFailure(e, fallback)
			} finally {
				this.busy = false
			}
		},
	},
})
</script>
