<template>
	<NcModal
		v-if="ticket"
		size="large"
		:name="ticket.title"
		@close="$emit('close')">
		<!--
			Die App-Klasse MUSS hier drin stehen, nicht nur aussen an der App.
			NcModal teleportiert seinen Inhalt an den `body`; er haengt damit
			ausserhalb von `.app-projektwerk`, und jede darunter geschachtelte
			Regel geht ins Leere. Ohne diese Zeile faellt das ganze Overlay auf
			Blocksatz zurueck: Name und Firma ohne Umbruch, Flex-Abstaende weg,
			Klickflaechen unter `--default-clickable-area`.
		-->
		<div class="app-projektwerk">
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

				<!-- Die Marke steht ueber dem Titel (§9), hier als ganze Zeile. -->
				<WaitBadge :state="waiting" :fromClientSide="fromClientSide" />

				<h2 class="pw-detail__title">
					{{ ticket.title }}
				</h2>

				<!--
					Im Detail als Satz mit Namen, nicht nur als Marke: Wer wartet,
					ist hier die eigentliche Auskunft.
				-->
				<p v-if="waitingSentence" class="pw-wait__sentence">
					{{ waitingSentence }}
				</p>

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

				<StepList
					:boardId="ticket.boardId"
					:ticketId="ticket.id"
					:steps="steps"
					:members="members"
					@changed="$emit('stepsChanged')" />

				<CommentList
					:boardId="ticket.boardId"
					:ticketId="ticket.id"
					:comments="comments"
					:members="members"
					:viewer="viewer"
					@changed="$emit('commentsChanged')" />

				<!--
					Anhaenge stehen in §9 ebenfalls im Detail — sie kommen mit
					Phase 5, Teil B. Bis dahin steht hier nichts statt einer
					leeren Ueberschrift, die etwas verspricht, das es noch nicht
					gibt.
				-->
			</div>
		</div>
	</NcModal>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column, Member, ViewerInfo } from '@/types/board'
import type { Comment, Step, Ticket, WaitState } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcModal from '@nextcloud/vue/components/NcModal'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CommentList from '@/components/CommentList.vue'
import StepList from '@/components/StepList.vue'
import VisibilityControl from '@/components/VisibilityControl.vue'
import WaitBadge from '@/components/WaitBadge.vue'

export default defineComponent({
	name: 'TicketDetail',

	components: { AccountMultipleIcon, CommentList, NcAvatar, NcModal, OfficeBuildingIcon, PencilIcon, StepList, VisibilityControl, WaitBadge },

	props: {
		ticket: { type: Object as PropType<Ticket | null>, default: null },
		columns: { type: Array as PropType<Column[]>, default: () => [] },
		members: { type: Array as PropType<Member[]>, default: () => [] },
		viewer: { type: Object as PropType<ViewerInfo | null>, default: null },
		orgInternal: { type: String, default: '' },
		orgExternal: { type: String, default: '' },
		/** Nur die interne Seite sieht die Kennzeichnung (§9). */
		showVisibility: { type: Boolean, default: false },
		steps: { type: Array as PropType<Step[]>, default: () => [] },
		comments: { type: Array as PropType<Comment[]>, default: () => [] },
		waiting: { type: Object as PropType<WaitState | null>, default: null },
		/** Aus Sicht der Kundenseite formuliert. */
		fromClientSide: { type: Boolean, default: false },
	},

	emits: ['close', 'changed', 'stepsChanged', 'commentsChanged'],

	computed: {
		paddedNumber(): string {
			return String(this.ticket?.number ?? 0).padStart(4, '0')
		},

		columnTitle(): string {
			return this.columns.find((c) => c.id === this.ticket?.columnId)?.title ?? ''
		},

		/**
		 * Der Satz mit Namen, den §9 im Detail verlangt.
		 *
		 * Aus den Kennungen, die der Server geliefert hat — die Rechnung selbst
		 * bleibt dort. Hier werden nur Namen eingesetzt.
		 */
		waitingSentence(): string {
			const namen = (this.waiting?.userIds ?? []).map((userId) => this.nameOf(userId))
			if (namen.length === 0) {
				return ''
			}

			const liste = namen.join(', ')

			// Aus Kundensicht ist „wartet auf" schief — sie sind die Wartenden.
			// „Liegt bei" sagt dasselbe aus ihrer Lage heraus und nennt, wer
			// von ihnen gemeint ist.
			return this.fromClientSide
				? t('projektwerk', 'Dieser Vorgang liegt bei: {names}', { names: liste })
				: t('projektwerk', 'Dieser Vorgang wartet auf die Kundenseite: {names}', { names: liste })
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
		 * Der vom Server aufgelöste Name, sonst die Kennung.
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
			return this.members.find((m) => m.userId === userId)?.resolvedName ?? userId
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
