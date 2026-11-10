<template>
	<div class="pw-view">
		<div class="pw-view__head">
			<h2>{{ t('projektwerk', 'Projekte') }}</h2>
			<NcCheckboxRadioSwitch
				class="pw-boards__archswitch"
				:modelValue="showArchived"
				type="switch"
				@update:modelValue="setShowArchived">
				{{ t('projektwerk', 'Archivierte zeigen') }}
			</NcCheckboxRadioSwitch>
			<!--
				Kein „Neues Projekt" für Gäste (#280): Ein Gast, der ein Projekt
				anlegt, würde darin intern und Manager. Der Server verweigert die
				Anlage ohnehin (403); dieses Signal blendet den Knopf gleich aus.
			-->
			<NcButton v-if="canCreateProject" variant="primary" @click="creating = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Neues Projekt') }}
			</NcButton>
		</div>

		<div v-if="store.loading" class="pw-boards">
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
			v-else-if="sourceBoards.length === 0"
			:name="t('projektwerk', 'Noch kein Projekt')"
			:description="t('projektwerk', 'Legen Sie Ihr erstes Projekt an, oder warten Sie, bis Sie zu einem hinzugefügt werden.')">
			<template #icon>
				<FolderMultipleIcon :size="20" />
			</template>
			<template v-if="canCreateProject" #action>
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
			<div v-if="store.customerSuggestions.length >= 2" class="pw-tiles__filter">
				<label class="hidden-visually" for="pw-customer-filter">{{ t('projektwerk', 'Kunde') }}</label>
				<select id="pw-customer-filter" v-model="customerFilter" class="pw-tiles__filterselect">
					<option value="">
						{{ t('projektwerk', 'Alle Kunden') }}
					</option>
					<option v-for="c in store.customerSuggestions" :key="c" :value="c">
						{{ c }}
					</option>
				</select>
			</div>

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
					:archived="tile.archived"
					:pinnable="!tile.archived || tile.pinned"
					@open="openBoard"
					@togglePin="togglePin(tile)" />
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

import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CreateBoardWizard from '@/components/CreateBoardWizard.vue'
import ProjectTile from '@/components/ProjectTile.vue'
import { fetchBoards, setBoardPin } from '@/services/boards'
import { showError } from '@/services/toast'
import { useBoardStore } from '@/stores/boardStore'
import { useOverviewStore } from '@/stores/overviewStore'

/** Die Anzeigedaten einer Verzeichnis-Kachel. */
interface TileVM {
	key: string
	projectId: number | null
	/** Das Board, das die Kachel öffnet. */
	boardId: number
	/** Alle Boards des Projekts in der Liste. */
	boardIds: number[]
	archived: boolean
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

/** Rot oder gelb an einem Board färbt das Projekt; still steht es erst, wenn sich auf keinem Board etwas bewegt. */
const ZUSTAND_RANG: ProjectStatusRow['zustand'][] = ['rot', 'gelb', 'gruen', 'grau']

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

	components: { CreateBoardWizard, NcButton, NcCheckboxRadioSwitch, NcEmptyContent, FolderMultipleIcon, PlusIcon, ProjectTile },

	setup() {
		return { store: useBoardStore(), overview: useOverviewStore(), STATUS }
	},

	data() {
		return {
			creating: false,
			// Kundenfilter (#309): leer = alle. Rein clientseitig über die schon
			// geladene Board-Liste, kein eigener Lesepfad.
			customerFilter: '',
			// Ob die Person Projekte anlegen darf (#280) — vom Server als
			// Initial-State geliefert (Gäste: false). Default true ist unkritisch:
			// die echte Sperre sitzt serverseitig (403), dies blendet nur den
			// Knopf aus.
			canCreateProject: loadState<boolean>('projektwerk', 'canCreateProject', true),
			showArchived: false,
			/** Der zuletzt gewünschte Stand des Schalters; eine späte Antwort darf ihn nicht überholen. */
			gewuenschtArchiviert: false,
			/** Mit archivierten Boards und Projekten; nur geladen, solange der Schalter an ist. */
			allBoards: [] as Board[],
		}
	},

