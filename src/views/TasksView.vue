<template>
	<div class="pw-view pw-tasks">
		<div class="pw-view__head">
			<h2>{{ t('projektwerk', 'Meine Aufgaben') }}</h2>
			<span v-if="store.overdueCount > 0" class="pw-tasks__overdue">
				{{ overdueLabel }}
			</span>
		</div>

		<div v-if="store.loading" class="pw-stack">
			<div v-for="n in 3" :key="n" class="pw-skel">
				<i /><i /><i />
			</div>
		</div>

		<!--
			**Der Fehlerfall vor dem Leerfall.** Ohne ihn behauptet ein
			gescheitertes Laden „Zurzeit wartet nichts auf Sie." — die Listen
			sind ja leer. Das ist die unangenehmste Sorte Falschaussage: Sie
			beruhigt, wo sie warnen muesste.
		-->
		<NcEmptyContent
			v-else-if="store.error !== null"
			:name="t('projektwerk', 'Aufgaben konnten nicht geladen werden')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="nothingToDo"
			:name="t('projektwerk', 'Zurzeit wartet nichts auf Sie.')"
			:description="t('projektwerk', 'Sobald Ihnen ein Arbeitsschritt zugewiesen wird oder Sie für einen Vorgang zuständig sind, steht er hier.')">
			<template #icon>
				<FormatListChecksIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!--
				Erst die Schritte: Das ist das, was jemand von mir erwartet, und
				laut §9 der haeufigste Vorgang des Kunden. „Meine Tickets" ist
				der weitere Rahmen und steht deshalb darunter.
			-->
			<section v-if="store.stepRows.length > 0" class="pw-tasks__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Meine Arbeitsschritte') }}
					<span class="pw-n">{{ store.stepRows.length }}</span>
				</h3>

				<div
					v-for="row in store.stepRows"
					:key="row.step.id"
					class="pw-task"
					:class="{ 'pw-task--overdue': row.overdue }">
					<!--
						Das Kaestchen erledigt den Schritt an Ort und Stelle
						(§9) — der haeufigste Vorgang des Kunden darf keine drei
						Klicks kosten. Gesperrt wird nur diese Zeile: Wer fuenf
						Haken hintereinander setzt, soll nicht auf jeden warten.
					-->
					<!--
						**Der Haken wird nach dem Aufruf zurueckgesetzt, nicht
						nur gebunden.** `:checked` allein genuegt nicht: Vue
						schreibt das DOM nur neu, wenn sich der *gebundene Wert*
						aendert. Beim Scheitern bleibt der Schritt offen, der
						Wert also `false` wie vorher — und der Haken, den der
						Browser beim Klick von sich aus gesetzt hat, stuende
						weiter an einem unerledigten Schritt. Gemessen, nicht
						vermutet: mit kuenstlich scheiterndem PATCH geprueft.

						Bei Erfolg faellt die Zeile ohnehin aus der Liste — ein
						Schritt steht hier nur, solange er offen ist.

						Gesperrt wird nur DIESE Zeile. Alle zu sperren hiesse,
						den Folgeklick zu schlucken, waehrend sich die Liste
						darunter neu ordnet.
					-->
					<input
						type="checkbox"
						class="pw-task__check"
						:checked="row.step.done"
						:disabled="store.busySteps.includes(row.step.id)"
						:aria-label="checkLabel(row)"
						@change="complete(row, $event)">

					<button type="button" class="pw-task__body" @click="open(row.ticket)">
						<span class="pw-task__title">{{ row.step.title }}</span>
						<span class="pw-task__meta">
							<span class="pw-num">#{{ padded(row.ticket.number) }}</span>
							{{ row.ticket.title }}
							<!--
								Die Herkunft gehoert an die Zeile: „Freigabe
								erteilen" allein sagt nichts, mit Vorgang und
								Projekt sagt es alles.
							-->
							<span v-if="row.board" class="pw-task__board">· {{ row.board.title }}</span>
						</span>
					</button>

					<span v-if="row.step.dueDate" class="pw-task__due">
						{{ dueLabel(row) }}
					</span>
				</div>
			</section>

			<section v-if="store.tickets.length > 0" class="pw-tasks__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Meine Vorgänge') }}
					<span class="pw-n">{{ store.tickets.length }}</span>
				</h3>

				<div v-for="ticket in store.tickets" :key="ticket.id" class="pw-task">
					<button type="button" class="pw-task__body" @click="open(ticket)">
						<span class="pw-task__title">
							<span class="pw-num">#{{ padded(ticket.number) }}</span>
							{{ ticket.title }}
						</span>
						<span v-if="store.boardOf(ticket)" class="pw-task__meta">
							{{ store.boardOf(ticket).title }}
							<span v-if="orgLine(ticket)" class="pw-task__board">· {{ orgLine(ticket) }}</span>
						</span>
					</button>
				</div>
			</section>
		</template>
	</div>
</template>

<script lang="ts">
import type { StepRow } from '@/types/task'
import type { Ticket } from '@/types/ticket'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import { useTaskStore } from '@/stores/taskStore'

