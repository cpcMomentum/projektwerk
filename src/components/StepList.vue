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

			<span v-if="step.dueDate" class="pw-step__due">{{ step.dueDate }}</span>
		</div>

		<p v-if="ordered.length === 0" class="pw-detail__empty">
			{{ t('projektwerk', 'Noch keine Arbeitsschritte.') }}
		</p>

		<!--
			Eingabezeile am Listenende: tippen, Enter, fertig. Ein Dialog fuer
			einen einzeiligen Schritt waere drei Klicks fuer eine Zeile Text.
		-->
		<div class="pw-step pw-step--new">
			<NcTextField
				v-model="newTitle"
				:label="t('projektwerk', 'Neuer Arbeitsschritt')"
				:disabled="busy"
				@keydown.enter="add" />
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
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createStep, fetchAssignable, updateStep } from '@/services/steps'
import { showError } from '@/services/toast'

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

	components: { NcAvatar, NcButton, NcTextField },

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
			assignable: [] as string[],
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
			},
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

		add() {
			const title = this.newTitle.trim()
			if (title === '') {
				return
			}

			return this.write(
				async () => {
					await createStep(this.boardId, this.ticketId, { title })
					this.newTitle = ''
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
