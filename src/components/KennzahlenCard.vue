<template>
	<div class="pw-kpicard">
		<!-- Wo hakt es: vier Mini-Balken zum Vergleichen. -->
		<div class="pw-kpicard__sec pw-kpicard__hot">
			<h3 class="pw-kpicard__h">
				{{ t('projektwerk', 'Wo hakt es') }}
			</h3>
			<div class="pw-mini">
				<template v-for="m in hotspots" :key="m.key">
					<span class="pw-mini__l">{{ m.label }}</span>
					<span class="pw-mini__track">
						<i class="pw-mini__fill" :class="m.cls" :style="fillStyle(m.count)" />
					</span>
					<span class="pw-mini__v" :class="{ 'pw-mini__v--rot': m.key === 'overdue' }">{{ m.count }}</span>
				</template>
			</div>
		</div>

		<!-- Durchsatz: zwei Werte untereinander, mit Veränderung zur Vorwoche.
		     Die Verlaufs-Kurve folgt mit der Tages-Zeitreihe (#232). -->
		<div class="pw-kpicard__sec pw-kpicard__dur">
			<h3 class="pw-kpicard__h">
				{{ t('projektwerk', 'Durchsatz') }}
			</h3>
			<div class="pw-dur">
				<div class="pw-dur__item">
					<span class="pw-dur__l">
						{{ t('projektwerk', 'Neu / Woche') }}
					</span>
					<span class="pw-dur__v">
						{{ store.durchsatz.neu }}
						<span class="pw-dur__delta" :class="deltaCls(store.durchsatz.neuDelta)">{{ deltaText(store.durchsatz.neuDelta) }}</span>
					</span>
				</div>
				<div class="pw-dur__item">
					<span class="pw-dur__l">
						{{ t('projektwerk', 'Erledigt / Woche') }}
					</span>
					<span class="pw-dur__v">
						{{ store.durchsatz.erledigt }}
						<span class="pw-dur__delta" :class="deltaCls(store.durchsatz.erledigtDelta)">{{ deltaText(store.durchsatz.erledigtDelta) }}</span>
					</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script lang="ts">
import type { ProjectStatusRow, WaitingRow } from '@/types/overview'
import type { MeasureRow } from '@/types/task'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import { useOverviewStore } from '@/stores/overviewStore'
import { useTaskStore } from '@/stores/taskStore'

/** Ab wann eine Wartezeit als „lange" zählt — eine Woche, grob und ohne Frist. */
const LANGE_WARTEZEIT = 7

/**
 * Die Kennzahlen-Karte des Dashboards (#226): links „Wo hakt es" als vier
 * Mini-Balken zum Vergleichen, rechts der Durchsatz.
 *
 * **Bewusst zwei Darstellungen** (mit Axel besprochen): Mini-Balken zum
 * Vergleichen vier unabhängiger Zahlen, die Kacheln daneben stapeln zum
 * Zusammensetzen. Die Zahlen kommen aus denselben Gettern wie der Rest — keine
 * zweite Wahrheit. „Überfällig" zählt die Maßnahmen (Schritte + Vorgänge),
 * konsistent mit der Maßnahmen-Tabelle.
 */
export default defineComponent({
	name: 'KennzahlenCard',

	setup() {
		return { store: useOverviewStore(), taskStore: useTaskStore() }
	},

	computed: {
		/**
		 * Die vier „wo hakt es"-Werte in Balken-Reihenfolge.
		 */
		hotspots(): Array<{ key: string, label: string, count: number, cls: string }> {
			const overdue = (this.taskStore.measureRows as MeasureRow[]).filter((row) => row.overdue).length
			const waitingLong = (this.store.waitingRows as WaitingRow[]).filter((row) => row.days >= LANGE_WARTEZEIT).length
			const nobody = this.store.nobodyRows.length
			const stalled = (this.store.projectStatusRows as ProjectStatusRow[]).filter((row) => row.zustand === 'grau').length

			return [
				{ key: 'overdue', label: t('projektwerk', 'Überfällig'), count: overdue, cls: 'pw-mini--rot' },
				{ key: 'waiting', label: t('projektwerk', 'Wartet lange'), count: waitingLong, cls: 'pw-mini--gelb' },
				{ key: 'nobody', label: t('projektwerk', 'Liegt bei niemandem'), count: nobody, cls: 'pw-mini--slate' },
				{ key: 'stalled', label: t('projektwerk', 'Steht still'), count: stalled, cls: 'pw-mini--grau' },
			]
		},

		/** Der größte der vier Werte — die Skala der Mini-Balken (mind. 1). */
		hotMax(): number {
			return Math.max(1, ...this.hotspots.map((m) => m.count))
		},
	},

	methods: {
		t,

		/**
		 * Die Füllbreite eines Mini-Balkens, auf den größten Wert skaliert. Ein
		 * Wert von 0 füllt nichts (die Mindestbreite greift nur ab 1, per CSS).
		 *
		 * @param count Der Wert.
		 */
		fillStyle(count: number): Record<string, string> {
			return count === 0
				? { width: '0' }
				: { width: `${Math.round((count / this.hotMax) * 100)}%` }
		},

		/**
		 * Die Veränderung zur Vorwoche als Text — Pfeil + Vorzeichen, Wort steht
		 * über die Farbe hinaus (§9).
		 *
		 * @param delta Die Differenz.
		 */
		deltaText(delta: number): string {
			if (delta > 0) {
				return `▲ +${delta}`
			}
			if (delta < 0) {
				return `▼ ${delta}`
			}
			return '± 0'
		},

		/**
		 * @param delta Die Differenz.
		 */
		deltaCls(delta: number): string {
			if (delta > 0) {
				return 'pw-dur__delta--up'
			}
			if (delta < 0) {
				return 'pw-dur__delta--down'
			}
			return 'pw-dur__delta--flat'
		},
	},
})
</script>
