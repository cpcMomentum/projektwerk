<template>
	<section class="pw-abschnitt">
		<div class="pw-abschnitt__kopf">
			<h3>{{ t('projektwerk', 'Arbeitsschritte') }}</h3>
			<span v-if="ordered.length > 0" class="pw-abschnitt__zaehler">{{ fortschritt }}</span>
		</div>

		<!--
			**Variante C** (#99). Wo eine Zuweisung oder eine Frist steht, steht
			sie als Text; wo nichts steht, stehen zwei flache Knoepfe.

			Das haelt die Festlegung aus #86 — sichtbar, dass es die Felder gibt —
			ohne bei fuenf Schritten fuenf Comboboxen und fuenf Datumsfelder
			untereinanderzustellen. Genau das war die Kritik am alten Stand.
		-->
		<div v-for="step in ordered" :key="step.id" class="pw-step">
			<!-- Titel steht nicht im Label, sonst hakt ein Klick zum Öffnen ab. -->
			<NcCheckboxRadioSwitch
				type="checkbox"
				class="pw-step__check"
				:modelValue="step.done"
				:disabled="busy"
				@update:modelValue="toggle(step)">
				<span class="hidden-visually">{{ t('projektwerk', 'Erledigt: {title}', { title: step.title }) }}</span>
			</NcCheckboxRadioSwitch>

			<!-- Beim Bearbeiten nur im Feld, sonst stünde der alte Titel darüber. -->
			<button
				v-if="editing !== step.id"
				type="button"
				class="pw-step__title"
				:class="{ 'pw-step__title--done': step.done }"
				@click="beginEdit(step)">
				{{ step.title }}
			</button>

			<div class="pw-step__rechts">
				<!--
					**Löschen: leichte Rückfrage in der Zeile** (#203), wie beim
					Kommentar. Hart gelöscht, keinen Papierkorb — deshalb kurz
					nachgefragt, statt einen schweren Dialog aufzuziehen.
				-->
				<template v-if="removing === step.id">
					<span class="pw-step__confirm">{{ t('projektwerk', 'Arbeitsschritt entfernen?') }}</span>
					<NcButton :disabled="busy" @click="removeStep(step)">
						{{ t('projektwerk', 'Löschen') }}
					</NcButton>
					<NcButton :disabled="busy" @click="removing = null">
						{{ t('projektwerk', 'Abbrechen') }}
					</NcButton>
				</template>

				<template v-else-if="editing !== step.id">
					<!--
						**Variante B** (#345): lesen, Stift je Schritt. Zuweisung und
						Frist stehen als Text; ob gesetzt oder nicht, der Weg zum
						Ändern ist derselbe Stift.
					-->
					<span v-if="step.assignedUserId || step.dueDate" class="pw-step__info">
						<NcAvatar
							v-if="step.assignedUserId"
							:user="step.assignedUserId"
							:displayName="nameOf(step.assignedUserId)"
							:size="24"
							:disableMenu="true"
							:hideStatus="true" />
						{{ infoFor(step) }}
					</span>
					<NcButton
						variant="tertiary"
						:ariaLabel="t('projektwerk', 'Arbeitsschritt bearbeiten: {title}', { title: step.title })"
						:title="t('projektwerk', 'Bearbeiten')"
						@click="beginEdit(step)">
						<template #icon>
							<PencilOutlineIcon :size="20" />
						</template>
					</NcButton>

					<NcButton
						variant="tertiary"
						:ariaLabel="t('projektwerk', 'Arbeitsschritt löschen: {title}', { title: step.title })"
						@click="removing = step.id">
						<template #icon>
							<DeleteOutlineIcon :size="20" />
						</template>
					</NcButton>
				</template>
			</div>

			<!--
				Beschreibung und Ergebnis stehen als volle Zeilen unter der
				Kopfzeile — `.pw-step` bricht um. Angezeigt außerhalb des
				Bearbeitens; beim Bearbeiten treten die Felder an ihre Stelle.
				Das Ergebnis wird mehrzeilig gezeigt (CSS `pre-wrap`).
			-->
			<!-- Text im `span`: Leerraum direkt im `p` zeigte `pre-wrap` als Leerzeile an. -->
			<p v-if="editing !== step.id && step.description" class="pw-step__beschreibung">
				<span>{{ step.description }}</span>
			</p>

			<div v-if="editing !== step.id && step.result" class="pw-step__ergebnis">
				<span class="pw-step__ergebnis-marke">{{ t('projektwerk', 'Ergebnis') }}</span>
				<span class="pw-step__ergebnis-text">{{ step.result }}</span>
			</div>

			<!--
				Der geöffnete Schritt (#345): Beschriftung **über** dem Feld wie im
				übrigen App-Formular (`pw-field`), Zuständig und Frist in einer Zeile
				und gleich hoch, „Fertig" unten statt eines Häkchens am Rand.
				Zuweisung und Frist speichern sofort; Titel, Beschreibung und
				Ergebnis mit „Fertig" (oder Enter bzw. Strg/Cmd+Enter).
			-->
			<div v-if="editing === step.id" class="pw-step__felder-text">
				<div class="pw-field">
					<label :for="'pw-step-title-' + step.id">{{ t('projektwerk', 'Titel') }}</label>
					<NcTextField
						:id="'pw-step-title-' + step.id"
						class="pw-step__feld"
						:modelValue="editTitle"
						:label="t('projektwerk', 'Titel')"
						:labelOutside="true"
						:disabled="busy"
						@update:modelValue="editTitle = $event"
						@keydown.enter="saveDetails(step)" />
				</div>

				<div class="pw-step__felder-zeile">
					<div class="pw-field pw-step__picker-feld">
						<label :for="'pw-step-user-' + step.id">{{ t('projektwerk', 'Zuständig') }}</label>
						<NcSelectUsers
							class="pw-step__picker"
							:options="options"
							:modelValue="optionFor(step.assignedUserId)"
							:inputId="'pw-step-user-' + step.id"
							:labelOutside="true"
							:disabled="busy"
							:placeholder="t('projektwerk', 'Niemand')"
							@update:modelValue="assign(step, $event)" />
					</div>
					<div class="pw-field pw-step__datum-feld">
						<label>{{ t('projektwerk', 'Fällig bis') }}</label>
						<NcDateTimePicker
							type="date"
							class="pw-step__datum"
							:modelValue="asDate(step.dueDate)"
							:clearable="true"
							:appendToBody="true"
							:ariaLabel="t('projektwerk', 'Fälligkeit')"
							:placeholder="t('projektwerk', 'Keine Frist')"
							:disabled="busy"
							@update:modelValue="setDue(step, $event)" />
					</div>
				</div>

				<div class="pw-field">
					<label :for="'pw-step-desc-' + step.id">{{ t('projektwerk', 'Beschreibung') }}</label>
					<NcTextArea
						:id="'pw-step-desc-' + step.id"
						class="pw-step__feld"
						:modelValue="editDescription"
						:label="t('projektwerk', 'Beschreibung')"
						:labelOutside="true"
						:rows="1"
						resize="none"
						:disabled="busy"
						@update:modelValue="editDescription = $event"
						@keydown.enter.ctrl.exact="saveDetails(step)"
						@keydown.enter.meta.exact="saveDetails(step)" />
				</div>

				<div class="pw-field">
					<label :for="'pw-step-result-' + step.id">{{ t('projektwerk', 'Ergebnis') }}</label>
					<NcTextArea
						:id="'pw-step-result-' + step.id"
						class="pw-step__feld"
						:modelValue="editResult"
						:label="t('projektwerk', 'Ergebnis')"
						:labelOutside="true"
						:rows="1"
						resize="none"
						:disabled="busy"
						@update:modelValue="editResult = $event"
						@keydown.enter.ctrl.exact="saveDetails(step)"
						@keydown.enter.meta.exact="saveDetails(step)" />
				</div>

				<div class="pw-step__felder-aktionen">
					<NcButton
						variant="primary"
						:ariaLabel="t('projektwerk', 'Fertig')"
						:disabled="busy"
						@click="saveDetails(step)">
						<template #icon>
							<CheckIcon :size="20" />
						</template>
						{{ t('projektwerk', 'Fertig') }}
					</NcButton>
				</div>
			</div>
		</div>

		<p v-if="ordered.length === 0" class="pw-detail__empty">
			{{ t('projektwerk', 'Noch keine Arbeitsschritte.') }}
		</p>

		<!--
			Eingabezeile am Listenende: tippen, Enter, fertig. Ein Dialog fuer
			einen einzeiligen Schritt waere drei Klicks fuer eine Zeile Text.

			**Nur ein Feld „Neuer Arbeitsschritt" + „+"** (#308). Zuvor standen
			hier vier Felder — Titel, Beschreibung (#247), Zuständig und Frist
			(#86); die Anlege-Form wurde als zu wuchtig empfunden. #308 nimmt das
			bewusst zurück: Zuweisung, Frist, Beschreibung und Ergebnis werden
			**nach** dem Anlegen über den Stift des Schritts nachgetragen. Das
			Zuweisen wird damit wieder ein zweiter Schritt — gewollt, nicht
			übersehen.
		-->
		<div class="pw-step pw-step--new">
			<NcTextField
				v-model="newTitle"
				class="pw-step__neu-titel"
				:label="t('projektwerk', 'Neuer Arbeitsschritt')"
				:disabled="busy"
				@keydown.enter="add" />

			<!--
				**Ein Plus statt eines breiten Knopfes** (#99).

				Ausdruecklich **ohne** `size="small"`: Das waere
				`--clickable-area-small` (24 px) und damit unter der
				Plattformgrenze. `NcButton` setzt von sich aus
				`--default-clickable-area`, also 34 px.

				Der schnelle Weg bleibt: Enter im Textfeld sendet weiterhin ab,
				das Plus ist nur die sichtbare Entsprechung.
			-->
			<NcButton
				variant="primary"
				class="pw-step__neu-plus"
				:disabled="busy || newTitle.trim() === ''"
				:ariaLabel="t('projektwerk', 'Arbeitsschritt hinzufügen')"
				@click="add">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
			</NcButton>
		</div>
	</section>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Member } from '@/types/board'
import type { Step } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { createStep, deleteStep, fetchAssignable, updateStep } from '@/services/steps'
import { showError } from '@/services/toast'

interface PersonOption {
	id: string
	displayName: string
	user: string
	subname?: string
}

/**
 * Ein `Date` in das Format, das der Server verlangt (`JJJJ-MM-TT`).
 *
 * **Nicht über `toISOString()`.** Das rechnet nach UTC um, und westlich von
 * Greenwich fällt der 11. dabei auf den 10. zurück — eine Frist, die einen Tag
 * zu früh im Kalender steht, und niemand sieht warum. Die lokalen Bestandteile
 * sind genau das, was im Feld stand.
 *
 * @param date Was der Picker geliefert hat.
 */
function alsIsoTag(date: Date | null): string | null {
	if (date === null) {
		return null
	}

	const zwei = (wert: number): string => String(wert).padStart(2, '0')

	return `${date.getFullYear()}-${zwei(date.getMonth() + 1)}-${zwei(date.getDate())}`
}

/**
 * Die Arbeitsschritte eines Vorgangs.
 *
 * **Die Auswahlliste kommt vom Server**, nicht aus den Board-Mitgliedern. Wer
 * einen Schritt bekommen darf, folgt aus der Sichtbarkeitsregel: bei einem
 * öffentlichen Vorgang alle Beteiligten ohne Trennung, bei einem internen nur
 * die besitzende Seite, bei einem Entwurf nur die anlegende Person. Diese
 * Bedingung im Browser nachzubauen wäre ihre zweite Fassung — und die zweite
 * Fassung prüft niemand.
 *
 * Dass die Liste bei einem öffentlichen Vorgang interne und externe Personen
 * **gemeinsam und ohne Warnung** zeigt, ist kein Versehen: Der Kundenzugriff
 * ist Zweck des Produkts, keine Ausnahme.
 */
export default defineComponent({
	name: 'StepList',

	components: { CheckIcon, DeleteOutlineIcon, NcAvatar, NcButton, NcCheckboxRadioSwitch, NcDateTimePicker, NcSelectUsers, NcTextArea, NcTextField, PencilOutlineIcon, PlusIcon },

	props: {
		boardId: { type: Number, required: true },
		ticketId: { type: Number, required: true },
		steps: { type: Array as PropType<Step[]>, default: () => [] },
		/** Nur zur Anzeige der Namen. */
		members: { type: Array as PropType<Member[]>, default: () => [] },
		/** Fuer die Zweitzeile in der Personenauswahl. */
		orgInternal: { type: String, default: '' },
		/** Kunde des Projekts (#309) — Rückfall für externe Mitglieder ohne eigene Firma. */
		customer: { type: String, default: '' },
	},

	emits: ['changed'],

	data() {
		return {
			busy: false,
			schreibkette: Promise.resolve() as Promise<void>,
			newTitle: '',
			assignable: [] as string[],
			/**
			 * Der Schritt, dessen Zuweisung und Frist gerade offenstehen.
			 *
			 * Immer nur einer: Zwei offene Zeilen waeren wieder die Wand aus
			 * Formularfeldern, die Variante C abgeraeumt hat.
			 */
			editing: null as number | null,
			/**
			 * Puffer für Beschreibung und Ergebnis des gerade bearbeiteten
			 * Schritts (#247). Anders als Zuweisung und Frist, die sofort beim
			 * Ändern speichern, sind das Freitextfelder — hier gilt dasselbe
			 * Muster wie bei der Ticket-Beschreibung: lokal tippen, mit „Fertig"
			 * (oder Strg/Cmd+Enter) speichern.
			 */
			editTitle: '',
			editDescription: '',
			editResult: '',
			/** Der Schritt, dessen Löschen gerade zur Rückfrage offensteht (#203). */
			removing: null as number | null,
			/**
			 * Wohin der Fokus nach dem Anlegen gehört.
			 *
			 * Dasselbe Muster wie in `CommentList` und aus demselben Grund: Das
			 * Feld leert sich, „Hinzufügen" wird dadurch deaktiviert und nimmt
			 * den Fokus mit auf den `body`. Wer sich durchgetabbt hat, fängt von
			 * vorn an.
			 *
			 * Zweimal lokal statt einmal geteilt — beim dritten Mal gehört das in
			 * einen gemeinsamen Helfer.
			 */
			fokusZiel: null as string | null,
		}
	},

	computed: {
		ordered(): Step[] {
			return [...this.steps].sort((a, b) => a.position - b.position)
		},

		/** „2/5" — dieselbe Auskunft wie auf der Karte (§9). */
		fortschritt(): string {
			return `${this.steps.filter((s) => s.done).length}/${this.steps.length}`
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
		options(): PersonOption[] {
			return this.assignable.map((userId) => ({
				id: userId,
				displayName: this.nameOf(userId),
				user: userId,
				subname: this.companyOf(userId),
			}))
		},
	},

	watch: {
		ticketId: {
			immediate: true,
			handler() {
				this.loadAssignable()
				// Angefangenes gehört zum vorigen Vorgang und darf nicht stehen
				// bleiben.
				this.newTitle = ''
				this.editing = null
				this.removing = null
			},
		},

		/** Erst nach dem Rendern messen, sonst gilt noch die alte Höhe. */
		editDescription() {
			this.$nextTick(() => this.autoGrowFelder())
		},

		editResult() {
			this.$nextTick(() => this.autoGrowFelder())
		},

		/**
		 * Den Fokus nach dem Neuaufbau der Liste wieder setzen.
		 *
		 * Hängt an der Ersetzung der Liste und nicht am Schreibaufruf: Der
		 * Elternteil lädt nach jedem Schreiben neu, und vorher steht das Ziel
		 * noch gar nicht im Dokument.
		 */
		steps() {
			const ziel = this.fokusZiel
			if (ziel === null) {
				return
			}
			this.fokusZiel = null
			this.$nextTick(() => {
				const el = this.$el?.querySelector?.(ziel)
				if (el instanceof HTMLElement) {
					el.focus()
				}
			})
		},
	},

	methods: {
		t,

		/**
		 * @param userId Kennung der Person.
		 */
		nameOf(userId: string): string {
			return this.members.find((m) => m.userId === userId)?.resolvedName ?? userId
		},

		/**
		 * @param userId Kennung der Person.
		 */
		roleOf(userId: string): string {
			return this.members.find((m) => m.userId === userId)?.role ?? 'internal'
		},

		/**
		 * Die Firma dieser Person (#309): pro Mitglied gepflegt. Fällt eine noch
		 * nicht gepflegte Firma auf die alte, aus der Rolle abgeleitete Board-Firma
		 * zurück — so bleibt die Anzeige lückenlos, bis Phase 3b die Pflege liefert.
		 *
		 * @param userId Kennung der Person.
		 */
		companyOf(userId: string): string {
			const member = this.members.find((m) => m.userId === userId)
			return member?.company ?? (this.roleOf(userId) === 'external' ? this.customer : this.orgInternal)
		},

		/**
		 * @param userId Kennung der Person, oder null.
		 */
		optionFor(userId: string | null): PersonOption | null {
			if (userId === null) {
				return null
			}

			return this.options.find((o) => o.id === userId) ?? {
				id: userId,
				displayName: this.nameOf(userId),
				user: userId,
			}
		},

		/**
		 * `NcSelectUsers` kann auch mehrfach — hier nie. Die Liste faellt auf
		 * ihren ersten Eintrag zusammen, damit der Rest mit einem Wert rechnen
		 * kann statt mit zweien.
		 *
		 * @param value Was die Auswahl geliefert hat.
		 */
		single(value: PersonOption | PersonOption[] | null): PersonOption | null {
			return Array.isArray(value) ? (value[0] ?? null) : value
		},

		/**
		 * Zuweisung und Frist als ein Satzstueck.
		 *
		 * Der Name steht neben dem Avatar, die Frist dahinter; fehlt eines von
		 * beiden, entfaellt es samt Trenner. Ein „· " ohne Fortsetzung sieht aus
		 * wie ein Fehler.
		 *
		 * @param step Der Schritt.
		 */
		infoFor(step: Step): string {
			const teile: string[] = []
			if (step.assignedUserId !== null) {
				teile.push(this.nameOf(step.assignedUserId))
			}
			if (step.dueDate !== null && step.dueDate !== '') {
				const datum = this.asDate(step.dueDate)
				if (datum !== null) {
					teile.push(datum.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric' }))
				}
			}

			return teile.join(' · ')
		},

		async loadAssignable(): Promise<void> {
			try {
				this.assignable = await fetchAssignable(this.boardId, this.ticketId)
			} catch {
				// Ohne Liste bleibt die Auswahl leer; das Ändern eines Schritts
				// bleibt trotzdem möglich. Eine Meldung wäre hier Lärm — die
				// eigentliche Arbeit ist das Abhaken.
				this.assignable = []
			}
		},

		/**
		 * @param run Der Schreibaufruf.
		 * @param fallback Meldung, wenn der Server keine eigene mitgibt.
		 */
		write(run: () => Promise<unknown>, fallback: string): Promise<void> {
			// Anstellen statt verwerfen: Frist direkt nach der Person ging sonst still verloren.
			const lauf = this.schreibkette.then(async () => {
				this.busy = true
				try {
					await run()
					this.$emit('changed')
				} catch (e) {
					showError((e as { message?: string }).message ?? fallback)
				} finally {
					this.busy = false
				}
			})
			this.schreibkette = lauf

			return lauf
		},

		/**
		 * Ein `JJJJ-MM-TT` vom Server als `Date` für den Picker.
		 *
		 * Mit `T00:00` statt roh: `new Date('2026-08-11')` liest die Zeichenkette
		 * als UTC-Mitternacht und zeigt westlich von Greenwich den Vortag. Mit
		 * Uhrzeit dahinter wird sie als Ortszeit gelesen — derselbe Tag, der
		 * dasteht.
		 *
		 * @param wert Was der Server geliefert hat.
		 */
		asDate(wert: string | null): Date | null {
			return wert === null || wert === '' ? null : new Date(`${wert}T00:00`)
		},

		/**
		 * Die Fälligkeit eines bestehenden Schritts setzen oder löschen.
		 *
		 * @param step Der Schritt.
		 * @param wert Das gewählte Datum, oder null zum Löschen.
		 */
		setDue(step: Step, wert: Date | null) {
			const neu = alsIsoTag(wert)
			if (neu === (step.dueDate ?? null)) {
				return
			}

			return this.write(
				() => updateStep(this.boardId, step.id, { dueDate: neu }),
				t('projektwerk', 'Fälligkeit konnte nicht gesetzt werden'),
			)
		},

		add() {
			const title = this.newTitle.trim()
			if (title === '') {
				return
			}

			return this.write(
				async () => {
					// Nur der Titel (#308): Zuweisung, Frist, Beschreibung und
					// Ergebnis werden nach dem Anlegen über den Stift nachgetragen.
					await createStep(this.boardId, this.ticketId, { title })
					this.newTitle = ''
					this.fokusZiel = '.pw-step--new input[type="text"]'
				},
				t('projektwerk', 'Arbeitsschritt konnte nicht angelegt werden'),
			)
		},

		/**
		 * In den Bearbeiten-Modus eines Schritts wechseln (#247).
		 *
		 * Zuweisung und Frist speichern sofort beim Ändern; Beschreibung und
		 * Ergebnis werden erst hier in den Puffer kopiert und mit „Fertig"
		 * gespeichert. Deshalb der eigene Einstieg statt `editing = step.id`.
		 *
		 * @param step Der Schritt, der bearbeitet wird.
		 */
		beginEdit(step: Step) {
			this.editing = step.id
			this.editTitle = step.title
			this.editDescription = step.description ?? ''
			this.editResult = step.result ?? ''
			this.removing = null
			this.$nextTick(() => {
				// Die Watcher feuern nicht, wenn der Puffer schon denselben Wert hatte.
				this.autoGrowFelder()
				// Der geklickte Titel ist jetzt weg; ohne Ziel fiele der Fokus auf den `body`.
				const titelFeld = (this.$el as HTMLElement | undefined)?.querySelector?.('.pw-step__felder-text input')
				if (titelFeld instanceof HTMLElement) {
					titelFeld.focus()
				}
			})
		},

		/** Erst `auto`, damit `scrollHeight` den Inhalt misst statt der zuletzt gesetzten Höhe. */
		autoGrowFelder(): void {
			const felder = (this.$el as HTMLElement | undefined)?.querySelectorAll?.('.pw-step__felder-text textarea') ?? []
			for (const feld of felder) {
				if (feld instanceof HTMLTextAreaElement) {
					feld.style.height = 'auto'
					feld.style.height = feld.scrollHeight + 'px'
				}
			}
		},

		/**
		 * Titel, Beschreibung und Ergebnis sichern und den Bearbeiten-Modus
		 * verlassen (#247, #308).
		 *
		 * Nur die tatsächlich geänderten Felder gehen mit; sind alle
		 * unverändert, wird nichts geschrieben und nur geschlossen. Ein leerer
		 * Beschreibungs-/Ergebnis-Wert reist als Leerstring — der Dienst macht
		 * daraus `null` (Feld geleert), und weil `array_key_exists` am Endpunkt
		 * greift, kommt das Leeren auch wirklich an.
		 *
		 * **Der Titel ist Pflicht** (#308): Ein leergeräumtes Titelfeld wird
		 * ignoriert, der bisherige Titel bleibt stehen — anders als die
		 * optionalen Felder lässt er sich nicht entwerten.
		 *
		 * @param step Der Schritt.
		 */
		saveDetails(step: Step) {
			const titel = this.editTitle.trim()
			const beschreibung = this.editDescription.trim()
			const ergebnis = this.editResult.trim()
			const changes: { title?: string, description?: string, result?: string } = {}

			if (titel !== '' && titel !== step.title) {
				changes.title = titel
			}
			if (beschreibung !== (step.description ?? '')) {
				changes.description = beschreibung
			}
			if (ergebnis !== (step.result ?? '')) {
				changes.result = ergebnis
			}

			if (Object.keys(changes).length === 0) {
				this.editing = null

				return
			}

			return this.write(
				async () => {
					await updateStep(this.boardId, step.id, changes)
					this.editing = null
				},
				t('projektwerk', 'Ändern fehlgeschlagen'),
			)
		},

		/**
		 * @param step Der Schritt.
		 */
		toggle(step: Step) {
			return this.write(
				() => updateStep(this.boardId, step.id, { done: !step.done }),
				t('projektwerk', 'Ändern fehlgeschlagen'),
			)
		},

		/**
		 * Einen Arbeitsschritt löschen (#203) — nach der Rückfrage. Der Server
		 * prüft die Sichtbarkeit; danach lädt der Vorgang neu (`changed`), der
		 * Schritt ist weg.
		 *
		 * @param step Der zu löschende Arbeitsschritt.
		 */
		async removeStep(step: Step): Promise<void> {
			await this.write(
				() => deleteStep(this.boardId, step.id),
				t('projektwerk', 'Löschen fehlgeschlagen'),
			)
			this.removing = null
		},

		/**
		 * Zuweisen oder Zuweisung löschen.
		 *
		 * Der leere Eintrag sendet ausdrücklich `null` — weggelassen hieße
		 * „unverändert", und die Zuweisung ließe sich nie wieder entfernen.
		 *
		 * @param step Der Schritt.
		 * @param value Die gewaehlte Person, oder null beim Leeren.
		 */
		assign(step: Step, value: PersonOption | PersonOption[] | null) {
			const gewaehlt = this.single(value)?.id ?? null
			if (gewaehlt === (step.assignedUserId ?? null)) {
				return
			}

			return this.write(
				() => updateStep(this.boardId, step.id, { assignedUserId: gewaehlt }),
				t('projektwerk', 'Zuweisung fehlgeschlagen'),
			)
		},
	},
})
</script>
