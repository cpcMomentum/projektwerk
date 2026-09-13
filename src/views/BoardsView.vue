<template>
	<div class="pw-view">
		<div class="pw-view__head">
			<h2>{{ t('projektwerk', 'Projekte') }}</h2>
			<NcButton variant="primary" @click="creating = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Neues Projekt') }}
			</NcButton>
		</div>

		<!--
			**Auf beide Quellen warten, nicht nur auf `store.loading`.** Die
			Kacheln joinen Board-Liste und Überblick; kaeme die Board-Liste
			zuerst zurueck, zeigten alle Kacheln kurz "Noch keine Vorgaenge",
			bis der Überblick nachzieht (#276).
		-->
		<div v-if="store.loading || overview.loading" class="pw-boards">
			<div v-for="n in 3" :key="n" class="pw-skel">
				<i /><i /><i />
			</div>
		</div>

		<!--
			Der Leerzustand bietet den Weg nach vorn selbst an: Wer noch kein
			Projekt hat, soll hier eines anlegen können und nicht erst den Knopf
			oben suchen. „Sobald Sie zu einem Projekt gehören" allein liesse eine
			neue Person ratlos zurück (#135).
		-->
		<NcEmptyContent
			v-else-if="store.boards.length === 0"
			:name="t('projektwerk', 'Noch kein Projekt')"
			:description="t('projektwerk', 'Legen Sie Ihr erstes Projekt an, oder warten Sie, bis Sie zu einem hinzugefügt werden.')">
			<template #icon>
				<FolderMultipleIcon :size="20" />
			</template>
			<template #action>
				<NcButton variant="primary" @click="creating = true">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('projektwerk', 'Neues Projekt') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<!--
				Das vollständige Projekt-Verzeichnis (#276): dieselben Kacheln wie
				im Überblick, aber über **alle** Projekte — auch leere und inaktive,
				die der Überblick ausblendet und bei sechs kappt. Hier wird
				angepinnt (Toggle in der Kachel) und angelegt.
			-->
			<div class="pw-tiles__legend">
				<span
					v-for="s in STATUS"
					:key="s.key"
					class="pw-tiles__leg"
					:class="s.cls">
					<i class="pw-tiles__legdot" />{{ s.label() }}
				</span>
			</div>

			<div class="pw-boards">
				<ProjectTile
					v-for="tile in tiles"
					:key="tile.boardId"
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
					pinnable
					@open="openDashboard"
					@togglePin="store.togglePin" />
			</div>
		</template>

		<CreateBoardWizard
			:open="creating"
			@update:open="creating = $event"
			@finished="openCreated" />
	</div>
</template>

<script lang="ts">
import type { Board } from '@/types/board'
import type { ProjectStatusRow } from '@/types/overview'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CreateBoardWizard from '@/components/CreateBoardWizard.vue'
import ProjectTile from '@/components/ProjectTile.vue'
import { useBoardStore } from '@/stores/boardStore'
import { useOverviewStore } from '@/stores/overviewStore'

/** Die Anzeigedaten einer Verzeichnis-Kachel. */
interface TileVM {
	boardId: number
	title: string
	org: string
	neu: number
	offen: number
	wartet: number
	erledigt: number
	neuDieseWoche: number
	zustand: ProjectStatusRow['zustand']
	pinned: boolean
}

/** Ein Statuseintrag der Legende — dieselbe Reihenfolge wie der Balken. */
const STATUS = [
	{ key: 'neu', cls: 'pw-st--neu', label: (): string => t('projektwerk', 'Neu') },
	{ key: 'offen', cls: 'pw-st--offen', label: (): string => t('projektwerk', 'Offen') },
	{ key: 'wartet', cls: 'pw-st--wartet', label: (): string => t('projektwerk', 'Wartet') },
	{ key: 'erledigt', cls: 'pw-st--erl', label: (): string => t('projektwerk', 'Erledigt') },
] as const

/**
 * „Projekte" — das vollständige Projekt-Verzeichnis (#276).
 *
 * **Zwei Quellen, hier zusammengeführt.** Welche Projekte es gibt, weiß der
 * `boardStore` (die Board-Liste, inkl. leerer und inaktiver). Die Statuszahlen
 * je Projekt liefert der `overviewStore` (`projectStatusRows`). Der Join per
 * `boardId` macht daraus die Kacheln; ein Projekt ohne Statuszeile (frisch
 * angelegt, keine Vorgänge) erscheint mit Nullwerten und dem Hinweis „Noch keine
 * Vorgänge".
 *
 * Der Unterschied zum Überblick ist bewusst: Der zeigt das Cockpit (Top-Projekte
 * gefiltert, gekappt), dieses Verzeichnis zeigt **alles** und ist der Ort zum
 * Anlegen und Anpinnen.
 */
export default defineComponent({
	name: 'BoardsView',

	components: { CreateBoardWizard, NcButton, NcEmptyContent, FolderMultipleIcon, PlusIcon, ProjectTile },

	setup() {
		return { store: useBoardStore(), overview: useOverviewStore(), STATUS }
	},

	data() {
		return {
			creating: false,
		}
	},

	computed: {
		/**
		 * Alle Projekte als Kacheln — Board-Liste als Quelle der Wahrheit, die
		 * Statuszahlen aus dem Überblick dazugejoint. Angepinnte zuerst, sonst
		 * alphabetisch: ein Verzeichnis wird durchgesehen, nicht nach Dringlichkeit
		 * gelesen (das ist der Überblick).
		 */
		tiles(): TileVM[] {
			const status = new Map((this.overview.projectStatusRows as ProjectStatusRow[]).map((row) => [row.boardId, row]))

			return (this.store.boards as Board[])
				.map((board): TileVM => {
					const s = status.get(board.id)

					return {
						boardId: board.id,
						title: board.title,
						org: this.store.orgLine(board),
						neu: s?.neu ?? 0,
						offen: s?.offen ?? 0,
						wartet: s?.wartet ?? 0,
						erledigt: s?.erledigt ?? 0,
						neuDieseWoche: s?.neuDieseWoche ?? 0,
						zustand: s?.zustand ?? 'gruen',
						pinned: board.pinned === true,
					}
				})
				.sort((a, b) => Number(b.pinned) - Number(a.pinned) || a.title.localeCompare(b.title))
		},
	},

	mounted() {
		// Beide Quellen: die Board-Liste (welche Projekte) und der Überblick
		// (Statuszahlen). Sie laufen unabhängig; die Kacheln stehen, sobald die
		// Board-Liste da ist, und füllen sich mit den Zahlen, wenn der Überblick
		// nachkommt.
		this.store.loadBoards()
		this.overview.load()
	},

	methods: {
		t,

		/**
		 * Kachel-Klick → **Projekt-Dashboard** (#276), einheitlich mit dem
		 * Überblick. Von dort führt „Board öffnen" weiter aufs Kanban.
		 *
		 * @param boardId Kennung des Projekts.
		 */
		openDashboard(boardId: number) {
			this.$router.push({ name: 'project-dashboard', params: { boardId: String(boardId) } })
		},

		/**
		 * Nach dem Anlegen → direkt ins **Board** (Kanban), nicht ins Dashboard:
		 * Ein frisches Projekt ist leer, und der erste Schritt ist ein Vorgang,
		 * nicht der Überblick über keine. Der Assistent meldet die Kennung über
		 * `@finished`.
		 *
		 * @param boardId Kennung des Projekts.
		 */
		openCreated(boardId: number) {
			this.creating = false
			this.$router.push({ name: 'board', params: { boardId: String(boardId) } })
		},
	},
})
</script>
