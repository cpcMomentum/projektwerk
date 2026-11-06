<template>
	<section class="pw-abschnitt pw-deps">
		<div class="pw-abschnitt__kopf">
			<h3>{{ t('projektwerk', 'Hängt ab von') }}</h3>
			<span v-if="dependsOn.length > 0" class="pw-abschnitt__zaehler">{{ dependsOn.length }}</span>

			<NcButton
				v-if="!choosing"
				variant="tertiary"
				class="pw-abschnitt__aktion"
				:disabled="busy || candidates.length === 0"
				@click="startChoosing">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Abhängigkeit hinzufügen') }}
			</NcButton>
		</div>

		<div v-if="choosing" class="pw-deps__auswahl">
			<NcSelect
				ref="auswahl"
				class="pw-deps__select"
				:options="candidates"
				label="label"
				:inputLabel="t('projektwerk', 'Vorgang, auf den dieser wartet')"
				:labelOutside="false"
				:placeholder="t('projektwerk', 'Vorgang suchen …')"
				:disabled="busy"
				:appendToBody="true"
				@update:modelValue="add" />
			<NcButton
				variant="tertiary"
				:ariaLabel="t('projektwerk', 'Abbrechen')"
				@click="choosing = false">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<p v-if="dependsOn.length === 0 && !choosing" class="pw-detail__empty">
			{{ t('projektwerk', 'Wartet auf keinen anderen Vorgang.') }}
		</p>

		<ul v-else class="pw-deps__liste">
			<li v-for="link in dependsOn" :key="link.id" class="pw-deps__zeile">
				<a
					href="#"
					class="pw-deps__link"
					:class="{ 'pw-deps__link--zu': link.closedOutcome !== null }"
					@click.prevent="$emit('open', link.id)">
					<span class="pw-deps__nummer">#{{ number(link) }}</span>
					<span class="pw-deps__titel">{{ link.title }}</span>
				</a>
				<span class="pw-deps__status">{{ status(link) }}</span>
				<NcButton
					variant="tertiary"
					:ariaLabel="t('projektwerk', 'Abhängigkeit von #{number} entfernen', { number: number(link) })"
					:disabled="busy"
					@click="remove(link)">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>

		<template v-if="neededBy.length > 0">
			<h4 class="pw-deps__unterkopf">
				{{ t('projektwerk', 'Wird gebraucht für') }}
			</h4>
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
	</section>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column } from '@/types/board'
import type { Ticket, TicketLink, TicketLinks } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { addDependency, removeDependency } from '@/services/tickets'
import { showError } from '@/services/toast'

interface Candidate {
	id: number
	label: string
}

export default defineComponent({
	name: 'DependencyList',

	components: { CloseIcon, NcButton, NcSelect, PlusIcon },

	props: {
		boardId: { type: Number, required: true },
		ticketId: { type: Number, required: true },
		dependsOn: { type: Array as PropType<TicketLink[]>, default: () => [] },
		neededBy: { type: Array as PropType<TicketLink[]>, default: () => [] },
		/** Die geladenen Vorgänge des Boards; daraus wird gewählt. */
		boardTickets: { type: Array as PropType<Ticket[]>, default: () => [] },
		columns: { type: Array as PropType<Column[]>, default: () => [] },
	},

	emits: ['open', 'changed'],

	data() {
		return {
			choosing: false,
			busy: false,
		}
	},

	computed: {
		candidates(): Candidate[] {
			const taken = new Set([this.ticketId, ...this.dependsOn.map((link) => link.id)])

			return this.boardTickets
				.filter((ticket) => ticket.id > 0 && !taken.has(ticket.id))
				.sort((a, b) => a.number - b.number)
				.map((ticket) => ({ id: ticket.id, label: `#${String(ticket.number).padStart(4, '0')} ${ticket.title}` }))
		},
	},

	watch: {
		ticketId() {
			this.choosing = false
		},
	},

	methods: {
		t,

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

		startChoosing(): void {
			this.choosing = true
			this.$nextTick(() => {
				const auswahl = this.$refs.auswahl as { $el?: HTMLElement } | undefined
				auswahl?.$el?.querySelector('input')?.focus()
			})
		},

		async add(choice: Candidate | null): Promise<void> {
			if (choice === null) {
				return
			}
			await this.write(() => addDependency(this.boardId, this.ticketId, choice.id), t('projektwerk', 'Die Abhängigkeit konnte nicht gespeichert werden.'))
			this.choosing = false
		},

		async remove(link: TicketLink): Promise<void> {
			await this.write(() => removeDependency(this.boardId, this.ticketId, link.id), t('projektwerk', 'Die Abhängigkeit konnte nicht entfernt werden.'))
		},

		async write(run: () => Promise<TicketLinks>, fallback: string): Promise<void> {
			this.busy = true
			try {
				this.$emit('changed', await run())
			} catch (e) {
				showError((e as { message?: string }).message ?? fallback)
			} finally {
				this.busy = false
			}
		},
	},
})
</script>