	computed: {
		/**
		 * Alle Projekte als Kacheln — Board-Liste als Quelle der Wahrheit, die
		 * Statuszahlen aus dem Überblick dazugejoint. Angepinnte zuerst, sonst
		 * alphabetisch: ein Verzeichnis wird durchgesehen, nicht nach Dringlichkeit
		 * gelesen (das ist der Überblick).
		 */
		sourceBoards(): Board[] {
			return this.showArchived ? this.allBoards : this.store.boards
		},

		/** Eine Kachel je Projekt, die Statuszahlen über seine Boards summiert; archivierte hinten. */
		tiles(): TileVM[] {
			const status = new Map((this.overview.projectStatusRows as ProjectStatusRow[]).map((row) => [row.boardId, row]))
			const zuletztJeProjekt = this.store.lastBoards
			const gruppen = new Map<string, Board[]>()
			for (const board of this.sourceBoards) {
				const key = board.projectId === null || board.projectId === undefined ? `b${board.id}` : `p${board.projectId}`
				gruppen.set(key, [...(gruppen.get(key) ?? []), board])
			}

			return [...gruppen.entries()]
				.map(([key, boards]): TileVM => {
					const erstes = boards[0]
					const aktive = boards.filter((b) => !b.archived)
					const zuletzt = erstes.projectId === null || erstes.projectId === undefined ? null : zuletztJeProjekt[String(erstes.projectId)]
					const ziel = aktive.find((b) => b.id === zuletzt) ?? aktive[0] ?? erstes
					const archiviert = erstes.projectArchived === true || aktive.length === 0
					const zeilen = boards.map((b) => status.get(b.id)).filter((r): r is ProjectStatusRow => r !== undefined)
					const summe = (feld: 'neu' | 'offen' | 'wartet' | 'erledigt' | 'neuDieseWoche'): number => zeilen.reduce((n, r) => n + (r[feld] ?? 0), 0)

					return {
						key,
						projectId: erstes.projectId ?? null,
						boardId: ziel.id,
						boardIds: boards.map((b) => b.id),
						archived: archiviert,
						title: erstes.projectTitle ?? erstes.title,
						// Solange kein Kunde gepflegt ist, bleibt die eigene Firma als Rückfall stehen.
						org: erstes.customer ?? this.store.orgLine(erstes),
						neu: summe('neu'),
						offen: summe('offen'),
						wartet: summe('wartet'),
						erledigt: summe('erledigt'),
						neuDieseWoche: summe('neuDieseWoche'),
						zustand: archiviert ? 'grau' : (ZUSTAND_RANG.find((z) => zeilen.some((r) => r.zustand === z)) ?? 'gruen'),
						pinned: boards.some((b) => (this.store.boards.find((s) => s.id === b.id) ?? b).pinned === true),
					}
				})
				.filter((tile) => this.customerFilter === '' || this.sourceBoards.find((b) => b.id === tile.boardId)?.customer === this.customerFilter)
				.sort((a, b) => Number(a.archived) - Number(b.archived) || Number(b.pinned) - Number(a.pinned) || a.title.localeCompare(b.title))
		},
	},

	watch: {
		'store.boards': function() {
			if (this.showArchived) {
				this.setShowArchived(true)
			}
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

		async setShowArchived(an: boolean): Promise<void> {
			this.gewuenschtArchiviert = an
			if (an) {
				try {
					const alle = await fetchBoards(true)
					if (!this.gewuenschtArchiviert) {
						return
					}
					this.allBoards = alle
				} catch (e) {
					showError((e as { message?: string }).message ?? t('projektwerk', 'Archivierte Projekte konnten nicht geladen werden'))
					return
				}
			}
			this.showArchived = an
		},

		// Angepinnt ist ein Projekt, wenn eines seiner Boards angepinnt ist; Lösen löst alle.
		async togglePin(tile: TileVM): Promise<void> {
			if (!tile.pinned) {
				await this.store.togglePin(tile.boardId)
				return
			}
			try {
				for (const board of this.sourceBoards.filter((b) => tile.boardIds.includes(b.id))) {
					const imStore = this.store.boards.find((s) => s.id === board.id)
					if (imStore !== undefined) {
						if (imStore.pinned === true) {
							await this.store.togglePin(board.id)
						}
					} else if (board.pinned === true) {
						await setBoardPin(board.id, false)
						board.pinned = false
					}
				}
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Anpinnen fehlgeschlagen'))
			}
		},

		/**
		 * @param boardId Kennung des Projekts.
		 */
		openBoard(boardId: number) {
			this.$router.push({ name: 'board', params: { boardId: String(boardId) } })
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
