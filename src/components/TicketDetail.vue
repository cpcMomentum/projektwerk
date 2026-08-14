<template>
	<!--
		**`:labelId` statt `:name`** (#99). `:name` rendert ein zweites `h2` in
		einer Leiste, die `NcModal` absolut an den Rand des Overlays haengt — der
		Titel stand damit zweimal da: als weisse Schrift ueber dem Hintergrund und
		als Ueberschrift in der Karte. `labelId` benennt den Dialog ueber die
		Ueberschrift, die ohnehin da ist.
	-->
	<NcModal
		v-if="ticket"
		size="large"
		:labelId="titleId"
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
					Der Kopfbereich klebt beim Scrollen: Nummer, Titel und
					Sichtbarkeit bleiben stehen, waehrend man durch lange Kommentare
					faehrt. Er kostet dafuer dauerhaft rund 62 px Lesehoehe —
					Axels Entscheidung vom 2026-08-12.
				-->
				<header class="pw-kopf">
					<!--
						Nummer, Spaltenname und Sichtbarkeit auf **einer** Zeile und
						in **gleicher Groesse** (15 px statt 13): Fuer Axel haben die
						drei dieselbe Wertigkeit, und das soll man sehen. Der
						Schalter bleibt bei 34 px — flacher waere unter
						`--default-clickable-area`, und er ist der einzige Weg, die
						Sichtbarkeit zu aendern.

						Die CSS haelt rechts 42 px frei: Dort setzt `NcModal` seinen
						Schliessen-Knopf absolut in die Karte.
					-->
					<div class="pw-meta">
						<span class="pw-num">#{{ paddedNumber }}</span>
						<span class="pw-meta__sep" aria-hidden="true">·</span>
						<span class="pw-meta__column">{{ columnTitle }}</span>
						<span v-if="ticket.closedAt" class="pw-meta__closed">
							{{ t('projektwerk', 'Geschlossen') }}
						</span>

						<!--
							Der Schalter zeigt sich nur der besitzenden Seite und
							blendet sich sonst selbst aus (§7); fuer alle anderen
							steht dort der Chip, sofern die Kennzeichnung fuer sie
							ueberhaupt gilt. Beides entscheidet die Komponente
							selbst — die Frage „wer darf aendern" gehoert an genau
							eine Stelle.
						-->
						<VisibilityControl
							:ticket="ticket"
							:viewer="viewer"
							:showChip="showVisibility"
							@changed="$emit('changed', $event)" />
					</div>

					<h2 :id="titleId" class="pw-detail__title">
						{{ ticket.title }}
					</h2>

					<!--
						Die Marke steht ueber dem Titel (§9), hier als ganze Zeile.

						**`:names` ist der eigentliche Fehler aus #104.** Ohne die
						Zuordnung faellt `WaitBadge` auf die rohe Kennung zurueck und
						schrieb „wartet auf pw-carla, pw-dirk". Auf der Karte fiel es
						nicht auf, weil die kompakte Fassung Avatare zeigt und den Satz
						nur im `title` fuehrt; im Vorgang stand er sichtbar da.

						**Der Satz darunter ist entfallen** (Variante A, Axel
						2026-08-13). Er sagte dasselbe ein zweites Mal — einmal kaputt,
						einmal richtig. Die Marke ist die informativere von beiden: Sie
						traegt die Uhr als einziges farbiges Zeichen, das Datum, und mit
						#72 wird dieselbe Uhr rot. Welcher Seite die Person angehoert,
						beantwortet der Personen-Block zwei Zeilen weiter.
					-->
					<WaitBadge :state="waiting" :fromClientSide="fromClientSide" :names="memberNames" />
				</header>

				<!--
					**Die Beschreibung ist das Hauptbeschreibungsmittel** (#99) und
					damit kein blosser Absatz mehr:

					- **Markdown wie bei den Kommentaren.** Absaetze, Listen, Links.
					  Damit scheidet der einfache Klick zum Bearbeiten aus — er
					  zerstoerte jede Textauswahl und machte Links unklickbar. Der
					  Stift ist der Hauptweg, der Doppelklick die Abkuerzung.
					- **Auf 150 px gedeckelt**, sonst schoebe ein langer Text alles
					  Uebrige um mehrere hundert Pixel nach unten. Bewusst ueber
					  `max-height` und nicht ueber `-webkit-line-clamp`: Mit
					  `useExtendedMarkdown` sind Tabellen moeglich, und ein Clamp
					  greift auf `display: table` nicht — er versagte genau bei dem
					  Inhalt, fuer den der Deckel da ist.
				-->
				<div class="pw-beschreibung">
					<template v-if="!editingText">
						<div
							ref="textbereich"
							class="pw-beschreibung__text"
							:class="{ 'pw-beschreibung__text--offen': textOffen }"
							@dblclick="startEditText">
							<NcRichText
								v-if="ticket.description"
								:text="ticket.description"
								:useMarkdown="true"
								:useExtendedMarkdown="true"
								:interactive="false" />
							<!--
								Sprechender Leerzustand (§9) — und er bietet gleich
								den Weg an, statt nur festzustellen, dass nichts da
								ist.
							-->
							<NcButton v-else variant="tertiary" @click="startEditText">
								<template #icon>
									<PlusIcon :size="20" />
								</template>
								{{ t('projektwerk', 'Beschreibung hinzufügen') }}
							</NcButton>
							<span class="pw-beschreibung__deckel" aria-hidden="true" />
						</div>

						<NcButton
							v-if="textZuHoch"
							variant="tertiary"
							class="pw-beschreibung__mehr"
							@click="textOffen = !textOffen">
							<template #icon>
								<ChevronUpIcon v-if="textOffen" :size="20" />
								<ChevronDownIcon v-else :size="20" />
							</template>
							{{ textOffen ? t('projektwerk', 'Weniger anzeigen') : t('projektwerk', 'Mehr anzeigen') }}
						</NcButton>

						<NcButton
							v-if="ticket.description"
							variant="tertiary"
							class="pw-beschreibung__stift"
							:ariaLabel="t('projektwerk', 'Beschreibung bearbeiten')"
							@click="startEditText">
							<template #icon>
								<PencilOutlineIcon :size="20" />
							</template>
						</NcButton>
					</template>

					<div v-else class="pw-beschreibung__edit">
						<NcTextArea
							v-model="textEntwurf"
							:label="t('projektwerk', 'Beschreibung')"
							:disabled="busy"
							resize="vertical"
							@keydown.esc.stop="cancelEditText"
							@keydown.enter.ctrl.exact="saveText"
							@keydown.enter.meta.exact="saveText" />
						<div class="pw-beschreibung__actions">
							<NcButton :disabled="busy" @click="cancelEditText">
								{{ t('projektwerk', 'Abbrechen') }}
							</NcButton>
							<NcButton variant="primary" :disabled="busy || !textGeaendert" @click="saveText">
								{{ t('projektwerk', 'Speichern') }}
							</NcButton>
						</div>
					</div>
				</div>

				<!--
					Zwei Personen, nebeneinander in einer Zeile. Mehr koennen es
					nicht werden: Nach der Entscheidung zu #97 traegt der Vorgang
					genau zwei Rollen — die anlegende Person und die zustaendige.
					Zugearbeitet wird ueber die Arbeitsschritte.

					Die sichtbare Ueberschrift entfaellt (#99); zwei Namen mit
					Avatar erklaeren sich. Fuer Screenreader bleibt sie stehen.
				-->
				<div class="pw-personen">
					<h3 class="hidden-visually">
						{{ t('projektwerk', 'Personen') }}
					</h3>

					<div class="pw-person">
						<NcAvatar
							:user="ticket.creatorUserId"
							:displayName="nameOf(ticket.creatorUserId)"
							:size="32"
							:disableMenu="true"
							:hideStatus="true" />
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

					<!--
						**Die Zustaendigkeit war bis #97 nirgends setzbar.** Server
						und Dienst konnten sie von Anfang an, nur fuehrte kein Weg
						der Oberflaeche dorthin — und der bereits gebaute Ausloeser
						`EVENT_TICKET_ASSIGNED` blieb damit unerreichbar.

						Dasselbe Muster wie bei den Arbeitsschritten: Wo etwas
						steht, steht Text; wo nichts steht, steht ein flacher Knopf.
					-->
					<div class="pw-person">
						<template v-if="editingResponsible">
							<label class="hidden-visually" :for="responsibleInputId">
								{{ t('projektwerk', 'Zuständig') }}
							</label>
							<NcSelectUsers
								class="pw-person__picker"
								:options="assignableOptions"
								:modelValue="responsibleOption"
								:inputId="responsibleInputId"
								:labelOutside="true"
								:disabled="busy"
								:placeholder="t('projektwerk', 'Niemand')"
								@update:modelValue="setResponsible" />
							<NcButton
								variant="tertiary"
								:ariaLabel="t('projektwerk', 'Abbrechen')"
								@click="editingResponsible = false">
								<template #icon>
									<CloseIcon :size="20" />
								</template>
							</NcButton>
						</template>

						<template v-else-if="ticket.responsibleUserId">
							<NcAvatar
								:user="ticket.responsibleUserId"
								:displayName="nameOf(ticket.responsibleUserId)"
								:size="32"
								:disableMenu="true"
								:hideStatus="true" />
							<span class="pw-person__body">
								<span class="pw-person__name">{{ nameOf(ticket.responsibleUserId) }}</span>
								<span class="pw-person__org">{{ orgLine(roleOf(ticket.responsibleUserId), t('projektwerk', 'zuständig')) }}</span>
							</span>
							<NcButton
								variant="tertiary"
								:ariaLabel="t('projektwerk', 'Zuständigkeit ändern')"
								@click="startEditResponsible">
								<template #icon>
									<PencilOutlineIcon :size="20" />
								</template>
							</NcButton>
						</template>

						<NcButton
							v-else
							variant="tertiary"
							class="pw-person__flach"
							@click="startEditResponsible">
							<template #icon>
								<AccountPlusIcon :size="20" />
							</template>
							{{ t('projektwerk', 'Zuständige Person festlegen') }}
						</NcButton>
					</div>
				</div>

				<!--
					Die Fälligkeit des Vorgangs (#72) — „bis wann ist die Sache
					fertig", verschieden von der Frist eines einzelnen Schritts.
					Immer sichtbar und leer löschbar, wie der Datumswähler am
					Schritt; überfällig wird kräftig markiert, aber nur wenn das
					Datum wirklich gerissen ist.
				-->
				<div class="pw-frist" :class="{ 'pw-frist--overdue': dueOverdue }">
					<span class="pw-frist__label">
						<CalendarAlertIcon v-if="dueOverdue" :size="18" />
						<CalendarIcon v-else :size="18" />
						{{ t('projektwerk', 'Fällig bis') }}
					</span>
					<NcDateTimePicker
						:modelValue="dueValue"
						type="date"
						class="pw-frist__picker"
						:clearable="true"
						:appendToBody="true"
						:disabled="busy"
						:ariaLabel="t('projektwerk', 'Fällig bis')"
						:placeholder="t('projektwerk', 'Keine Frist')"
						@update:modelValue="setDue" />
					<span v-if="dueOverdue" class="pw-frist__marke">
						{{ t('projektwerk', 'überfällig') }}
					</span>
				</div>

				<StepList
					:boardId="ticket.boardId"
					:ticketId="ticket.id"
					:steps="steps"
					:members="members"
					:orgInternal="orgInternal"
					:orgExternal="orgExternal"
					@changed="$emit('stepsChanged')" />

				<!--
					**Anhaenge vor Kommentaren** — so gibt §9 die Reihenfolge vor,
					und der Code hielt sie bis #99 umgekehrt. Kommentare sind die
					einzige Liste, die unbegrenzt waechst; zuletzt behaelt alles
					darueber eine feste Position.
				-->
				<AttachmentList
					:boardId="ticket.boardId"
					:ticketId="ticket.id"
					:attachments="attachments"
					:members="members"
					@changed="$emit('attachmentsChanged')" />

				<CommentList
					:boardId="ticket.boardId"
					:ticketId="ticket.id"
					:comments="comments"
					:members="members"
					:viewer="viewer"
					@changed="$emit('commentsChanged')" />
			</div>
		</div>
	</NcModal>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column, Member, ViewerInfo } from '@/types/board'
import type { Attachment, Comment, Step, Ticket, WaitState } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlusOutline.vue'
import CalendarAlertIcon from 'vue-material-design-icons/CalendarAlert.vue'
import CalendarIcon from 'vue-material-design-icons/CalendarOutline.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import AttachmentList from '@/components/AttachmentList.vue'
import CommentList from '@/components/CommentList.vue'
import StepList from '@/components/StepList.vue'
import VisibilityControl from '@/components/VisibilityControl.vue'
import WaitBadge from '@/components/WaitBadge.vue'
import { fetchAssignable } from '@/services/steps'
import { updateTicket } from '@/services/tickets'
import { reportWriteError } from '@/services/writeError'
import { asDate, isOverdue, toIsoDay } from '@/utils/date'

/** Ab welcher Hoehe die Beschreibung gedeckelt wird. Deckt sich mit der CSS. */
const TEXT_DECKEL_PX = 150

interface PersonOption {
	id: string
	displayName: string
	user: string
	subname?: string
}

export default defineComponent({
	name: 'TicketDetail',

	components: { AccountPlusIcon, AttachmentList, CalendarIcon, CalendarAlertIcon, ChevronDownIcon, ChevronUpIcon, CloseIcon, CommentList, NcAvatar, NcButton, NcDateTimePicker, NcModal, NcRichText, NcSelectUsers, NcTextArea, PencilOutlineIcon, PlusIcon, StepList, VisibilityControl, WaitBadge },

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
		attachments: { type: Array as PropType<Attachment[]>, default: () => [] },
		waiting: { type: Object as PropType<WaitState | null>, default: null },
		/** Aus Sicht der Kundenseite formuliert. */
		fromClientSide: { type: Boolean, default: false },
	},

	emits: ['close', 'changed', 'stepsChanged', 'commentsChanged', 'attachmentsChanged'],

	data() {
		return {
			busy: false,
			/** Die Beschreibung wird gerade bearbeitet. */
			editingText: false,
			textEntwurf: '',
			/** Der Deckel ist aufgeklappt. */
			textOffen: false,
			/** Der Text reicht ueber den Deckel hinaus — erst dann lohnt „Mehr anzeigen". */
			textZuHoch: false,
			/** Die Zustaendigkeit wird gerade gewaehlt. */
			editingResponsible: false,
			/**
			 * Wer einen Arbeitsschritt bekommen darf — und damit auch, wer
			 * zustaendig sein darf.
			 *
			 * **Vom Server, nicht aus den Board-Mitgliedern.** Wen der Vorgang
			 * zulaesst, folgt aus der Sichtbarkeitsregel; im Browser nachgebaut
			 * waere das ihre zweite Fassung, und die zweite prueft niemand.
			 *
			 * `StepList` holt dieselbe Liste noch einmal fuer sich. Ein zweiter
			 * Aufruf auf einen billigen Endpunkt, dafuer bleibt der Vertrag der
			 * Schrittliste unangetastet.
			 */
			assignable: [] as string[],
		}
	},

	computed: {
		paddedNumber(): string {
			return String(this.ticket?.number ?? 0).padStart(4, '0')
		},

		/** Die Fälligkeit als `Date` für den Datumswähler, oder null. */
		dueValue(): Date | null {
			return asDate(this.ticket?.dueDate ?? null)
		},

		/** Ist die Fälligkeit gerissen? Ein fehlendes Datum nie (#72). */
		dueOverdue(): boolean {
			return isOverdue(this.ticket?.dueDate ?? null)
		},

		/** Benennt den Dialog ueber die Ueberschrift, die ohnehin dasteht. */
		titleId(): string {
			return `pw-detail-title-${this.ticket?.id ?? 0}`
		},

		responsibleInputId(): string {
			return `pw-detail-responsible-${this.ticket?.id ?? 0}`
		},

		/** Leerer Entwurf und leere Beschreibung sind dasselbe — beides heisst `null`. */
		textGeaendert(): boolean {
			return this.textEntwurf.trim() !== (this.ticket?.description ?? '').trim()
		},

		/**
		 * Die Auswahlliste, wie `NcSelectUsers` sie erwartet.
		 *
		 * `subname` traegt die Firma.
		 *
		 * **Ohne `isGuest`.** Die Prop schaltet in `NcAvatar` auf einen anderen
		 * Bild-Endpunkt, und sie meint den **Kontotyp**. Unsere Rolle
		 * `external` sagt darueber nichts: „Was die Kundenseite zur Kundenseite
		 * macht, ist `role = 'external'` in `pwerk_members`, nicht ihr
		 * Kontotyp." Ein Vollkonto mit Rolle „Kundenseite" laedt sonst vom
		 * falschen Ort.
		 */
		assignableOptions(): PersonOption[] {
			return this.assignable.map((userId) => ({
				id: userId,
				displayName: this.nameOf(userId),
				user: userId,
				subname: this.roleOf(userId) === 'internal' ? this.orgInternal : this.orgExternal,
			}))
		},

		responsibleOption(): PersonOption | null {
			const userId = this.ticket?.responsibleUserId ?? null
			if (userId === null) {
				return null
			}

			return this.assignableOptions.find((o) => o.id === userId) ?? {
				id: userId,
				displayName: this.nameOf(userId),
				user: userId,
			}
		},

		columnTitle(): string {
			return this.columns.find((c) => c.id === this.ticket?.columnId)?.title ?? ''
		},

		/**
		 * Kennung auf Anzeigenamen, wie `WaitBadge` sie erwartet.
		 *
		 * **Der Server löst die Namen auf** (`resolvedName`) — im Browser
		 * nachzuschlagen bliebe ausgerechnet beim Gast stumm. Hier wird die
		 * Mitgliederliste nur in die Form gebracht, die die Marke liest.
		 */
		memberNames(): Record<string, string> {
			return Object.fromEntries(this.members.map((m) => [m.userId, m.resolvedName ?? m.userId]))
		},

	},

	watch: {
		/**
		 * Ein anderer Vorgang im selben Overlay.
		 *
		 * Angefangenes gehoert zum vorigen und darf nicht stehen bleiben — ein
		 * Entwurf im Textfeld landete sonst beim naechsten Speichern am falschen
		 * Vorgang.
		 */
		'ticket.id': {
			immediate: true,
			handler() {
				this.editingText = false
				this.editingResponsible = false
				this.textEntwurf = ''
				this.textOffen = false
				this.ladeAssignable()
			},
		},

		/** Der Deckel haengt an der Hoehe, und die kennt man erst nach dem Rendern. */
		'ticket.description': {
			immediate: true,
			handler() {
				this.$nextTick(() => this.messenDeckel())
			},
		},
	},

	mounted() {
		this.messenDeckel()
	},

	methods: {
		t,

		/**
		 * Reicht der Text ueber den Deckel hinaus?
		 *
		 * Gemessen statt geschaetzt: Wie viele Zeilen ein Markdown-Abschnitt
		 * ergibt, haengt an Listen, Tabellen und der Fensterbreite. Ein
		 * Zeichenzaehler laege bei jedem zweiten Vorgang daneben — und „Mehr
		 * anzeigen" unter einem vollstaendig sichtbaren Text ist schlimmer als
		 * kein Knopf.
		 */
		messenDeckel(): void {
			const el = this.$refs.textbereich as HTMLElement | undefined
			this.textZuHoch = el !== undefined && el.scrollHeight > TEXT_DECKEL_PX + 1
		},

		async ladeAssignable(): Promise<void> {
			if (this.ticket === null) {
				return
			}
			try {
				this.assignable = await fetchAssignable(this.ticket.boardId, this.ticket.id)
			} catch {
				// Ohne Liste bleibt die Auswahl leer; alles Uebrige am Vorgang
				// funktioniert weiter. Eine Meldung waere hier Laerm.
				this.assignable = []
			}
		},

		startEditText(): void {
			this.textEntwurf = this.ticket?.description ?? ''
			this.editingText = true
		},

		cancelEditText(): void {
			this.editingText = false
			this.textEntwurf = ''
		},

		/**
		 * Die Beschreibung schreiben.
		 *
		 * Leer heisst ausdruecklich `null` und nicht `''` — sonst stuende in der
		 * Datenbank eine leere Zeichenkette, und der Leerzustand im Vorgang
		 * unterschiede sich nicht mehr von „nie etwas eingetragen".
		 */
		async saveText(): Promise<void> {
			if (this.ticket === null || this.busy || !this.textGeaendert) {
				return
			}

			const text = this.textEntwurf.trim()
			this.busy = true
			try {
				const updated = await updateTicket(
					this.ticket.boardId,
					this.ticket.id,
					this.ticket.version,
					{ description: text === '' ? null : text },
				)
				this.$emit('changed', updated)
				this.editingText = false
				this.textEntwurf = ''
			} catch (e) {
				reportWriteError(e, t('projektwerk', 'Beschreibung konnte nicht gespeichert werden'))
			} finally {
				this.busy = false
			}
		},

		startEditResponsible(): void {
			this.editingResponsible = true
		},

		/**
		 * Die Zustaendigkeit setzen oder loeschen.
		 *
		 * Der leere Wert sendet ausdruecklich `null` — weggelassen hiesse
		 * „unveraendert", und die Zustaendigkeit liesse sich nie wieder entfernen.
		 *
		 * @param option Die gewaehlte Person, oder null beim Leeren.
		 */
		async setResponsible(option: PersonOption | PersonOption[] | null): Promise<void> {
			if (this.ticket === null || this.busy) {
				return
			}

			const gewaehlt = Array.isArray(option) ? (option[0] ?? null) : option
			const userId = gewaehlt?.id ?? null
			if (userId === (this.ticket.responsibleUserId ?? null)) {
				this.editingResponsible = false

				return
			}

			this.busy = true
			try {
				const updated = await updateTicket(
					this.ticket.boardId,
					this.ticket.id,
					this.ticket.version,
					{ responsibleUserId: userId },
				)
				this.$emit('changed', updated)
				this.editingResponsible = false
			} catch (e) {
				reportWriteError(e, t('projektwerk', 'Zuständigkeit konnte nicht gesetzt werden'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Die Fälligkeit des Vorgangs setzen oder löschen (#72).
		 *
		 * Der Leerstring löscht: `null` filtert der Controller als „nicht
		 * geschickt" heraus, also trägt der Leerstring das Abnehmen einer Frist.
		 *
		 * @param value Das gewählte Datum, oder null zum Löschen.
		 */
		async setDue(value: Date | null): Promise<void> {
			if (this.ticket === null || this.busy) {
				return
			}

			const iso = toIsoDay(value)
			if ((iso ?? null) === (this.ticket.dueDate ?? null)) {
				return
			}

			this.busy = true
			try {
				const updated = await updateTicket(
					this.ticket.boardId,
					this.ticket.id,
					this.ticket.version,
					{ dueDate: iso ?? '' },
				)
				this.$emit('changed', updated)
			} catch (e) {
				reportWriteError(e, t('projektwerk', 'Fälligkeit konnte nicht gesetzt werden'))
			} finally {
				this.busy = false
			}
		},

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
