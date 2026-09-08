<template>
	<div class="pw-view">
		<div class="pw-view__head">
			<h2>{{ t('projektwerk', 'Überblick') }}</h2>
		</div>

		<div v-if="loading" class="pw-stack">
			<div v-for="n in 3" :key="n" class="pw-skel">
				<i /><i /><i />
			</div>
		</div>

		<!--
			**Der Fehlerfall vor dem Leerfall** — dieselbe Ordnung wie in
			`TasksView`. Ohne ihn behauptet ein gescheitertes Laden „nichts
			offen": Die Karten sind ja leer. Das ist die unangenehmste Sorte
			Falschaussage, und auf der Startseite die folgenreichste.
		-->
		<NcEmptyContent
			v-else-if="error !== null"
			:name="t('projektwerk', 'Der Überblick konnte nicht geladen werden')"
			:description="error">
			<template #icon>
				<AlertIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="istLeer"
			:name="t('projektwerk', 'Zurzeit hakt nichts.')"
			:description="t('projektwerk', 'Hier steht, was bei dir liegt und wie die Projekte stehen. Solange alle Vorgänge erledigt sind, bleibt die Seite leer.')">
			<template #icon>
				<ViewDashboardIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!--
				**Das Kachel-Dashboard** (#226): oben die Kennzahlen über alle
				Projekte (wo hakt es + Durchsatz), dann die Projekte als Kacheln,
				darunter meine Maßnahmen. Die Rechnung liegt in den Gettern und
				den Kind-Komponenten; die Seite ordnet nur an.
			-->
			<KennzahlenCard />

			<section class="pw-half">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Projekte') }}
				</h3>
				<ProjectTiles />
			</section>

			<section v-if="measureCount > 0" class="pw-half">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Meine Maßnahmen') }}
					<span class="pw-n">{{ measureCount }}</span>
				</h3>
				<MeasuresTable :rows="taskStore.measureRows" :limit="5" />
				<RouterLink
					v-if="measureCount > 5"
					class="pw-ov__more"
					:to="{ name: 'tasks' }">
					{{ t('projektwerk', 'Alle ansehen') }}
				</RouterLink>
			</section>
		</template>
	</div>
</template>

<script lang="ts">
import type { ProjectStatusRow } from '@/types/overview'
import type { MeasureRow } from '@/types/task'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
import KennzahlenCard from '@/components/KennzahlenCard.vue'
import MeasuresTable from '@/components/MeasuresTable.vue'
import ProjectTiles from '@/components/ProjectTiles.vue'
import { useOverviewStore } from '@/stores/overviewStore'
import { useTaskStore } from '@/stores/taskStore'

/**
 * Der Überblick — der Einstieg in die App (#76), als **Kachel-Dashboard** (#226):
 * Kennzahlen über alle Projekte (wo hakt es + Durchsatz), die Projekte als
 * Kacheln mit Status und Zustand, darunter meine Maßnahmen.
 *
 * **Zwei Speicher.** Die Projektzahlen aus dem Überblick (`overviewStore`), die
 * Maßnahmen aus „Meine Aufgaben" (`taskStore`). Beide werden hier geladen; die
 * Darstellung liegt in den Kind-Komponenten (`KennzahlenCard`, `ProjectTiles`,
 * `MeasuresTable`).
 */
export default defineComponent({
	name: 'OverviewView',

	components: { AlertIcon, KennzahlenCard, MeasuresTable, NcEmptyContent, ProjectTiles, ViewDashboardIcon },

	setup() {
		return { store: useOverviewStore(), taskStore: useTaskStore() }
	},

	computed: {
		/** Lädt eine der beiden Quellen noch? */
		loading(): boolean {
			return this.store.loading || this.taskStore.loading
		},

		/** Der erste Fehler, der auftrat — der Überblick zuerst. */
		error(): string | null {
			return this.store.error ?? this.taskStore.error
		},

		/** Wie viele Maßnahmen bei mir liegen. */
		measureCount(): number {
			return (this.taskStore.measureRows as MeasureRow[]).length
		},

		/** Gibt es überhaupt ein Projekt mit Inhalt zu zeigen? */
		hatProjekte(): boolean {
			return (this.store.projectStatusRows as ProjectStatusRow[])
				.some((row) => row.offenGesamt > 0 || row.erledigt > 0 || row.verworfen > 0)
		},

		/** Nichts bei mir und kein Projekt mit Inhalt — der Leerzustand. */
		istLeer(): boolean {
			return !this.hatProjekte && this.measureCount === 0
		},
	},

	created() {
		this.store.load()
		this.taskStore.load()
	},

	methods: { t },
})
</script>