/**
 * „Meine Aufgaben" — projektübergreifend, die Startseite des Kunden (§9).
 *
 * **Zwei Abschnitte, in dieser Reihenfolge.** Oben, was andere von mir
 * erwarten (meine Arbeitsschritte), darunter der weitere Rahmen (meine
 * Vorgänge). Wer die Seite öffnet, will zuerst wissen, was er schuldet.
 *
 * Jede Zeile trägt ihre **Herkunft**: Vorgang und Projekt. Ohne sie wäre
 * „Freigabe erteilen" eine Aufgabe ohne Ort — und auf einer projektübergreifenden
 * Seite ist der Ort die halbe Information.
 *
 * Der Klick auf eine Zeile führt ins **Board**, nicht in ein eigenes Detail:
 * Ein zweiter Ort, an dem ein Vorgang lebt, wäre ein zweiter Ort, an dem die
 * Sichtbarkeit stimmen müsste — und der Deep-Link führt ohnehin dorthin.
 */
export default defineComponent({
	name: 'TasksView',

	components: { AlertIcon, FormatListChecksIcon, NcEmptyContent },

	setup() {
		return { store: useTaskStore() }
	},

	computed: {
		nothingToDo(): boolean {
			return this.store.stepRows.length === 0 && this.store.tickets.length === 0
		},

		/**
		 * Die Zahl der überfälligen Schritte, als Satz.
		 *
		 * Steht in der Kopfzeile und nicht als Filter: Überfälliges steht durch
		 * die Sortierung ohnehin oben, ein Schalter wäre ein zweiter Weg zu
		 * derselben Zeile.
		 */
		overdueLabel(): string {
			return n(
				'projektwerk',
				'%n Arbeitsschritt ist überfällig',
				'%n Arbeitsschritte sind überfällig',
				this.store.overdueCount,
			)
		},
	},

	created() {
		this.store.load()
	},

	methods: {
		t,

		/**
		 * Einen Schritt erledigen und den Haken danach dem Speicher überlassen.
		 *
		 * Der Browser setzt ihn beim Klick selbst; das ist eine Vermutung, keine
		 * Tatsache. Wahr wird sie erst, wenn der Server zugestimmt hat — und
		 * dann ist die Zeile weg. Bleibt sie stehen, war es ein Fehlschlag, und
		 * der Haken gehört zurück.
		 *
		 * @param row Die Zeile.
		 * @param event Das Änderungsereignis.
		 */
		async complete(row: StepRow, event: Event): Promise<void> {
			const box = event.target as HTMLInputElement

			await this.store.completeStep(row)

			// Bei Erfolg haengt dieses Element nicht mehr im Baum — dann ist
			// die Zuweisung wirkungslos und stoert nicht.
			box.checked = false
		},

		/**
		 * @param number Die Ticketnummer.
		 */
		padded(number: number): string {
			return String(number).padStart(4, '0')
		},

		/**
		 * @param ticket Der Vorgang.
		 */
		orgLine(ticket: Ticket): string {
			const board = this.store.boardOf(ticket)
			if (board === null) {
				return ''
			}

			return [board.orgInternal, board.orgExternal].filter(Boolean).join(' · ')
		},

		/**
		 * Die Beschriftung des Kästchens — mit dem Titel des Schritts.
		 *
		 * Ohne ihn stünden N Kästchen „Erledigt" untereinander, und wer die
		 * Seite hört statt sieht, könnte sie nicht auseinanderhalten.
		 *
		 * @param row Die Zeile.
		 */
		checkLabel(row: StepRow): string {
			return t('projektwerk', '„{title}“ erledigen', { title: row.step.title })
		},

		/**
		 * Fällig am — oder überfällig seit.
		 *
		 * @param row Die Zeile.
		 */
		dueLabel(row: StepRow): string {
			const date = this.germanDate(row.step.dueDate)

			return row.overdue
				? t('projektwerk', 'überfällig seit {date}', { date })
				: t('projektwerk', 'fällig {date}', { date })
		},

		/**
		 * `2026-08-20` als `20.08.2026`.
		 *
		 * Die Wartemarke schreibt Daten schon deutsch; ein rohes ISO-Datum
		 * daneben läse sich wie ein Datenbankfeld. Umgerechnet wird auf der
		 * Zeichenkette und nicht über `Date`: `dueDate` ist ein Datum **ohne**
		 * Uhrzeit, und ein Umweg über einen Zeitpunkt verschiebt es je nach
		 * Zeitzone um einen Tag.
		 *
		 * @param iso Datum als JJJJ-MM-TT, oder null.
		 */
		germanDate(iso: string | null): string {
			if (iso === null) {
				return ''
			}
			const [jahr, monat, tag] = iso.split('-')

			return tag === undefined ? iso : `${tag}.${monat}.${jahr}`
		},

		/**
		 * Ins Board, mit dem Vorgang offen — derselbe Weg wie der Deep-Link.
		 *
		 * @param ticket Der Vorgang.
		 */
		open(ticket: Ticket): void {
			this.$router.push({
				name: 'board',
				params: { boardId: String(ticket.boardId) },
				query: { ticket: String(ticket.id) },
			})
		},
	},
})
</script>
