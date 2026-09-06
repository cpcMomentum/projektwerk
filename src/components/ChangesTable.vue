<template>
	<div class="pw-changes">
		<div
			v-for="row in shown"
			:key="row.ticket.id"
			class="pw-task">
			<button
				type="button"
				class="pw-task__body"
				:aria-label="rowAria(row)"
				@click="open(row.ticket)">
				<span class="pw-task__title">
					<span class="pw-num">#{{ padded(row.ticket.number) }}</span>
					{{ row.ticket.title }}
				</span>
				<span v-if="row.board" class="pw-task__meta">
					{{ row.board.title }}
				</span>
			</button>

			<!--
				Die Marke rechts: das Datum der Änderung, wie die Wartemarke „seit
				…" (§9). Kein Verb — für einen fremd angelegten, noch ungesehenen
				Vorgang wäre „geändert" falsch, „neu" für einen bloß geänderten
				ebenso; das Datum stimmt in beiden Fällen. `updatedAt` ist ein
				voller Zeitstempel, deshalb der Tag vorn abgeschnitten, bevor
				`germanDate` ihn zerlegt (sonst derselbe `-`-Zerlegungsfehler wie
				in `tageZwischen`).
			-->
			<span v-if="markDate(row)" class="pw-task__due pw-changes__mark">
				{{ markDate(row) }}
			</span>
		</div>
	</div>
</template>

<script lang="ts">
import type { OverviewTicketRow } from '@/types/overview'
import type { Ticket } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import { germanDate } from '@/utils/date'

/**
 * Die Tabelle „Seit deinem letzten Blick" (#249) — was projektübergreifend neu
 * oder seit dem eigenen Blick geändert ist, jüngste Änderung oben.
 *
 * **Read-only und klickbar**, wie `MeasuresTable`: Ein Dashboard zeigt,
 * gehandelt wird im Vorgang. Der Klick öffnet den Vorgang (Deep-Link ins Board),
 * so wie „Meine Aufgaben" und die Maßnahmen.
 *
 * Die Zeilen und ihre Reihenfolge kommen fertig aus dem `overviewStore`
 * (`changedRows`); `limit` schneidet die kompakte Fassung fürs Dashboard ab.
 * Die **Regel**, was überhaupt hervorgehoben wird, steckt im Server
 * (`ChangeHighlighter`, #79/#175) — dieselbe wie die Kartenmarke am Board, damit
 * sich beide nie widersprechen.
 */
export default defineComponent({
	name: 'ChangesTable',

	props: {
		/** Die geänderten Vorgänge, bereits sortiert (jüngste zuerst). */
		rows: {
			type: Array as () => OverviewTicketRow[],
			required: true,
		},

		/** Höchstzahl der Zeilen; 0 = alle. */
		limit: {
			type: Number,
			default: 0,
		},
	},

	computed: {
		/** Die angezeigten Zeilen — bei gesetztem `limit` gekürzt. */
		shown(): OverviewTicketRow[] {
			return this.limit > 0 ? this.rows.slice(0, this.limit) : this.rows
		},
	},

	methods: {
		t,

		/**
		 * Die Vorgangsnummer vierstellig, wie überall (`#0007`).
		 *
		 * @param n Die Nummer.
		 */
		padded(n: number): string {
			return String(n).padStart(4, '0')
		},

		/**
		 * Das Änderungsdatum als Marke, oder leer, wenn keins vorliegt.
		 *
		 * `updatedAt` ist ein voller Zeitstempel; erst der Tag herausgeschnitten,
		 * dann durch `germanDate` — das an `-` zerlegt und an einem vollen
		 * Zeitstempel den Tag verfehlte.
		 *
		 * @param row Die Zeile.
		 */
		markDate(row: OverviewTicketRow): string {
			const iso = row.ticket.updatedAt
			return iso ? germanDate(iso.slice(0, 10)) : ''
		},

		/**
		 * Beschriftung der Zeile für Hilfstechnik.
		 *
		 * @param row Die Zeile.
		 */
		rowAria(row: OverviewTicketRow): string {
			return t('projektwerk', 'Vorgang #{number}: {title}, {board}', {
				number: this.padded(row.ticket.number),
				title: row.ticket.title,
				board: row.board?.title ?? '',
			})
		},

		/**
		 * Den Vorgang öffnen — Deep-Link ins Board mit geöffnetem Ticket, wie in
		 * „Meine Aufgaben" und den Maßnahmen.
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

<style scoped>
/*
 * Die Marke ist neutral, nicht die rote Fälligkeits-Uhr: „seit deinem Blick"
 * ist eine Information, keine Frist. Das Layout von `.pw-task__due` bleibt (Platz
 * rechts), nur die Signalfarbe fällt weg.
 */
.pw-changes__mark {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
