<template>
	<NcModal
		v-if="ticket"
		size="large"
		:name="ticket.title"
		@close="$emit('close')">
		<div class="pw-detail">
			<!--
				Kopfzeile von oben nach §9: Nummer, Titel, Spalte,
				Sichtbarkeits-Chip. Die Wartemarke kommt mit Phase 3, wenn es
				Arbeitsschritte gibt, aus denen sie entsteht.
			-->
			<header class="pw-detail__head">
				<span class="pw-num">#{{ paddedNumber }}</span>
				<span class="pw-detail__column">{{ columnTitle }}</span>
				<span v-if="showVisibility" class="pw-vis" :class="'pw-vis--' + ticket.visibility">
					<AccountMultipleIcon v-if="ticket.visibility === 'public'" :size="14" />
					<OfficeBuildingIcon v-else-if="ticket.visibility === 'internal'" :size="14" />
					<PencilIcon v-else :size="14" />
					{{ visibilityLabel }}
				</span>
				<span v-if="ticket.closedAt" class="pw-detail__closed">
					{{ t('projektwerk', 'Geschlossen') }}
				</span>
			</header>

			<h2 class="pw-detail__title">
				{{ ticket.title }}
			</h2>

			<!--
				Sprechender Leerzustand statt einer leeren Flaeche (§9) — er sagt
				auch gleich, was zu tun waere.
			-->
			<p v-if="ticket.description" class="pw-detail__body">
				{{ ticket.description }}
			</p>
			<p v-else class="pw-detail__empty">
				{{ t('projektwerk', 'Keine Beschreibung hinterlegt.') }}
			</p>

			<section class="pw-detail__section">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Personen') }}
				</h3>

				<div class="pw-person">
					<NcAvatar
						:user="ticket.creatorUserId"
						:displayName="nameOf(ticket.creatorUserId)"
						:size="32"
						:disableMenu="true" />
					<span class="pw-person__body">
						<span class="pw-person__name">{{ nameOf(ticket.creatorUserId) }}</span>
						<!--
							Die Firma steht unter JEDEM Namen, auch unter den
							internen — sonst waere die eine Seite stumm „der
							Normalfall".
						-->
						<span class="pw-person__org">{{ orgLine(ticket.creatorRole, t('projektwerk', 'angelegt')) }}</span>
					</span>
				</div>

				<div v-if="ticket.responsibleUserId" class="pw-person">
					<NcAvatar
						:user="ticket.responsibleUserId"
						:displayName="nameOf(ticket.responsibleUserId)"
						:size="32"
						:disableMenu="true" />
					<span class="pw-person__body">
						<span class="pw-person__name">{{ nameOf(ticket.responsibleUserId) }}</span>
						<span class="pw-person__org">{{ orgLine(roleOf(ticket.responsibleUserId), t('projektwerk', 'zuständig')) }}</span>
					</span>
				</div>
			</section>

			<!--
				Der Bereich zeigt sich nur der besitzenden Seite und blendet
				sich sonst selbst aus (§7). Er haengt bewusst NICHT an
				`showVisibility`: Das ist die Kennzeichnung fuer interne
				Betrachter — waere der Knopf daran gebunden, koennte die
				Kundenseite die Sichtbarkeit ihrer eigenen Vorgaenge nie aendern.
			-->
			<VisibilityControl
				:ticket="ticket"
				:viewer="viewer"
				:members="members"
				@changed="$emit('changed', $event)" />

			<!--
				Arbeitsschritte, Anhaenge und Kommentare stehen in §9 ebenfalls
				im Detail — sie kommen mit den Phasen 3 und 5. Bis dahin steht
				hier nichts statt einer leeren Ueberschrift, die etwas
				verspricht, das es noch nicht gibt.
			-->
		</div>
	</NcModal>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column, Member, ViewerInfo } from '@/types/board'
import type { Ticket } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcModal from '@nextcloud/vue/components/NcModal'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import VisibilityControl from '@/components/VisibilityControl.vue'

export default defineComponent({
	name: 'TicketDetail',

	components: { AccountMultipleIcon, NcAvatar, NcModal, OfficeBuildingIcon, PencilIcon, VisibilityControl },

	props: {
		ticket: { type: Object as PropType<Ticket | null>, default: null },
		columns: { type: Array as PropType<Column[]>, default: () => [] },
		members: { type: Array as PropType<Member[]>, default: () => [] },
		viewer: { type: Object as PropType<ViewerInfo | null>, default: null },
		orgInternal: { type: String, default: '' },
		orgExternal: { type: String, default: '' },
		/** Nur die interne Seite sieht die Kennzeichnung (§9). */
		showVisibility: { type: Boolean, default: false },
	},

	emits: ['close', 'changed'],

	computed: {
		paddedNumber(): string {
			return String(this.ticket?.number ?? 0).padStart(4, '0')
		},

		columnTitle(): string {
			return this.columns.find((c) => c.id === this.ticket?.columnId)?.title ?? ''
		},

		visibilityLabel(): string {
			if (this.ticket?.visibility === 'public') {
				return t('projektwerk', 'Alle Beteiligten')
			}
			if (this.ticket?.visibility === 'internal') {
				return t('projektwerk', 'Intern')
			}
			return t('projektwerk', 'Nur ich')
		},
	},

	methods: {
		t,

		/**
		 * Der Name für dieses Board, sonst die Kennung.
		 *
		 * Nie nur die Kennung, wo ein Name da ist: Bei einem Gastkonto stünde
		 * dort sonst ein 64-stelliger Hash.
		 *
		 * @param userId Kennung der Person.
		 */
		nameOf(userId: string | null): string {
			if (userId === null) {
				return ''
			}
			return this.members.find((m) => m.userId === userId)?.displayName ?? userId
		},

		/**
		 * @param userId Kennung der Person.
		 */
		roleOf(userId: string | null): string {
			return this.members.find((m) => m.userId === userId)?.role ?? 'internal'
		},

		/**
		 * @param role Rolle der Person auf diesem Board.
		 * @param suffix Was diese Person hier getan hat.
		 */
		orgLine(role: string, suffix: string): string {
			const org = role === 'internal' ? this.orgInternal : this.orgExternal
			return org === '' ? suffix : org + ' · ' + suffix
		},
	},
})
</script>
