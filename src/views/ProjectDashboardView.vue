<template>
	<div class="pw-view">
		<div v-if="loading" class="pw-stack">
			<div v-for="k in 3" :key="k" class="pw-skel">
				<i /><i /><i />
			</div>
		</div>

		<!-- Fehler vor Leerstand, wie im Überblick: Ein gescheitertes Laden darf
		     nicht als „Projekt ohne Vorgänge" durchgehen. -->
		<NcEmptyContent
			v-else-if="error !== null"
			:name="t('projektwerk', 'Das Projekt lässt sich nicht laden')"
			:description="error">
			<template #icon>
				<AlertCircleIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else-if="board !== null">
			<!-- Brotkrume zurück zum Überblick — ein echtes Ziel, kein Zurück-Rätsel. -->
			<router-link class="pw-crumb" :to="{ name: 'overview' }">
				<ChevronLeftIcon :size="16" />{{ t('projektwerk', 'Überblick') }}
			</router-link>

			<div class="pw-view__head pw-view__head--split">
				<div class="pw-view__ident">
					<h2>{{ board.title }}</h2>
					<div v-if="orgLine" class="pw-view__org">
						{{ orgLine }}
					</div>
				</div>
				<div class="pw-view__actions">
					<NcButton variant="primary" @click="openBoard">
						{{ t('projektwerk', 'Board öffnen') }}
						<template #icon>
							<ArrowRightIcon :size="18" />
						</template>
					</NcButton>
				</div>
			</div>

			<!-- Status-Kacheln: Label oben, Zahl groß, Überfällig rechts daneben. -->
			<div class="pw-pd__kpis">
				<div
					v-for="tile in statusTiles"
					:key="tile.key"
					class="pw-pd__kpi"
					:class="tile.cls">
					<div class="pw-pd__kpil">
						<i class="pw-pd__dot" />{{ tile.label }}
					</div>
					<div class="pw-pd__kpirow">
						<span class="pw-pd__kpin">{{ tile.count }}</span>
						<span v-if="tile.overdue > 0" class="pw-pd__over">
							<span class="pw-pd__overtri">▲</span>{{ overdueText(tile.overdue) }}
						</span>
					</div>
				</div>
			</div>

			<!-- Fortschritt -->
			<section class="pw-sect">
				<h3 class="pw-sect__h">
					{{ t('projektwerk', 'Fortschritt') }}
				</h3>
				<div class="pw-pd__prog">
					<div class="pw-pd__progbar">
						<div class="pw-pd__progfill" :style="{ width: fortschrittPct + '%' }" />
					</div>
					<span class="pw-pd__progtxt">{{ fortschrittText }}</span>
				</div>
			</section>

			<!-- Verteilung über die Phasen: die echten Board-Spalten als Balken. -->
			<section class="pw-sect">
				<h3 class="pw-sect__h">
					{{ t('projektwerk', 'Verteilung über die Phasen') }}
				</h3>
				<div class="pw-pd__phaselegend">
					<span v-for="p in summary.phasen" :key="p.id" class="pw-pd__pleg">
						<i class="pw-pd__pdot" :style="{ background: phaseColor(p.id) }" />{{ p.title }} {{ p.count }}
					</span>
				</div>
				<div v-if="offenPhasen.length > 0" class="pw-pd__phasebar">
					<span
						v-for="p in offenPhasen"
						:key="p.id"
						class="pw-pd__pseg"
						:style="{ flex: p.count, background: phaseColor(p.id) }">{{ p.count }}</span>
				</div>
				<p v-else class="pw-pd__empty">
					{{ t('projektwerk', 'Noch keine Vorgänge in diesem Projekt.') }}
				</p>
			</section>

			<!-- Offene Vorgänge -->
			<section class="pw-sect">
				<h3 class="pw-sect__h">
					{{ t('projektwerk', 'Offene Vorgänge') }}
				</h3>
				<table v-if="openRows.length > 0" class="pw-pd__table">
					<thead>
						<tr>
							<th>{{ t('projektwerk', 'Vorgang') }}</th>
							<th>{{ t('projektwerk', 'Phase') }}</th>
							<th>{{ t('projektwerk', 'Verantwortlich') }}</th>
							<th>{{ t('projektwerk', 'Fällig') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="ticket in openRows"
							:key="ticket.id"
							class="pw-pd__row"
							tabindex="0"
							role="button"
							@click="openTicket(ticket.id)"
							@keydown.enter="openTicket(ticket.id)"
							@keydown.space.prevent="openTicket(ticket.id)">
							<td>{{ ticket.title }}</td>
							<td><span class="pw-pd__phase">{{ columnTitle(ticket.columnId) }}</span></td>
							<td>{{ ticket.responsibleUserId ? nameOf(ticket.responsibleUserId) : '—' }}</td>
							<td :class="{ 'pw-pd__late': overdueRow(ticket) }">
								{{ ticket.dueDate ? germanShort(ticket.dueDate) : '—' }}
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="pw-pd__empty">
					{{ t('projektwerk', 'Alles abgearbeitet — kein offener Vorgang.') }}
				</p>
			</section>

			<div class="pw-pd__two">
				<!-- Zuletzt bearbeitet -->
				<section class="pw-sect">
					<h3 class="pw-sect__h">
						{{ t('projektwerk', 'Zuletzt bearbeitet') }}
					</h3>
					<table v-if="recentRows.length > 0" class="pw-pd__table">
						<tbody>
							<tr
								v-for="ticket in recentRows"
								:key="ticket.id"
								class="pw-pd__row"
								tabindex="0"
								role="button"
								@click="openTicket(ticket.id)"
								@keydown.enter="openTicket(ticket.id)"
								@keydown.space.prevent="openTicket(ticket.id)">
								<td>{{ ticket.title }}</td>
								<td class="pw-pd__when">
									<NcDateTime v-if="ticket.updatedAt" :timestamp="asMs(ticket.updatedAt)" relativeTime="long" />
								</td>
							</tr>
						</tbody>
					</table>
					<p v-else class="pw-pd__empty">
						{{ t('projektwerk', 'Noch nichts bearbeitet.') }}
					</p>
				</section>

				<!-- Zuletzt abgestellt -->
				<section class="pw-sect">
					<h3 class="pw-sect__h">
						{{ t('projektwerk', 'Zuletzt abgestellt') }}
					</h3>
					<table v-if="doneRows.length > 0" class="pw-pd__table">
						<tbody>
							<tr
								v-for="ticket in doneRows"
								:key="ticket.id"
								class="pw-pd__row"
								tabindex="0"
								role="button"
								@click="openTicket(ticket.id)"
								@keydown.enter="openTicket(ticket.id)"
								@keydown.space.prevent="openTicket(ticket.id)">
								<td>{{ ticket.title }}</td>
								<td class="pw-pd__when">
									<NcDateTime v-if="ticket.closedAt" :timestamp="asMs(ticket.closedAt)" relativeTime="long" />
								</td>
							</tr>
						</tbody>
					</table>
					<p v-else class="pw-pd__empty">
						{{ t('projektwerk', 'Noch nichts erledigt.') }}
					</p>
				</section>
			</div>
		</template>
	</div>
</template>

<script lang="ts">
// Bewusst `t`/`n` ohne Alias: die l10n-Extraktion erkennt nur den Alias-freien Import.
import type { Ticket } from '@/types/ticket'
import type { ProjectStatus, ProjectSummary } from '@/utils/projectDashboard'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import { useBoardStore } from '@/stores/boardStore'
import { germanDate, heute, isOverdue } from '@/utils/date'
import { openTickets, projectSummary, recentlyDone, recentlyUpdated } from '@/utils/projectDashboard'

/** Ein Statuseintrag der Kacheln — Reihenfolge = Anzeige. */
const STATUS_TILES: Array<{ key: ProjectStatus, cls: string, label: () => string, overdue?: keyof ProjectSummary['overdue'] }> = [
	{ key: 'neu', cls: 'pw-st--neu', label: (): string => t('projektwerk', 'Neu'), overdue: 'neu' },
	{ key: 'offen', cls: 'pw-st--offen', label: (): string => t('projektwerk', 'Offen'), overdue: 'offen' },
	{ key: 'wartet', cls: 'pw-st--wartet', label: (): string => t('projektwerk', 'Wartet'), overdue: 'wartet' },
	{ key: 'erledigt', cls: 'pw-st--erl', label: (): string => t('projektwerk', 'Erledigt') },
]

/**
 * Ersatzfarben für Phasen ohne eigene Spaltenfarbe — eine ruhige, sequenzielle
 * Reihe, damit der Balken auch bei ungefärbten Spalten lesbar bleibt.
 */
const PHASE_FALLBACK = ['#5b7fb3', '#0082c9', '#8a6d00', '#2d7b3f', '#767676', '#8a3ffc']

/**
 * Das Projekt-Dashboard (#227, Ebene 2) — Zustand genau eines Projekts.
 *
 * **Kein eigener Lesepfad.** Die Seite lädt dasselbe wie das Board
 * (`boardStore.open`: `board#show` + `ticket#index`, beide in der Leak-Matrix)
 * und rechnet daraus die Kacheln, den Fortschritt, die Phasen-Verteilung und die
 * Listen. Die Ableitung liegt rein in `@/utils/projectDashboard` und ist
 * dort ohne Browser geprüft; hier wird nur gezeigt.
 *
 * **Board = Projekt, aber nicht festgenagelt** (Konzept, Mehr-Board-Zukunft):
 * Die Aggregation nimmt eine Vorgangs- und Spaltenmenge; heute die eines Boards,
 * später die mehrerer eines Projekts.
 */
export default defineComponent({
	name: 'ProjectDashboardView',

	components: { NcButton, NcDateTime, NcEmptyContent, AlertCircleIcon, ArrowRightIcon, ChevronLeftIcon },

	setup() {
		return { store: useBoardStore() }
	},

	computed: {
		boardId(): number {
			return Number(this.$route.params.boardId)
		},

		loading(): boolean {
			return this.store.loading
		},

		error(): string | null {
			return this.store.error
		},

		board() {
			return this.store.board
		},

		orgLine(): string {
			return this.board === null ? '' : this.store.orgLine(this.board)
		},

		/** Alle sichtbaren Vorgänge des Boards als Feld (offen und geschlossen). */
		tickets(): Ticket[] {
			return [...this.store.tickets.values()]
		},

		summary(): ProjectSummary {
			return projectSummary(this.tickets, this.store.columns, this.store.waiting, heute())
		},

		statusTiles(): Array<{ key: string, cls: string, label: string, count: number, overdue: number }> {
			return STATUS_TILES.map((tile) => ({
				key: tile.key,
				cls: tile.cls,
				label: tile.label(),
				count: this.summary.counts[tile.key],
				overdue: tile.overdue ? this.summary.overdue[tile.overdue] : 0,
			}))
		},

		/** Nur Phasen mit Vorgängen tragen ein Balkensegment (kein Segment für „nichts"). */
		offenPhasen(): ProjectSummary['phasen'] {
			return this.summary.phasen.filter((p) => p.count > 0)
		},

		fortschrittPct(): number {
			return Math.round(this.summary.fortschritt * 100)
		},

		fortschrittText(): string {
			const gesamt = this.summary.counts.erledigt + this.summary.offenGesamt
			return t('projektwerk', '{done} von {total} erledigt · {pct} %', {
				done: String(this.summary.counts.erledigt),
				total: String(gesamt),
				pct: String(this.fortschrittPct),
			})
		},

		openRows(): Ticket[] {
			return openTickets(this.tickets, heute())
		},

		recentRows(): Ticket[] {
			return recentlyUpdated(this.tickets)
		},

		doneRows(): Ticket[] {
			return recentlyDone(this.tickets)
		},

		/** Spalten-Kennung → Titel, für die Phase-Spalte der Vorgangstabelle. */
		columnTitles(): Record<number, string> {
			return Object.fromEntries(this.store.columns.map((c) => [c.id, c.title]))
		},
	},

	watch: {
		boardId: {
			immediate: true,
			async handler(id: number) {
				await this.store.open(id)
			},
		},
	},

	methods: {
		t,

		nameOf(userId: string): string {
			return this.store.nameOf(userId)
		},

		germanShort(iso: string): string {
			// Ohne Jahr in der Tabelle — kürzer und im laufenden Jahr eindeutig.
			return germanDate(iso).replace(/\.\d{4}$/, '.')
		},

		/**
		 * @param anzahl Überfällige in dieser Kategorie.
		 */
		overdueText(anzahl: number): string {
			return n('projektwerk', '%n überfällig', '%n überfällig', anzahl)
		},

		/**
		 * @param ticket Der Vorgang.
		 */
		overdueRow(ticket: Ticket): boolean {
			return isOverdue(ticket.dueDate)
		},

		/**
		 * @param columnId Kennung der Spalte.
		 */
		columnTitle(columnId: number): string {
			return this.columnTitles[columnId] ?? ''
		},

		/**
		 * Die Farbe einer Phase: die eigene Spaltenfarbe, sonst eine ruhige
		 * Ersatzfarbe nach Position.
		 *
		 * @param columnId Kennung der Spalte.
		 */
		phaseColor(columnId: number): string {
			const column = this.store.columns.find((c) => c.id === columnId)
			if (column?.color) {
				return column.color
			}
			const index = this.store.columns.findIndex((c) => c.id === columnId)
			return PHASE_FALLBACK[index % PHASE_FALLBACK.length]
		},

		/**
		 * Ein ATOM-Zeitstempel als Millisekunden für NcDateTime.
		 *
		 * @param iso Zeitstempel.
		 */
		asMs(iso: string): number {
			return Date.parse(iso)
		},

		openBoard(): void {
			this.$router.push({ name: 'board', params: { boardId: String(this.boardId) } })
		},

		/**
		 * In das Board, mit dem Vorgang im Fokus — derselbe Weg wie ein Deep-Link.
		 *
		 * @param ticketId Kennung des Vorgangs.
		 */
		openTicket(ticketId: number): void {
			this.$router.push({ name: 'board', params: { boardId: String(this.boardId) }, query: { ticket: String(ticketId) } })
		},
	},
})
</script>
