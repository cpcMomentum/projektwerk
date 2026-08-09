<template>
	<div class="pw-view" :style="{ '--pw-columns': store.columns.length || 1 }">
		<div class="pw-view__head">
			<h2>{{ store.board?.title ?? t('projektwerk', 'Projekt') }}</h2>

			<NcButton variant="primary" :disabled="store.columns.length === 0" @click="creating = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Neuer Vorgang') }}
			</NcButton>

			<!-- Ohne hinterlegte Adresse entfaellt der Knopf ersatzlos (§9). -->
			<NcButton
				v-if="store.board?.chatUrl"
				:href="store.board.chatUrl"
				target="_blank"
				rel="noopener">
				{{ t('projektwerk', 'Zum Projektchat') }}
			</NcButton>

			<p v-if="orgLine" class="pw-view__sub">
				{{ orgLine }}
			</p>
		</div>

		<div v-if="store.loading" class="pw-board">
			<div v-for="n in 3" :key="n" class="pw-col">
				<div class="pw-col__head">
&nbsp;
				</div>
				<div class="pw-stack">
					<div class="pw-skel">
						<i /><i /><i />
					</div>
					<div class="pw-skel">
						<i /><i /><i />
					</div>
				</div>
			</div>
		</div>

		<NcEmptyContent
			v-else-if="store.columns.length === 0"
			:name="t('projektwerk', 'Noch keine Spalten')"
			:description="t('projektwerk', 'Legen Sie in den Projekteinstellungen die erste Spalte an.')">
			<template #icon>
				<FolderMultipleIcon :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="pw-board">
			<div v-for="column in store.columns" :key="column.id" class="pw-col">
				<div class="pw-col__head">
					{{ column.title }}
					<span class="pw-n">{{ store.ticketsIn(column.id).length }}</span>
				</div>
				<div class="pw-stack">
					<TicketCard
						v-for="ticket in store.ticketsIn(column.id)"
						:key="ticket.id"
						:ticket="ticket"
						:showVisibility="showVisibility"
						:responsibleName="store.nameOf(ticket.responsibleUserId)"
						:columns="store.columns"
						:lastEditorName="store.nameOf(ticket.lastEditorUserId)"
						:commentCount="count('comments', ticket.id)"
						:stepCount="count('steps', ticket.id)"
						@open="openTicket"
						@move="move" />

					<!-- Leerzustaende sprechen (§9). -->
					<div v-if="store.ticketsIn(column.id).length === 0" class="pw-empty">
						{{ t('projektwerk', 'Hier liegt gerade nichts.') }}
					</div>
				</div>
			</div>
		</div>

		<TicketDetail
			:ticket="openTicketData"
			:columns="store.columns"
			:members="store.members"
			:viewer="store.viewer"
			:orgInternal="store.board?.orgInternal ?? ''"
			:orgExternal="store.board?.orgExternal ?? ''"
			:showVisibility="showVisibility"
			@close="openTicketData = null"
			@changed="applyChanged" />

		<CreateTicketDialog
			:open="creating"
			:columns="store.columns"
			@update:open="creating = $event"
			@create="create" />
	</div>
</template>

<script lang="ts">
import type { Visibility } from '@/types/board'
import type { Ticket } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CreateTicketDialog from '@/components/CreateTicketDialog.vue'
import TicketCard from '@/components/TicketCard.vue'
import TicketDetail from '@/components/TicketDetail.vue'
import { createTicket } from '@/services/tickets'
import { showError } from '@/services/toast'
import { isConflict, reportWriteError } from '@/services/writeError'
import { useBoardStore } from '@/stores/boardStore'

export default defineComponent({
	name: 'BoardView',

	components: { CreateTicketDialog, FolderMultipleIcon, NcButton, NcEmptyContent, PlusIcon, TicketCard, TicketDetail },

	setup() {
		return { store: useBoardStore() }
	},

	data() {
		return { creating: false, openTicketData: null as Ticket | null }
	},

	computed: {
		boardId(): number {
			return Number(this.$route.params.boardId)
		},

		/**
		 * Die Kennzeichnung gibt es nur fuer die interne Seite — und nur, wenn
		 * das Board ueberhaupt externe Mitglieder hat. Sonst gibt es niemanden,
		 * vor dem etwas verborgen waere, und sie waere Rauschen (§9).
		 */
		showVisibility(): boolean {
			return this.store.isInternal && this.store.hasExternalMembers
		},

		orgLine(): string {
			const board = this.store.board
			return board === null ? '' : this.store.orgLine(board)
		},
	},

	watch: {
		boardId: {
			immediate: true,
			handler(id: number) {
				this.store.open(id)
			},
		},
	},

	methods: {
		t,

		count(kind: 'comments' | 'steps', ticketId: number): number {
			return this.store.counts?.[kind]?.[ticketId] ?? 0
		},

		/**
		 * Verschieben über das Kartenmenü: ans Ende der Zielspalte.
		 *
		 * Der Aufrufer nennt keine Position, sondern den letzten Nachbarn dort
		 * — das ist derselbe Weg, den später auch Drag & Drop nimmt.
		 *
		 * @param payload Ticket und Zielspalte.
		 * @param payload.ticket
		 * @param payload.columnId
		 */
		async move(payload: { ticket: Ticket, columnId: number }) {
			const inTarget = this.store.ticketsIn(payload.columnId)
			const last = inTarget.length > 0 ? inTarget[inTarget.length - 1].id : null

			try {
				await this.store.moveTicket(payload.ticket.id, payload.columnId, last, null)
			} catch (e) {
				// Beim Konflikt wird nachgeladen statt zum Neuladen aufgefordert:
				// Hier liegt das ganze Board im Speicher, und ein veralteter
				// `version`-Wert liesse jeden weiteren Versuch scheitern — auch
				// den an einer ganz anderen Karte.
				if (isConflict(e)) {
					await this.store.open(this.boardId)
				}
				reportWriteError(e, t('projektwerk', 'Verschieben fehlgeschlagen'), isConflict(e))
			}
		},

		openTicket(ticket: Ticket) {
			this.openTicketData = ticket
		},

		/**
		 * Ein geändertes Ticket in Karte und Overlay übernehmen.
		 *
		 * Beide Stellen, weil beide denselben Stand zeigen: Das Overlay hält
		 * eine eigene Referenz, die sonst auf dem alten `version`-Wert stünde —
		 * und der nächste Schreibversuch aus dem offenen Overlay liefe damit in
		 * einen 409, obwohl niemand sonst etwas geändert hat.
		 *
		 * @param ticket Der neue Stand vom Server.
		 */
		applyChanged(ticket: Ticket) {
			this.store.replaceTicket(ticket)
			this.openTicketData = ticket
		},

		async create(data: { title: string, description: string | null, visibility: Visibility, columnId: number }) {
			try {
				await createTicket(this.boardId, data)
				this.creating = false
				await this.store.open(this.boardId)
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Anlegen fehlgeschlagen'))
			}
		},
	},
})
</script>
