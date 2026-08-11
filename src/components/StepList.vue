<template>
	<section class="pw-detail__section">
		<h3 class="pw-col__head">
			{{ t('projektwerk', 'Arbeitsschritte') }}
		</h3>

		<div v-for="step in ordered" :key="step.id" class="pw-step">
			<input
				type="checkbox"
				:checked="step.done"
				:disabled="busy"
				:aria-label="step.title"
				@change="toggle(step)">

			<span class="pw-step__title" :class="{ 'pw-step__title--done': step.done }">
				{{ step.title }}
			</span>

			<NcAvatar
				v-if="step.assignedUserId"
				:user="step.assignedUserId"
				:displayName="nameOf(step.assignedUserId)"
				:size="24"
				:disableMenu="true" />

			<!--
				Beide Felder in einer Klammer, damit der Zeilenumbruch **vor**
				ihnen faellt und nicht zwischen ihnen: Auf 390 px passen sie
				zusammen nicht mehr neben den Titel, nebeneinander unter ihn aber
				sehr wohl. Ohne die Klammer wird aus jedem Schritt ein Block von
				drei Zeilen.
			-->
			<div class="pw-step__fields">
				<select
					:value="step.assignedUserId ?? ''"
					:aria-label="t('projektwerk', 'Zuständig')"
					:disabled="busy"
					@change="assign(step, $event)">
					<option value="">
						{{ t('projektwerk', 'Niemand') }}
					</option>
					<option v-for="userId in assignable" :key="userId" :value="userId">
						{{ nameOf(userId) }}
					</option>
				</select>

				<!--
					Die Faelligkeit war bisher **nur Anzeige** — setzen liess sie
					sich gar nicht, weder hier noch beim Anlegen. Wer eine Frist
					eintragen wollte, kam nur ueber die API dorthin (#86).

					Immer sichtbar, auch ohne Wert, wie die Personenauswahl
					daneben: Ein Feld, das erst auf Klick erscheint, findet
					niemand, der nicht weiss, dass es es gibt.
				-->
				<NcDateTimePickerNative
					type="date"
					:modelValue="asDate(step.dueDate)"
					:label="t('projektwerk', 'Fälligkeit')"
					:hideLabel="true"
					:disabled="busy"
					@update:modelValue="setDue(step, $event)" />
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
				:label="t('projektwerk', 'Neuer Arbeitsschritt')"
				:disabled="busy"
				@keydown.enter="add" />

			<div class="pw-step__fields">
				<select
					v-model="newAssignee"
					:aria-label="t('projektwerk', 'Zuständig')"
					:disabled="busy">
					<option value="">
						{{ t('projektwerk', 'Niemand') }}
					</option>
					<option v-for="userId in assignable" :key="userId" :value="userId">
						{{ nameOf(userId) }}
					</option>
				</select>

				<NcDateTimePickerNative
					v-model="newDueDate"
					type="date"
					:label="t('projektwerk', 'Fälligkeit')"
					:hideLabel="true"
					:disabled="busy" />
			</div>

			<NcButton :disabled="busy || newTitle.trim() === ''" @click="add">
				{{ t('projektwerk', 'Hinzufügen') }}
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
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createStep, fetchAssignable, updateStep } from '@/services/steps'
import { showError } from '@/services/toast'

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

	components: { NcAvatar, NcButton, NcDateTimePickerNative, NcTextField },

	props: {
		boardId: { type: Number, required: true },
		ticketId: { type: Number, required: true },
		steps: { type: Array as PropType<Step[]>, default: () => [] },
		/** Nur zur Anzeige der Namen. */
		members: { type: Array as PropType<Member[]>, default: () => [] },
	},

	emits: ['changed'],

	data() {
		return {
			busy: false,
			newTitle: '',
			/** Leer heißt „Niemand" — der schnelle Weg bleibt unbelastet. */
			newAssignee: '',
			newDueDate: null as Date | null,
			assignable: [] as string[],
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
	},

	watch: {
		ticketId: {
			immediate: true,
			handler() {
				this.loadAssignable()
				// Angefangenes gehört zum vorigen Vorgang und darf nicht stehen
				// bleiben — sonst trüge der nächste Schritt dessen Zuweisung.
				this.newTitle = ''
				this.newAssignee = ''
				this.newDueDate = null
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

			return this.write(
				async () => {
					await createStep(this.boardId, this.ticketId, {
						title,
						// Ausdrücklich `null` statt weglassen: Der Dienst
						// unterscheidet „nicht genannt" von „keine Zuweisung",
						// und gemeint ist hier das Zweite.
						assignedUserId: this.newAssignee === '' ? null : this.newAssignee,
						dueDate: alsIsoTag(this.newDueDate),
					})
					this.newTitle = ''
					this.newAssignee = ''
					this.newDueDate = null
					this.fokusZiel = '.pw-step--new input[type="text"]'
				},
				t('projektwerk', 'Arbeitsschritt konnte nicht angelegt werden'),
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
		 * Zuweisen oder Zuweisung löschen.
		 *
		 * Der leere Eintrag sendet ausdrücklich `null` — weggelassen hieße
		 * „unverändert", und die Zuweisung ließe sich nie wieder entfernen.
		 *
		 * @param step Der Schritt.
		 * @param event Das Auswahlereignis.
		 */
		assign(step: Step, event: Event) {
			const gewaehlt = (event.target as HTMLSelectElement).value

			return this.write(
				() => updateStep(this.boardId, step.id, { assignedUserId: gewaehlt === '' ? null : gewaehlt }),
				t('projektwerk', 'Zuweisung fehlgeschlagen'),
			)
		},
	},
})
</script>
