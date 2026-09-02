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
			<NcCheckboxRadioSwitch
				type="checkbox"
				class="pw-step__check"
				:modelValue="step.done"
				:disabled="busy"
				@update:modelValue="toggle(step)">
				<span :class="{ 'pw-step__title--done': step.done }">{{ step.title }}</span>
			</NcCheckboxRadioSwitch>

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

				<template v-else>
					<template v-if="editing === step.id">
						<label class="hidden-visually" :for="'pw-step-user-' + step.id">
							{{ t('projektwerk', 'Zuständig') }}
						</label>
						<NcSelectUsers
							class="pw-step__picker"
							:options="options"
							:modelValue="optionFor(step.assignedUserId)"
							:inputId="'pw-step-user-' + step.id"
							:labelOutside="true"
							:disabled="busy"
							:placeholder="t('projektwerk', 'Niemand')"
							@update:modelValue="assign(step, $event)" />

						<NcDateTimePicker
							type="date"
							class="pw-step__datum"
							:modelValue="asDate(step.dueDate)"
							:clearable="true"
							:appendToBody="true"
							:ariaLabel="t('projektwerk', 'Fälligkeit')"
							:placeholder="t('projektwerk', 'Fälligkeit')"
							:disabled="busy"
							@update:modelValue="setDue(step, $event)" />

						<NcButton variant="tertiary" :ariaLabel="t('projektwerk', 'Fertig')" @click="saveDetails(step)">
							<template #icon>
								<CheckIcon :size="20" />
							</template>
						</NcButton>
					</template>

					<template v-else-if="step.assignedUserId || step.dueDate">
						<span class="pw-step__info">
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
							:ariaLabel="t('projektwerk', 'Zuweisung und Fälligkeit ändern: {title}', { title: step.title })"
							@click="beginEdit(step)">
							<template #icon>
								<PencilOutlineIcon :size="20" />
							</template>
						</NcButton>
					</template>

					<NcButton
						v-else
						variant="tertiary"
						class="pw-step__flach"
						@click="beginEdit(step)">
						{{ t('projektwerk', 'Zuweisen oder Frist setzen') }}
					</NcButton>

					<!-- Der Papierkorb — nicht während des Bearbeitens, sonst wird die Zeile eng. -->
					<NcButton
						v-if="editing !== step.id"
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
			<p v-if="editing !== step.id && step.description" class="pw-step__beschreibung">
				{{ step.description }}
			</p>

			<div v-if="editing !== step.id && step.result" class="pw-step__ergebnis">
				<span class="pw-step__ergebnis-marke">{{ t('projektwerk', 'Ergebnis') }}</span>
				<span class="pw-step__ergebnis-text">{{ step.result }}</span>
			</div>

			<div v-if="editing === step.id" class="pw-step__felder-text">
				<NcTextField
					class="pw-step__feld"
					:modelValue="editDescription"
					:label="t('projektwerk', 'Beschreibung')"
					:disabled="busy"
					@update:modelValue="editDescription = $event"
					@keydown.enter="saveDetails(step)" />

				<NcTextArea
					class="pw-step__feld"
					:modelValue="editResult"
					:label="t('projektwerk', 'Ergebnis')"
					:rows="3"
					resize="vertical"
					:disabled="busy"
					@update:modelValue="editResult = $event"
					@keydown.enter.ctrl.exact="saveDetails(step)"
					@keydown.enter.meta.exact="saveDetails(step)" />
			</div>
		</div>

		<p v-if="ordered.length === 0" class="pw-detail__empty">
			{{ t('projektwerk', 'Noch keine Arbeitsschritte.') }}
		</p>

		<!--
			Eingabezeile am Listenende: tippen, Enter, fertig. Ein Dialog fuer
			einen einzeiligen Schritt waere drei Klicks fuer eine Zeile Text.

			**Zustaendig und Faelligkeit stehen mit in der Zeile** (#86). Vorher
			legte man an und wies danach zu — zwei Schritte fuer eine
			Entscheidung, die beim Tippen schon feststand. Der schnelle Weg
			bleibt trotzdem: Beide Felder sind leer vorbelegt, und Enter sendet
			ab. Wer niemanden zuweisen und keine Frist setzen will, merkt von der
			Erweiterung nichts.
		-->
		<div class="pw-step pw-step--new">
			<NcTextField
				v-model="newTitle"
				class="pw-step__neu-titel"
				:label="t('projektwerk', 'Neuer Arbeitsschritt')"
				:disabled="busy"
				@keydown.enter="add" />

			<!--
				Beschreibung optional in einer eigenen Zeile (#247). Enter sendet
				weiterhin ab, der schnelle Weg (nur Titel, Enter) bleibt also
				unbelastet.
			-->
			<NcTextField
				v-model="newDescription"
				class="pw-step__neu-beschreibung"
				:label="t('projektwerk', 'Beschreibung (optional)')"
				:disabled="busy"
				@keydown.enter="add" />

			<label class="hidden-visually" for="pw-step-new-user">
				{{ t('projektwerk', 'Zuständig') }}
			</label>
			<NcSelectUsers
				id="pw-step-new-user-wrap"
				class="pw-step__neu-person"
				:options="options"
				:modelValue="newAssignee"
				inputId="pw-step-new-user"
				:labelOutside="true"
				:disabled="busy"
				:placeholder="t('projektwerk', 'Niemand')"
				@update:modelValue="newAssignee = single($event)" />

			<NcDateTimePicker
				v-model="newDueDate"
				type="date"
				class="pw-step__neu-datum"
				:clearable="true"
				:appendToBody="true"
				:ariaLabel="t('projektwerk', 'Fälligkeit')"
				:placeholder="t('projektwerk', 'Fälligkeit')"
				:disabled="busy" />

			<!--
				**Ein Plus statt eines breiten Knopfes** (#99) — damit passt die
				Neuanlage in eine Zeile statt in drei.

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
		orgExternal: { type: String, default: '' },
	},

	emits: ['changed'],

	data() {
		return {
			busy: false,
			newTitle: '',
			/** Eine Zeile Beschreibung für den neuen Schritt (#247), leer = keine. */
			newDescription: '',
			/** `null` heißt „Niemand" — der schnelle Weg bleibt unbelastet. */
			newAssignee: null as PersonOption | null,
			newDueDate: null as Date | null,
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
				subname: this.roleOf(userId) === 'internal' ? this.orgInternal : this.orgExternal,
			}))
		},
	},

	watch: {
		ticketId: {
			immediate: true,
			handler() {
				this.loadAssignable()
				// Angefangenes gehört zum vorigen Vorgang und darf nicht stehen
				// bleiben — sonst trüge der nächste Schritt dessen Zuweisung.
				this.newTitle = ''
				this.newDescription = ''
				this.newAssignee = null
				this.newDueDate = null
				this.editing = null
				this.removing = null
			},
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
		async write(run: () => Promise<unknown>, fallback: string): Promise<void> {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				await run()
				this.$emit('changed')
			} catch (e) {
				showError((e as { message?: string }).message ?? fallback)
			} finally {
				this.busy = false
			}
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

			const description = this.newDescription.trim()

			return this.write(
				async () => {
					await createStep(this.boardId, this.ticketId, {
						title,
						// Leer heißt „keine Beschreibung": als `null`, nicht als
						// leere Zeichenkette — so muss die Anzeige nicht zwischen
						// beidem unterscheiden.
						description: description === '' ? null : description,
						// Ausdrücklich `null` statt weglassen: Der Dienst
						// unterscheidet „nicht genannt" von „keine Zuweisung",
						// und gemeint ist hier das Zweite.
						assignedUserId: this.newAssignee?.id ?? null,
						dueDate: alsIsoTag(this.newDueDate),
					})
					this.newTitle = ''
					this.newDescription = ''
					this.newAssignee = null
					this.newDueDate = null
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
			this.editDescription = step.description ?? ''
			this.editResult = step.result ?? ''
		},

		/**
		 * Beschreibung und Ergebnis sichern und den Bearbeiten-Modus verlassen
		 * (#247).
		 *
		 * Nur die tatsächlich geänderten Felder gehen mit; sind beide
		 * unverändert, wird nichts geschrieben und nur geschlossen. Ein leerer
		 * Wert reist als Leerstring — der Dienst macht daraus `null` (Feld
		 * geleert), und weil `array_key_exists` am Endpunkt greift, kommt das
		 * Leeren auch wirklich an.
		 *
		 * @param step Der Schritt.
		 */
		saveDetails(step: Step) {
			const beschreibung = this.editDescription.trim()
			const ergebnis = this.editResult.trim()
			const changes: { description?: string, result?: string } = {}

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
