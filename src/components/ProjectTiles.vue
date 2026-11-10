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
			<!-- Dieselbe Kachel wie in der Projektliste, hier ohne Pin-Toggle. -->
			<ProjectTile
				v-for="tile in shown"
				:key="tile.key"
				:boardId="tile.boardId"
				:title="tile.title"
				:org="tile.org"
				:neu="tile.neu"
				:offen="tile.offen"
				:wartet="tile.wartet"
				:erledigt="tile.erledigt"
				:neuDieseWoche="tile.neuDieseWoche"
				:zustand="tile.zustand"
				:pinned="tile.pinned"
				@open="open" />
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
import type { ProjectTileData } from '@/services/projectTiles'
import type { ProjectStatusRow } from '@/types/overview'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import ProjectTile from '@/components/ProjectTile.vue'
import { AUFMERKSAMKEIT, overviewProjectTiles } from '@/services/projectTiles'
import { useBoardStore } from '@/stores/boardStore'
import { useOverviewStore } from '@/stores/overviewStore'

/**
 * Wie viele Kacheln höchstens ohne Aufklappen stehen (#226). Sechs, weil zwei
 * Spalten mal drei Reihen ein ruhiges Raster geben; der Rest kommt per Klick.
 */
const MAX_KACHELN = 6

/** Ein Statuseintrag der Legende — Reihenfolge = Balken. */
const STATUS = [
	{ key: 'neu', cls: 'pw-st--neu', label: (): string => t('projektwerk', 'Neu') },
	{ key: 'offen', cls: 'pw-st--offen', label: (): string => t('projektwerk', 'Offen') },
	{ key: 'wartet', cls: 'pw-st--wartet', label: (): string => t('projektwerk', 'Wartet') },
	{ key: 'erledigt', cls: 'pw-st--erl', label: (): string => t('projektwerk', 'Erledigt') },
] as const

/**
 * Die Projekt-Kacheln des Dashboards (#226) — je aktivem Projekt eine Kachel.
 *
 * Diese Komponente ordnet nur an: sie filtert leere Projekte aus, sortiert
 * angepinnte nach oben, begrenzt auf sechs (mit „weitere anzeigen") und reicht
 * die Zahlen an die geteilte `ProjectTile`-Komponente weiter. Dieselbe Kachel steht
 * im Projekt-Verzeichnis (#276), dort mit Pin-Toggle und ohne Deckel.
 */
export default defineComponent({
	name: 'ProjectTiles',

	components: { ProjectTile },

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
		/** Je Projekt eine Kachel mit Inhalt; angepinnte zuerst, dann nach Aufmerksamkeit. */
		rows(): ProjectTileData[] {
			return overviewProjectTiles(this.store.projectStatusRows as ProjectStatusRow[], this.boardStore.boards, this.boardStore.lastBoards)
				.filter((tile) => tile.offenGesamt > 0 || tile.erledigt > 0 || tile.verworfen > 0)
				.sort((a, b) => Number(b.pinned) - Number(a.pinned)
					|| AUFMERKSAMKEIT[a.zustand] - AUFMERKSAMKEIT[b.zustand]
					|| b.offenGesamt - a.offenGesamt
					|| a.title.localeCompare(b.title))
		},

		/** Die tatsächlich gezeigten — bis zum Aufklappen auf sechs begrenzt. */
		shown(): ProjectTileData[] {
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
		open(boardId: number): void {
			this.$router.push({ name: 'board', params: { boardId: String(boardId) } })
		},
	},
})
</script>
