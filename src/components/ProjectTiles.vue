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
			<!--
				Dieselbe Kachel wie im Projekt-Verzeichnis (#276), hier nur zur
				Anzeige: `pinnable` bleibt aus, der Stern zeigt an, klickt aber
				nicht. Der Klick auf die Kachel führt ins Projekt-Dashboard (#227).
			-->
			<ProjectTile
				v-for="row in shown"
				:key="row.boardId"
				:boardId="row.boardId"
				:title="row.title"
				:org="row.org"
				:neu="row.neu"
				:offen="row.offen"
				:wartet="row.wartet"
				:erledigt="row.erledigt"
				:neuDieseWoche="row.neuDieseWoche"
				:zustand="row.zustand"
				:pinned="isPinned(row.boardId)"
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
import type { ProjectStatusRow } from '@/types/overview'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import ProjectTile from '@/components/ProjectTile.vue'
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
		/** Kennungen der angepinnten Projekte, als Menge. */
		pinnedIds(): Set<number> {
			return new Set(this.boardStore.pinnedBoards.map((board) => board.id))
		},

		/**
		 * Die anzuzeigenden Projekte: leere (nichts offen, nichts erledigt/
		 * verworfen) fallen weg, angepinnte zuerst — stabile Teilung.
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
		 * Ins **Projekt-Dashboard** des Projekts (#227, Ebene 2) — nicht mehr
		 * direkt aufs Board. Von dort führt „Board öffnen" weiter aufs Kanban.
		 *
		 * @param boardId Kennung des Projekts.
		 */
		open(boardId: number): void {
			this.$router.push({ name: 'project-dashboard', params: { boardId: String(boardId) } })
		},
	},
})
</script>
