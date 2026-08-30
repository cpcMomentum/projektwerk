<template>
	<div class="pw-ptable-card">
		<table class="pw-ptable pw-mtable">
			<thead>
				<tr>
					<th scope="col">
						{{ t('projektwerk', 'Vorgang') }}
					</th>
					<th scope="col">
						{{ t('projektwerk', 'Projekt') }}
					</th>
					<th scope="col">
						{{ t('projektwerk', 'Art') }}
					</th>
					<th scope="col" class="pw-mtable__due">
						{{ t('projektwerk', 'Fällig') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in shown"
					:key="row.key"
					class="pw-ptable__row"
					tabindex="0"
					role="button"
					:aria-label="rowAria(row)"
					@click="open(row)"
					@keydown.enter="open(row)"
					@keydown.space.prevent="open(row)">
					<td class="pw-mtable__vorgang">
						<span class="pw-ptable__title">{{ row.title }}</span>
					</td>
					<td class="pw-mtable__proj">
						{{ row.board ? row.board.title : '' }}
					</td>
					<td>
						<span class="pw-art" :class="'pw-art--' + row.art">{{ artLabel(row.art) }}</span>
					</td>
					<td class="pw-mtable__due" :class="{ 'pw-mtable__overdue': row.overdue }">
						{{ dueText(row) }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script lang="ts">
import type { MeasureRow } from '@/types/task'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import { germanDate } from '@/utils/date'

/**
 * Die Maßnahmen-Tabelle (#226) — Schritte und Vorgänge in einer Liste, nach der
 * Art der Obligation unterschieden (Schritt / Verantwortung).
 *
 * **Read-only und klickbar**: Ein Dashboard zeigt, gehandelt wird im Vorgang.
 * Der Klick öffnet den Vorgang (Deep-Link ins Board), so wie „Meine Aufgaben"
 * heute. Die volle, bearbeitbare Fassung (mit Erledigt-Häkchen am Schritt) ist
 * die Seite „Meine Aufgaben"; diese Tabelle zeigt eine kompakte Auswahl.
 *
 * Die Zeilen und ihre Reihenfolge kommen fertig aus dem `taskStore`
 * (`measureRows`); `limit` schneidet die kompakte Fassung fürs Dashboard ab.
 */
export default defineComponent({
	name: 'MeasuresTable',

	props: {
		/** Die Maßnahmen, bereits sortiert (Überfälliges oben). */
		rows: {
			type: Array as () => MeasureRow[],
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
		shown(): MeasureRow[] {
			return this.limit > 0 ? this.rows.slice(0, this.limit) : this.rows
		},
	},

	methods: {
		t,

		/**
		 * Das Wort zur Art. Ein Pillen-Etikett, das die Obligation benennt.
		 *
		 * @param art Die Art der Obligation.
		 */
		artLabel(art: MeasureRow['art']): string {
			return art === 'schritt'
				? t('projektwerk', 'Schritt')
				: t('projektwerk', 'Verantwortung')
		},

		/**
		 * Die Fälligkeit als Text — überfällig mit Vorsatz, sonst das Datum,
		 * ohne Datum ein Strich. Die Farbe (rot bei überfällig) trägt die Klasse
		 * an der Zelle, nie allein (§9): der Vorsatz „überfällig" steht als Wort.
		 *
		 * @param row Die Zeile.
		 */
		dueText(row: MeasureRow): string {
			if (row.dueDate === null) {
				return '—'
			}
			const datum = germanDate(row.dueDate)

			return row.overdue
				? t('projektwerk', 'überfällig {date}', { date: datum })
				: datum
		},

		/**
		 * Beschriftung der Zeile für Hilfstechnik.
		 *
		 * @param row Die Zeile.
		 */
		rowAria(row: MeasureRow): string {
			return t('projektwerk', '{art}: {title}, {faellig}', {
				art: this.artLabel(row.art),
				title: row.title,
				faellig: this.dueText(row),
			})
		},

		/**
		 * Den Vorgang öffnen — Deep-Link ins Board mit geöffnetem Ticket, wie in
		 * „Meine Aufgaben".
		 *
		 * @param row Die Zeile.
		 */
		open(row: MeasureRow): void {
			this.$router.push({
				name: 'board',
				params: { boardId: String(row.ticket.boardId) },
				query: { ticket: String(row.ticket.id) },
			})
		},
	},
})
</script>
