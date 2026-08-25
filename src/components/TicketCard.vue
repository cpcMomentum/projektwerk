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
				<!--
					**Der Punkt „seit deinem Blick geändert" (#79).** Er ersetzt den
					früheren blauen Punkt, der an `lastEditorUserId` hing und nie
					ausging. Dieser hängt am Lesestand je Person: nur wenn sich seit
					dem letzten Öffnen etwas getan hat, und er geht beim Öffnen aus.
					Neutrale Farbe, nicht die Warnfarbe der Uhr unten — keine zwei
					konkurrierenden Signale auf einer Karte.
				-->
				<span
					v-if="changed"
					class="pw-changed"
					role="img"
					:title="t('projektwerk', 'Seit Ihrem letzten Blick geändert')"
					:aria-label="t('projektwerk', 'Seit Ihrem letzten Blick geändert')" />
				<span class="pw-num">#{{ paddedNumber }}</span>
				<!--
					Das Abschluss-Ergebnis (#171) direkt auf der Karte — erledigt
					oder verworfen, damit das Board beides unterscheidet, ohne dass
					man erst die Karte öffnet.
				-->
				<span
					v-if="ticket.closedAt"
					class="pw-card__outcome"
					:class="{ 'pw-card__outcome--discarded': ticket.closedOutcome === 'discarded' }">
					{{ closedLabel }}
				</span>
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
					:disableMenu="true"
					:hideStatus="true" />
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
				<!--
					Die Fälligkeit des Vorgangs (#72), überfällig kräftig markiert
					— dasselbe Muster wie am Schritt. „Bis wann ist die Sache
					fertig", die Zusage an die Gegenseite; wirklich in Verzug ist
					sie erst, wenn das Datum gerissen ist.
				-->
				<span
					v-if="ticket.dueDate"
					class="pw-due"
					:class="{ 'pw-due--overdue': overdue }"
					:title="dueTitle">
					<CalendarAlertIcon v-if="overdue" :size="13" />
					<CalendarIcon v-else :size="13" />
					{{ germanDate(ticket.dueDate) }}
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
			class="pw-card__menu"
			:forceMenu="true"
			:ariaLabel="menuLabel">
			<!--
				Abschließen / Wieder öffnen direkt an der Karte (#168) — sichtbar
				auch für die Kundenseite, ohne erst ins Detail zu müssen. Ruft
				dieselbe offen↔geschlossen-Kette wie der Knopf im Detail.

				**Das Ergebnis wählt man mit** (#171): zwei Einträge (erledigt /
				verworfen) statt eines. Das zweite Argument reicht das Ergebnis
				durch; beim Wieder-öffnen bleibt es weg (ein offener Vorgang hat
				keins). Im Menü sind zwei Zeilen nicht eng — anders als zwei Knöpfe
				auf der schmalen Karte.
			-->
			<template v-if="ticket.closedAt === null">
				<NcActionButton
					:closeAfterClick="true"
					@click="$emit('toggleclosed', ticket, 'done')">
					<template #icon>
						<CheckIcon :size="20" />
					</template>
					{{ t('projektwerk', 'Als erledigt abschließen') }}
				</NcActionButton>
				<NcActionButton
					:closeAfterClick="true"
					@click="$emit('toggleclosed', ticket, 'discarded')">
					<template #icon>
						<CancelIcon :size="20" />
					</template>
					{{ t('projektwerk', 'Als verworfen abschließen') }}
				</NcActionButton>
			</template>
			<NcActionButton
				v-else
				:closeAfterClick="true"
				@click="$emit('toggleclosed', ticket)">
				<template #icon>
					<RestoreIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Wieder öffnen') }}
			</NcActionButton>

			<!--
				**Ein Hauptwort, kein Handlungssatz.** „Verschieben nach …" las
				sich wie ein Knopf — und weil Ueberschriften in
				Nextcloud-Menues grau sind, wie ein **gesperrter**. Axel hat
				beim Geraetetest darauf getippt und daraus geschlossen, das
				Verschieben ginge nicht. „Zielspalte" laesst sich nicht fuer
				eine Handlung halten; die Handlung sind die Zeilen darunter.
			-->
			<template v-if="otherColumns.length > 0">
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
			</template>

			<!--
				Löschen als letzter Eintrag (#167) — weich und über den Undo-Toast
				sofort umkehrbar, deshalb ohne schwere Rückfrage.
			-->
			<NcActionButton
				:closeAfterClick="true"
				@click="$emit('delete', ticket)">
				<template #icon>
					<DeleteOutlineIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Löschen') }}
			</NcActionButton>
		</NcActions>
	</article>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column } from '@/types/board'
import type { Ticket, WaitState } from '@/types/ticket'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import CalendarAlertIcon from 'vue-material-design-icons/CalendarAlert.vue'
import CalendarIcon from 'vue-material-design-icons/CalendarOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CommentOutlineIcon from 'vue-material-design-icons/CommentOutline.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import WaitBadge from '@/components/WaitBadge.vue'
import { germanDate, isOverdue } from '@/utils/date'

export default defineComponent({
	name: 'TicketCard',

	components: {
		WaitBadge,
		ArrowRightIcon,
		CalendarIcon,
		CalendarAlertIcon,
		CancelIcon,
		CheckIcon,
		CommentOutlineIcon,
		DeleteOutlineIcon,
		FormatListChecksIcon,
		NcActionButton,
		NcActionCaption,
		NcActions,
		NcAvatar,
		OfficeBuildingIcon,
		PencilIcon,
		RestoreIcon,
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
		/** „Seit deinem Blick geändert" (#79) — der neutrale Punkt oben. */
		changed: { type: Boolean, default: false },
		/** Frisch angelegt und darum gerade kurz hervorgehoben (#165). */
		highlighted: { type: Boolean, default: false },
		/** Aus Sicht der Kundenseite formuliert. */
		fromClientSide: { type: Boolean, default: false },
	},

	emits: ['open', 'move', 'toggleclosed', 'delete'],

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
			// Kurz nach dem Anlegen (#165) — ein vergänglicher Anstrich, den die
			// CSS von selbst ausklingen lässt.
			if (this.highlighted) {
				classes.push('pw-card--neu')
			}
			return classes
		},

		paddedNumber(): string {
			return String(this.ticket.number).padStart(4, '0')
		},

		/**
		 * Das Abschluss-Ergebnis als Wort (#171): erledigt oder verworfen, sonst
		 * das neutrale „Geschlossen" für vor #171 ohne Ergebnis geschlossene
		 * Vorgänge. Nur sichtbar, wenn der Vorgang überhaupt geschlossen ist.
		 */
		closedLabel(): string {
			if (this.ticket.closedOutcome === 'discarded') {
				return t('projektwerk', 'Verworfen')
			}
			if (this.ticket.closedOutcome === 'done') {
				return t('projektwerk', 'Erledigt')
			}

			return t('projektwerk', 'Geschlossen')
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

		/**
		 * Der Vorlesetext des Sprechblasensymbols — die Zahl steht nirgends
		 * sichtbar, nur hier.
		 *
		 * `n()` und nicht `t()` mit Platzhalter: Bei genau einem Kommentar stand
		 * „1 Kommentare".
		 */
		commentTitle(): string {
			return n('projektwerk', '%n Kommentar', '%n Kommentare', this.commentCount)
		},

		/**
		 * Kein `n()` nötig: Die Zeile erscheint erst **ab zwei** Schritten
		 * (siehe die Bedingung in der Vorlage), und die Mehrzahl richtet sich
		 * nach `count`, nicht nach `done`. „1 von 3 Arbeitsschritten erledigt"
		 * ist richtig; „1 von 1" kommt gar nicht vor.
		 */
		stepTitle(): string {
			return t('projektwerk', '{done} von {count} Arbeitsschritten erledigt', {
				done: this.stepsDone,
				count: this.stepCount,
			})
		},

		/** Ist die Fälligkeit gerissen? Ein fehlendes Datum nie (#72). */
		overdue(): boolean {
			return isOverdue(this.ticket.dueDate)
		},

		dueTitle(): string {
			const date = germanDate(this.ticket.dueDate)

			return this.overdue
				? t('projektwerk', 'überfällig seit {date}', { date })
				: t('projektwerk', 'fällig {date}', { date })
		},
	},

	methods: { t, germanDate },
})
</script>
