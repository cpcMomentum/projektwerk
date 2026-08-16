<template>
	<!--
		**Die einzige Stelle, an der `vue-draggable-plus` importiert wird** (Plan
		§3.6). Ein Austausch der Bibliothek kostet diese eine Datei; ein Ausfall
		kostet Komfort, nicht Funktion — Menü „Verschieben nach …" und Tastatur
		tragen die Bedienbarkeit weiter (§5.28), `sortablejs` kann keine Tastatur.

		**Die Karten werden hier gerendert, nicht über einen Eltern-Slot.** Das ist
		der Grund: `sortablejs` verschiebt beim Ziehen echte DOM-Knoten. Gehören
		die einem fremden Render-Baum (BoardView), gerät er beim Neuzeichnen mit
		`sortablejs` aneinander, und die Karte steht kurz in zwei Spalten. Besitzt
		der Zieh-Layer sie selbst, bleibt genau die eine Stelle Herr über den
		Knoten — kein Neuaufbau, kein Aufblitzen.

		Der Server bleibt die Wahrheit: Das Ziehen mutiert nur die lokale Kopie,
		danach rechnet `onDrop` die Nachbar-IDs und meldet sie hoch;
		`store.moveTicket` lädt neu und der frische Stand ersetzt die Kopie über
		den `tickets`-Watcher — derselbe Kommandoweg wie das Menü.
	-->
	<VueDraggable
		v-model="lokal"
		:group="{ name: 'pw-board' }"
		:animation="150"
		:delay="180"
		:delayOnTouchOnly="true"
		:forceFallback="true"
		:fallbackTolerance="4"
		:scroll="true"
		:scrollSensitivity="60"
		:bubbleScroll="true"
		tag="div"
		class="pw-stack__drag"
		@add="onDrop"
		@update="onDrop">
		<TicketCard
			v-for="ticket in lokal"
			:key="ticket.id"
			:ticket="ticket"
			:showVisibility="showVisibility"
			:responsibleName="store.nameOf(ticket.responsibleUserId)"
			:columns="store.columns"
			:commentCount="store.counts?.comments?.[ticket.id] ?? 0"
			:stepCount="store.counts?.steps?.[ticket.id] ?? 0"
			:stepsDone="store.counts?.stepsDone?.[ticket.id] ?? 0"
			:waitState="store.waiting[ticket.id] ?? null"
			:changed="store.changed[ticket.id] === true"
			:memberNames="store.memberNames"
			:fromClientSide="!store.isInternal"
			@open="$emit('open', $event)"
			@move="$emit('menumove', $event)" />
	</VueDraggable>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Ticket } from '@/types/ticket'

import { defineComponent } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import TicketCard from '@/components/TicketCard.vue'
import { useBoardStore } from '@/stores/boardStore'

/**
 * Der Zieh-Aufsatz über einem Spalten-Stapel (#11, 7a).
 *
 * Umschließt die Karten **einer** Spalte und rendert sie selbst. Alle Layer
 * teilen die Gruppe `pw-board`, deshalb wandern Karten über Spaltengrenzen. Das
 * Ziehen per langem Antippen (`delay` + `delayOnTouchOnly`) lässt vertikales
 * Scrollen auf dem Touchgerät erhalten; mit der Maus zieht die Bewegung sofort,
 * ein Klick ohne Bewegung öffnet weiterhin die Karte.
 *
 * Die Kartendaten kommen aus demselben Store wie in `BoardView` — es ist
 * derselbe Pinia-Speicher, kein zweiter Lesepfad.
 */
export default defineComponent({
	name: 'BoardDragLayer',

	components: { TicketCard, VueDraggable },

	props: {
		/** Die sichtbaren Karten dieser Spalte, in ihrer Reihenfolge. */
		tickets: { type: Array as PropType<Ticket[]>, required: true },
		/** Die Spalte, in die hier abgelegt wird. */
		columnId: { type: Number, required: true },
		/** Ob die Sichtbarkeits-Kennzeichnung an der Karte gezeigt wird. */
		showVisibility: { type: Boolean, default: false },
	},

	emits: ['open', 'menumove', 'dragmove'],

	setup() {
		return { store: useBoardStore() }
	},

	data() {
		return {
			/**
			 * Spiegel der `tickets`-Prop. `VueDraggable` mutiert ihn beim Ablegen;
			 * die Wahrheit kommt danach vom Server zurück und setzt ihn neu.
			 */
			lokal: [...this.tickets] as Ticket[],
		}
	},

	watch: {
		tickets(neu: Ticket[]) {
			// Nach dem Neuladen (oder bei Filter/Einklappen) den Spiegel wieder an
			// den Serverstand angleichen. Ein neues Array, nicht in-place, damit
			// `VueDraggable` sauber neu bindet.
			this.lokal = [...neu]
		},
	},

	methods: {
		/**
		 * Eine Karte wurde abgelegt — innerhalb der Spalte (`update`) oder aus
		 * einer anderen (`add`). Beide Male ist der Spiegel bereits umsortiert; die
		 * Nachbarn ergeben sich aus der Zielposition.
		 *
		 * @param event Das sortablejs-Ereignis mit der neuen Position.
		 * @param event.newIndex Die Zielposition der Karte in der Liste.
		 */
		onDrop(event: { newIndex?: number }): void {
			const index = event.newIndex
			if (index === undefined) {
				return
			}

			const moved = this.lokal[index]
			if (moved === undefined) {
				return
			}

			// Nachbarn aus dem **sichtbaren** Ausschnitt — wie beim Menü. Was die
			// Ansicht verbirgt (Nur wartend, eingeklappte Ältere), darf die
			// Sortierung nicht verschieben; der Server ordnet über die volle Liste.
			this.$emit('dragmove', {
				ticketId: moved.id,
				targetColumnId: this.columnId,
				beforeId: this.lokal[index - 1]?.id ?? null,
				afterId: this.lokal[index + 1]?.id ?? null,
			})
		},
	},
})
</script>
