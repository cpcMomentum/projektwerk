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
			offen": Die Tabellen sind ja leer. Das ist die unangenehmste Sorte
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
				**Zwei Hälften, eine Form** (#226, V3): oben „was bei mir liegt",
				unten „wie die Projekte stehen". Beide tragen dieselbe Tabellenform;
				die kleinen Kennzahlen sitzen über der jeweiligen Hälfte statt als
				eigene Ampel-Leiste. So liest die Seite als eine designte Einheit
				statt als Schichten aus Karten, Listen und Tabellen.
			-->
			<section class="pw-half">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Was bei mir liegt') }}
				</h3>

				<div class="pw-stats">
					<div class="pw-stat">
						<span class="pw-stat__lab">{{ t('projektwerk', 'Meine Maßnahmen') }}</span>
						<span class="pw-stat__num">{{ measureCount }}</span>
					</div>
					<div v-if="overdueCount > 0" class="pw-stat">
						<span class="pw-stat__lab">{{ t('projektwerk', 'davon überfällig') }}</span>
						<span class="pw-stat__num pw-stat__num--rot">{{ overdueCount }}</span>
					</div>
				</div>

				<template v-if="measureCount > 0">
					<MeasuresTable :rows="taskStore.measureRows" :limit="5" />
					<RouterLink
						v-if="measureCount > 5"
						class="pw-ov__more"
						:to="{ name: 'tasks' }">
						{{ t('projektwerk', 'Alle ansehen') }}
					</RouterLink>
				</template>
				<p v-else class="pw-empty-line">
					{{ t('projektwerk', 'Zurzeit liegt nichts bei dir.') }}
				</p>
			</section>

			<section v-if="hatProjekte" class="pw-half">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Wie die Projekte stehen') }}
				</h3>

				<div v-if="waitingLongCount > 0 || nobodyCount > 0 || stalledCount > 0" class="pw-stats">
					<div v-if="waitingLongCount > 0" class="pw-stat">
						<span class="pw-stat__lab">{{ t('projektwerk', 'Wartet lange') }}</span>
						<span class="pw-stat__num pw-stat__num--warn">{{ waitingLongCount }}</span>
					</div>
					<div v-if="nobodyCount > 0" class="pw-stat">
						<span class="pw-stat__lab">{{ t('projektwerk', 'Liegt bei niemandem') }}</span>
						<span class="pw-stat__num">{{ nobodyCount }}</span>
					</div>
					<div v-if="stalledCount > 0" class="pw-stat">
						<span class="pw-stat__lab">{{ t('projektwerk', 'Steht still') }}</span>
						<span class="pw-stat__num pw-stat__num--warn">{{ stalledCount }}</span>
					</div>
				</div>

				<ProjectStatusTable />
			</section>
		</template>
	</div>
</template>

<script lang="ts">
import type { ProjectStatusRow, WaitingRow } from '@/types/overview'
import type { MeasureRow } from '@/types/task'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
import MeasuresTable from '@/components/MeasuresTable.vue'
import ProjectStatusTable from '@/components/ProjectStatusTable.vue'
import { useOverviewStore } from '@/stores/overviewStore'
import { useTaskStore } from '@/stores/taskStore'

/**
 * Ab wann eine Wartezeit als „lange" zählt — eine Woche, grob und ohne Frist.
 * Dieselbe Grenze wie zuvor in der Ampel.
 */
const LANGE_WARTEZEIT = 7

/**
 * Der Überblick — der Einstieg in die App (#76), als Dashboard in zwei Hälften
 * (#226, V3): **was bei mir liegt** (meine Maßnahmen) und **wie die Projekte
 * stehen** (Status-Tabelle mit abgeleitetem Zustand).
 *
 * **Zwei Speicher.** Die Maßnahmen kommen aus „Meine Aufgaben" (`taskStore`),
 * die Projektzahlen aus dem Überblick (`overviewStore`). Beide werden hier
 * geladen; die Rechnung liegt in den Gettern, die Seite zeigt nur an.
 *
 * **Die frühere Ampel und die Wo-hakt-es-Listen sind entfallen.** Ihre Aussage
 * tragen jetzt die kleinen Kennzahlen über den Hälften und die Zustandsspalte
 * der Projekt-Tabelle; das einzelne wartende Ticket sieht man im Projekt.
 */
export default defineComponent({
	name: 'OverviewView',

	components: { AlertIcon, MeasuresTable, NcEmptyContent, ProjectStatusTable, ViewDashboardIcon },

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

		/** Wie viele davon überfällig sind. */
		overdueCount(): number {
			return (this.taskStore.measureRows as MeasureRow[]).filter((row) => row.overdue).length
		},

		/** Vorgänge, die seit über einer Woche auf die Kundenseite warten. */
		waitingLongCount(): number {
			return (this.store.waitingRows as WaitingRow[]).filter((row) => row.days >= LANGE_WARTEZEIT).length
		},

		/** Vorgänge ohne Verantwortlichen und ohne offenen Schritt. */
		nobodyCount(): number {
			return this.store.nobodyRows.length
		},

		/** Projekte, die stillstehen (Zustand grau). */
		stalledCount(): number {
			return (this.store.projectStatusRows as ProjectStatusRow[]).filter((row) => row.zustand === 'grau').length
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
