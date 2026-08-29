<template>
	<div class="pw-tiles-wrap">
		<!-- Legende einmal oben: Farbe → Status. Die Kacheln beschriften die
		     Segmente danach nur noch mit Strich + Zahl. -->
		<div class="pw-tiles__legend">
			<span
				v-for="s in STATUS"
				:key="s.key"
				class="pw-tiles__leg"
				:class="s.cls">
				<i class="pw-tiles__legdot" />{{ s.label() }}
			</span>
		</div>

		<div class="pw-tiles">
			<button
				v-for="row in shown"
				:key="row.boardId"
				type="button"
				class="pw-tile"
				:aria-label="tileAria(row)"
				@click="open(row.boardId)">
				<span class="pw-tile__head">
					<span class="pw-tile__ident">
						<StarIcon v-if="isPinned(row.boardId)" class="pw-tile__pin" :size="14" />
						<span class="pw-tile__title">{{ row.title }}</span>
						<span v-if="row.org" class="pw-tile__org">{{ row.org }}</span>
					</span>
					<span class="pw-dot" :class="'pw-dot--' + row.zustand">{{ zustandLabel(row.zustand) }}</span>
				</span>

				<!-- Zahlen am Anfang ihres Segments: gleiche Proportion wie der
				     Balken, jede mit farbigem Strich davor. -->
				<span class="pw-tile__nums">
					<span
						v-for="seg in segmente(row)"
						:key="seg.key"
						class="pw-tile__num"
						:class="seg.cls"
						:style="{ flex: seg.count }">
						<i class="pw-tile__tick" />{{ seg.count }}
					</span>
				</span>

				<!-- Der echt proportionale Statusbalken. Nullwerte fehlen ganz. -->
				<span class="pw-tile__bar">
					<span
						v-for="seg in segmente(row)"
						:key="seg.key"
						class="pw-tile__seg"
						:class="seg.cls"
						:style="{ flex: seg.count }" />
				</span>
			</button>
		</div>

		<button
			v-if="mehr > 0"
			type="button"
			class="pw-tiles__more"
			@click="expandiert = true">
			{{ n('projektwerk', '%n weiteres Projekt anzeigen', '%n weitere Projekte anzeigen', mehr) }}
		</button>
	</div>
</template>

<script lang="ts">
import type { ProjectStatusRow } from '@/types/overview'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import { useBoardStore } from '@/stores/boardStore'
import { useOverviewStore } from '@/stores/overviewStore'

/**
 * Wie viele Kacheln höchstens ohne Aufklappen stehen (#226). Sechs, weil zwei
 * Spalten mal drei Reihen ein ruhiges Raster geben; der Rest kommt per Klick.
 */
const MAX_KACHELN = 6

/** Ein Statuseintrag der Legende und der Segmente — Reihenfolge = Balken. */
const STATUS = [
	{ key: 'neu', cls: 'pw-st--neu', label: (): string => t('projektwerk', 'Neu') },
	{ key: 'offen', cls: 'pw-st--offen', label: (): string => t('projektwerk', 'Offen') },
	{ key: 'wartet', cls: 'pw-st--wartet', label: (): string => t('projektwerk', 'Wartet') },
	{ key: 'erledigt', cls: 'pw-st--erl', label: (): string => t('projektwerk', 'Erledigt') },
] as const

/**
 * Die Projekt-Kacheln des Dashboards (#226) — je aktivem Projekt eine Kachel mit
 * den kanonischen Status als Zahlen über einem **echt proportionalen**
 * Statusbalken (die Zahl sitzt am Anfang ihres Segments) und dem abgeleiteten
 * Zustandssignal.
 *
 * Die Rechnung liegt im Store (`projectStatusRows`); diese Komponente zeigt an,
 * sortiert angepinnte nach oben und blendet leere Projekte aus. Klick → Board;
 * in Stufe 2 (#227) wird daraus der Weg ins Projekt-Dashboard.
 */
export default defineComponent({
	name: 'ProjectTiles',

	components: { StarIcon },

	setup() {
		return { store: useOverviewStore(), boardStore: useBoardStore(), STATUS }
	},

	data() {
		return {
			/** Ob alle Projekte gezeigt werden (statt nur der ersten sechs). */
			expandiert: false,
		}
	},

	computed: {
		/** Kennungen der angepinnten Projekte, als Menge. */
		pinnedIds(): Set<number> {
			return new Set(this.boardStore.pinnedBoards.map((board) => board.id))
		},

		/**
		 * Die anzuzeigenden Projekte: leere (nichts offen, nichts erledigt/
		 * verworfen) fallen weg, angepinnte zuerst — stabile Teilung, wie im
		 * Überblick.
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

		/** Die tatsächlich gezeigten — bis zum Aufklappen auf sechs begrenzt. */
		shown(): ProjectStatusRow[] {
			return this.expandiert ? this.rows : this.rows.slice(0, MAX_KACHELN)
		},

		/** Wie viele Projekte noch hinter „weitere anzeigen" stecken. */
		mehr(): number {
			return this.rows.length - this.shown.length
		},
	},

	methods: {
		n,

		/**
		 * @param boardId Kennung des Projekts.
		 */
		isPinned(boardId: number): boolean {
			return this.pinnedIds.has(boardId)
		},

		/**
		 * Die Segmente einer Kachel in Balken-Reihenfolge — nur die mit Wert > 0,
		 * damit der Balken kein Segment für „nichts" trägt.
		 *
		 * @param row Die Zeile.
		 */
		segmente(row: ProjectStatusRow): Array<{ key: string, cls: string, count: number }> {
			const werte: Record<string, number> = {
				neu: row.neu,
				offen: row.offen,
				wartet: row.wartet,
				erledigt: row.erledigt,
			}

			return STATUS
				.map((s) => ({ key: s.key, cls: s.cls, count: werte[s.key] }))
				.filter((seg) => seg.count > 0)
		},

		/**
		 * Das kurze Wort zum Zustandssignal (Farbe trägt nie allein, §9).
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
		 * Beschriftung der ganzen Kachel für Hilfstechnik.
		 *
		 * @param row Die Zeile.
		 */
		tileAria(row: ProjectStatusRow): string {
			return t('projektwerk', '{title}: {zustand}, {neu} neu, {offen} offen, {wartet} wartet, {erledigt} erledigt', {
				title: row.title,
				zustand: this.zustandLabel(row.zustand),
				neu: String(row.neu),
				offen: String(row.offen),
				wartet: String(row.wartet),
				erledigt: String(row.erledigt),
			})
		},

		/**
		 * Ins Board des Projekts (Stufe 2: Projekt-Dashboard, #227).
		 *
		 * @param boardId Kennung des Projekts.
		 */
		open(boardId: number): void {
			this.$router.push({ name: 'board', params: { boardId: String(boardId) } })
		},
	},
})
</script>
