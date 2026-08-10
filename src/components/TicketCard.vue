<template>
	<article :class="cardClass">
		<button type="button" class="pw-card__open" @click="$emit('open', ticket)">
			<span class="pw-card__top">
				<!--
					**Hier stand ein blauer Punkt für „geändert".** Er erschien,
					sobald `lastEditorUserId` gesetzt war — und ging nie wieder
					aus. Nach ein paar Wochen trug ihn jedes Ticket, und ein
					Zeichen, das alle tragen, unterscheidet nichts mehr. Dafür
					belegte er die erste Stelle der Karte.

					Was er sagen *sollte*, ist „seit deinem letzten Blick
					geändert" — das braucht einen Lesestand pro Benutzer und
					kommt mit dem eigenen Issue. Bis dahin lieber kein Signal
					als ein falsches.
				-->
				<span class="pw-num">#{{ paddedNumber }}</span>
				<!--
					Die Kennzeichnung gibt es nur für die interne Seite und nur,
					wenn es überhaupt eine Gegenseite gibt (§9).

					**Und nur für Abweichungen vom Normalfall.** „Alle
					Beteiligten" stand doppelt da: als farbiger Balken oben UND
					als Wort. Der Balken sagt es bereits. Jetzt gilt: Keine
					Kennzeichnung heisst „sehen alle" — markiert wird, was davon
					abweicht.

					Dass ausgerechnet der Normalfall ohne Wort auskommt und die
					anderen nicht, ist kein Zufall: Die sicherheitskritische
					Frage lautet „ist das intern?", denn davon haengt ab, ob der
					Kunde mitliest. Wer Farben nicht unterscheidet, findet
					weiterhin Woerter dort, wo es zaehlt.
				-->
				<span
					v-if="showVisibility && ticket.visibility !== 'public'"
					class="pw-vis"
					:class="'pw-vis--' + ticket.visibility">
					<OfficeBuildingIcon v-if="ticket.visibility === 'internal'" :size="13" />
					<PencilIcon v-else :size="13" />
					{{ visibilityLabel }}
				</span>

				<!--
					**Wer den Vorgang traegt — leise, oben rechts.** Eine
					Zuordnung, keine Handlungsaufforderung: Sie gilt dauerhaft
					und aendert nichts daran, ob die Karte einen gerade angeht.
					Deshalb klein und in der Kopfzeile, wo auch Nummer und
					Sichtbarkeit stehen.
				-->
				<NcAvatar
					v-if="ticket.responsibleUserId"
					class="pw-card__owner"
					:user="ticket.responsibleUserId"
					:displayName="responsibleName"
					:size="18"
					:disableMenu="true" />
			</span>

			<span class="pw-card__title">{{ ticket.title }}</span>

			<!--
				**Die Zustandszeile — ein Satz, kein Formular.** Oben steht,
				was dauerhaft gilt; hier, was gerade der Fall ist: auf wen es
				wartet, seit wann, bei welchem Schritt.

				Die Zaehler standen bis zum 2026-08-10 per `margin-left: auto`
				am anderen Ende der Zeile und lasen sich als zweite, unabhaengige
				Anzeige. Sie sind aber keine: „0/2" sagt, *wo* es haengt, und
				gehoert damit zur selben Aussage wie das Warten. Jetzt stehen
				sie direkt dahinter, durch einen Mittelpunkt getrennt.

				Alles linksbuendig in einem Fluss — deshalb springt nichts, wenn
				ein Teil fehlt. Die Zeile wird nur kuerzer.
			-->
			<span class="pw-card__foot">
				<WaitBadge
					:state="waitState"
					:fromClientSide="fromClientSide"
					:names="memberNames"
					:compact="true" />
				<span v-if="commentCount > 0 || stepCount > 1" class="pw-counts">
					<CommentOutlineIcon v-if="commentCount > 0" :size="13" :title="commentTitle" />
					<!--
						**Erst ab zwei Schritten.** Bei einem einzigen sagt
						„0/1" dasselbe wie die Wartemarke daneben — zwei
						Anzeigen fuer denselben Sachverhalt. Ab zwei sagt der
						Zaehler etwas Eigenes: wie weit es insgesamt ist.
					-->
					<span v-if="stepCount > 1" class="pw-steps" :title="stepTitle">
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
			<!--
				**Ein Hauptwort, kein Handlungssatz.** „Verschieben nach …" las
				sich wie ein Knopf — und weil Ueberschriften in
				Nextcloud-Menues grau sind, wie ein **gesperrter**. Axel hat
				beim Geraetetest darauf getippt und daraus geschlossen, das
				Verschieben ginge nicht. „Zielspalte" laesst sich nicht fuer
				eine Handlung halten; die Handlung sind die Zeilen darunter.
			-->
			<NcActionCaption :name="t('projektwerk', 'Zielspalte')" />
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
import type { Ticket, WaitState } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import CommentOutlineIcon from 'vue-material-design-icons/CommentOutline.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import WaitBadge from '@/components/WaitBadge.vue'

export default defineComponent({
	name: 'TicketCard',

	components: {
		WaitBadge,
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
		/**
		 * Anzeigename je Benutzerkennung — fuer die Kugeln der Wartemarke.
		 *
		 * Der Server loest die Namen auf (`resolvedName`); im Browser
		 * nachzuschlagen bliebe ausgerechnet beim Gast stumm.
		 */
		memberNames: { type: Object as PropType<Record<string, string>>, default: () => ({}) },
		/** Alle Spalten des Boards — für „Verschieben nach …". */
		columns: { type: Array as PropType<Column[]>, default: () => [] },
		commentCount: { type: Number, default: 0 },
		stepCount: { type: Number, default: 0 },
		stepsDone: { type: Number, default: 0 },
		/** Der gerechnete Wartezustand, oder null. */
		waitState: { type: Object as PropType<WaitState | null>, default: null },
		/** Aus Sicht der Kundenseite formuliert. */
		fromClientSide: { type: Boolean, default: false },
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

		/**
		 * Nur für „Intern" und „Privat" — der Normalfall bleibt unmarkiert.
		 *
		 * „Privat" statt „Nur ich": zwei Eigenschaftswoerter nebeneinander
		 * lesen sich als ein Paar, ein Satzfragment daneben nicht. Im
		 * Auswahlmenue heisst die Stufe weiterhin ausfuehrlicher — dort wird
		 * gewaehlt, hier nur benannt.
		 */
		visibilityLabel(): string {
			return this.ticket.visibility === 'internal'
				? t('projektwerk', 'Intern')
				: t('projektwerk', 'Privat')
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
