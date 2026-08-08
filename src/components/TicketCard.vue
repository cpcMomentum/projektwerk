<template>
	<article :class="cardClass">
		<button type="button" class="pw-card__open" @click="$emit('open', ticket)">
			<span class="pw-card__top">
				<!--
					Das optische Zeichen für „geändert": kein Text auf der Karte.
					Wer draufzeigt oder das Ticket öffnet, erfährt wer und wann.
				-->
				<span v-if="wasEdited" class="pw-changed" :title="changedTitle" />
				<span class="pw-num">#{{ paddedNumber }}</span>
				<!--
					Die Kennzeichnung gibt es nur für die interne Seite und nur,
					wenn es überhaupt eine Gegenseite gibt (§9).
				-->
				<span v-if="showVisibility" class="pw-vis" :class="'pw-vis--' + ticket.visibility">
					<AccountMultipleIcon v-if="ticket.visibility === 'public'" :size="13" />
					<OfficeBuildingIcon v-else-if="ticket.visibility === 'internal'" :size="13" />
					<PencilIcon v-else :size="13" />
					{{ visibilityLabel }}
				</span>
			</span>

			<span class="pw-card__title">{{ ticket.title }}</span>

			<span class="pw-card__foot">
				<NcAvatar
					v-if="ticket.responsibleUserId"
					:user="ticket.responsibleUserId"
					:displayName="responsibleName"
					:size="22"
					:disableMenu="true" />
				<span class="pw-right">
					<CommentOutlineIcon v-if="commentCount > 0" :size="13" :title="commentTitle" />
					<span v-if="stepCount > 0" class="pw-steps" :title="stepTitle">
						<FormatListChecksIcon :size="13" />
						{{ stepsDone }}/{{ stepCount }}
					</span>
				</span>
			</span>
		</button>

		<!--
			„Verschieben nach …" ist der vorgesehene Weg, nicht die Notlösung
			(§9). Es ruft dasselbe Kommando, das später auch Drag & Drop ruft —
			damit ist die Tastaturbedienung strukturell erfüllt statt
			nachgerüstet.
		-->
		<NcActions
			v-if="otherColumns.length > 0"
			class="pw-card__menu"
			:forceMenu="true"
			:ariaLabel="menuLabel">
			<NcActionCaption :name="t('projektwerk', 'Verschieben nach …')" />
			<NcActionButton
				v-for="column in otherColumns"
				:key="column.id"
				:closeAfterClick="true"
				@click="$emit('move', { ticket, columnId: column.id })">
				<template #icon>
					<ArrowRightIcon :size="20" />
				</template>
				{{ column.title }}
			</NcActionButton>
		</NcActions>
	</article>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column } from '@/types/board'
import type { Ticket } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import CommentOutlineIcon from 'vue-material-design-icons/CommentOutline.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default defineComponent({
	name: 'TicketCard',

	components: {
		AccountMultipleIcon,
		ArrowRightIcon,
		CommentOutlineIcon,
		FormatListChecksIcon,
		NcActionButton,
		NcActionCaption,
		NcActions,
		NcAvatar,
		OfficeBuildingIcon,
		PencilIcon,
	},

	props: {
		ticket: { type: Object as PropType<Ticket>, required: true },
		/** Nur die interne Seite sieht die Sichtbarkeitskennzeichnung (§9). */
		showVisibility: { type: Boolean, default: false },
		responsibleName: { type: String, default: '' },
		/** Alle Spalten des Boards — für „Verschieben nach …". */
		columns: { type: Array as PropType<Column[]>, default: () => [] },
		commentCount: { type: Number, default: 0 },
		stepCount: { type: Number, default: 0 },
		stepsDone: { type: Number, default: 0 },
	},

	emits: ['open', 'move'],

	computed: {
		/** Die Zielspalten — die eigene ist keine. */
		otherColumns(): Column[] {
			return this.columns.filter((column) => column.id !== this.ticket.columnId)
		},

		menuLabel(): string {
			return t('projektwerk', 'Aktionen für {title}', { title: this.ticket.title })
		},

		cardClass(): string[] {
			const classes = ['pw-card']
			// Drei Lautstärken: Balken für öffentlich, gestrichelt für den
			// Entwurf, nichts für intern.
			if (this.showVisibility && this.ticket.visibility === 'public') {
				classes.push('pw-card--public')
			}
			if (this.showVisibility && this.ticket.visibility === 'private') {
				classes.push('pw-card--private')
			}
			if (this.ticket.closedAt !== null) {
				classes.push('pw-card--closed')
			}
			return classes
		},

		paddedNumber(): string {
			return String(this.ticket.number).padStart(4, '0')
		},

		/** `lastEditorUserId` ist null, solange niemand etwas geändert hat. */
		wasEdited(): boolean {
			return this.ticket.lastEditorUserId !== null
		},

		changedTitle(): string {
			return t('projektwerk', 'Geändert von {name}', { name: this.ticket.lastEditorUserId ?? '' })
		},

		visibilityLabel(): string {
			// Benannt nach dem Publikum, nicht nach der Technik (§7).
			if (this.ticket.visibility === 'public') {
				return t('projektwerk', 'Alle Beteiligten')
			}
			if (this.ticket.visibility === 'internal') {
				return t('projektwerk', 'Intern')
			}
			return t('projektwerk', 'Nur ich')
		},

		commentTitle(): string {
			return t('projektwerk', '{count} Kommentare', { count: this.commentCount })
		},

		stepTitle(): string {
			return t('projektwerk', '{done} von {count} Arbeitsschritten erledigt', {
				done: this.stepsDone,
				count: this.stepCount,
			})
		},
	},

	methods: { t },
})
</script>
