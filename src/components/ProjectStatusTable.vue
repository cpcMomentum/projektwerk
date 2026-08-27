<template>
	<div class="pw-ptable-card">
		<table class="pw-ptable">
			<thead>
				<tr>
					<th scope="col">
						{{ t('projektwerk', 'Projekt') }}
					</th>
					<th scope="col" class="pw-ptable__c">
						{{ t('projektwerk', 'Neu') }}
					</th>
					<th scope="col" class="pw-ptable__c">
						{{ t('projektwerk', 'Offen') }}
					</th>
					<th scope="col" class="pw-ptable__c">
						{{ t('projektwerk', 'Wartet') }}
					</th>
					<th scope="col" class="pw-ptable__c">
						{{ t('projektwerk', 'Erledigt') }}
					</th>
					<th scope="col">
						{{ t('projektwerk', 'Fortschritt') }}
					</th>
					<th scope="col">
						{{ t('projektwerk', 'Zustand') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.boardId"
					class="pw-ptable__row"
					tabindex="0"
					role="button"
					:aria-label="rowAria(row)"
					@click="open(row.boardId)"
					@keydown.enter="open(row.boardId)"
					@keydown.space.prevent="open(row.boardId)">
					<td class="pw-ptable__proj">
						<span class="pw-ptable__title">
							<StarIcon v-if="isPinned(row.boardId)" class="pw-ptable__pin" :size="14" />
							{{ row.title }}
						</span>
						<span v-if="row.org" class="pw-ptable__org">{{ row.org }}</span>
					</td>
					<td class="pw-ptable__c pw-num" :class="{ 'pw-ptable__zero': row.neu === 0 }">
						{{ row.neu }}
					</td>
					<td class="pw-ptable__c pw-num" :class="{ 'pw-ptable__zero': row.offen === 0 }">
						{{ row.offen }}
					</td>
					<td
						class="pw-ptable__c pw-num"
						:class="{ 'pw-ptable__zero': row.wartet === 0, 'pw-ptable__warn': row.wartet > 0 }">
						{{ row.wartet }}
					</td>
					<td class="pw-ptable__c pw-num pw-ptable__done" :class="{ 'pw-ptable__zero': row.erledigt === 0 }">
						{{ row.erledigt }}
					</td>
					<td class="pw-ptable__progress">
						<span class="pw-ptable__bar"><span :style="{ width: prozent(row.fortschritt) }" /></span>
						<span class="pw-ptable__pct">{{ prozent(row.fortschritt) }}</span>
					</td>
					<td>
						<span class="pw-dot" :class="'pw-dot--' + row.zustand">{{ zustandLabel(row.zustand) }}</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script lang="ts">
import type { ProjectStatusRow } from '@/types/overview'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import { useBoardStore } from '@/stores/boardStore'
import { useOverviewStore } from '@/stores/overviewStore'

/**
 * Die Projekt-Status-Tabelle des Dashboards (#226).
 *
 * Zeigt je **aktivem** Projekt die kanonischen Status (Neu/Offen/Wartet/Erledigt),
 * den Fortschritt und das abgeleitete Zustandssignal. Die Rechnung liegt im
 * Store (`projectStatusRows`); diese Komponente zeigt nur an und sortiert die
 * angepinnten nach oben.
 *
 * **Der Klick führt ins Board** — in Stufe 2 (#227) wird daraus der Weg ins
 * Projekt-Dashboard. Bis dahin ist das Board der einzige Zielort und bleibt es
 * hier, damit kein zweiter Ort entsteht, an dem die Sichtbarkeit stimmen müsste.
 */
export default defineComponent({
	name: 'ProjectStatusTable',

	components: { StarIcon },

	setup() {
		return { store: useOverviewStore(), boardStore: useBoardStore() }
	},

	computed: {
		/**
		 * Die Kennungen der angepinnten Projekte, als Menge für den schnellen
		 * Test je Zeile.
		 */
		pinnedIds(): Set<number> {
			return new Set(this.boardStore.pinnedBoards.map((board) => board.id))
		},

		/**
		 * Die anzuzeigenden Zeilen: leere Projekte (nichts offen, nichts erledigt,
		 * nichts verworfen) fallen weg, angepinnte zuerst.
		 *
		 * Die Reihenfolge des Stores (nach Zustand) bleibt innerhalb beider
		 * Gruppen erhalten — stabile Teilung, wie im Überblick.
		 */
		rows(): ProjectStatusRow[] {
			const alle = (this.store.projectStatusRows as ProjectStatusRow[])
				.filter((row) => row.offenGesamt > 0 || row.erledigt > 0 || row.verworfen > 0)
			const pinned = this.pinnedIds

			return [
				...alle.filter((row) => pinned.has(row.boardId)),
				...alle.filter((row) => !pinned.has(row.boardId)),
			]
		},
	},

	methods: {
		t,

		/**
		 * @param boardId Kennung des Projekts.
		 */
		isPinned(boardId: number): boolean {
			return this.pinnedIds.has(boardId)
		},

		/**
		 * Fortschritt als ganze Prozent — `tabular-nums` hält die Spalte ruhig.
		 *
		 * @param anteil 0..1.
		 */
		prozent(anteil: number): string {
			return `${Math.round(anteil * 100)} %`
		},

		/**
		 * Das kurze Wort zum Zustandssignal. Farbe trägt nie allein (§9): der
		 * Punkt bekommt einen Text daneben.
		 *
		 * @param zustand Der abgeleitete Zustand.
		 */
		zustandLabel(zustand: ProjectStatusRow['zustand']): string {
			switch (zustand) {
				case 'rot':
					return t('projektwerk', 'überfällig')
				case 'gelb':
					return t('projektwerk', 'wartet')
				case 'grau':
					return t('projektwerk', 'steht still')
				default:
					return t('projektwerk', 'läuft')
			}
		},

		/**
		 * Beschriftung der ganzen Zeile für Hilfstechnik.
		 *
		 * @param row Die Zeile.
		 */
		rowAria(row: ProjectStatusRow): string {
			return t('projektwerk', '{title}: {zustand}, {offen} offen, {erledigt} erledigt', {
				title: row.title,
				zustand: this.zustandLabel(row.zustand),
				offen: String(row.offenGesamt),
				erledigt: String(row.erledigt),
			})
		},

		/**
		 * Ins Board des Projekts. In Stufe 2 (#227) wird das der Weg ins
		 * Projekt-Dashboard.
		 *
		 * @param boardId Kennung des Projekts.
		 */
		open(boardId: number): void {
			this.$router.push({ name: 'board', params: { boardId: String(boardId) } })
		},
	},
})
</script>
