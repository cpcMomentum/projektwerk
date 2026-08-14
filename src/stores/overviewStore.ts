/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Der Überblick — der Einstieg in die App (#76).
 *
 * Ein eigener Speicher neben `taskStore` und `boardStore`, aus demselben Grund
 * wie jener: Diese Ansicht gehört zu keinem Board. Ein `boardId`-freier Zustand
 * in einem Speicher, dessen ganzer Sinn das geöffnete Board ist, wäre eine
 * Einladung, das eine mit dem anderen zu verwechseln.
 *
 * **Beide Abschnitte entstehen hier, nicht auf dem Server.** Er liefert die
 * sichtbare Menge und den Wartezustand; welcher Vorgang in welchen Abschnitt
 * gehört und wie sortiert wird, ist eine Anzeigefrage. Eine zweite Sortierung
 * im Server wäre dieselbe Regel an zwei Orten.
 */

import type { OverviewData, ProjectRow, WaitingRow } from '@/types/overview'
import type { TaskBoard } from '@/types/task'
import type { Ticket } from '@/types/ticket'

import { defineStore } from 'pinia'
import { fetchOverview } from '@/services/overview'
import { showError } from '@/services/toast'

interface State {
	tickets: Ticket[]
	waiting: OverviewData['waiting']
	boards: Record<number, TaskBoard>
	names: OverviewData['names']
	loading: boolean
	/**
	 * Der heutige Tag, beim Laden festgehalten.
	 *
	 * **Im Zustand und nicht in der Rechnung** — dieselbe Lehre wie im
	 * `taskStore`: Ein Pinia-Getter merkt sich sein Ergebnis und rechnet nur bei
	 * Zustandsänderung neu; ein `new Date()` darin ist keine Abhängigkeit, die
	 * ihn dazu bringt. Diese Seite ist der Einstieg und steht damit am längsten
	 * offen — „seit 4 Tagen" bliebe sonst über Nacht stehen.
	 */
	today: string
	error: string | null
}

/**
 * Der heutige Tag als `JJJJ-MM-TT`.
 *
 * `since` ist ein Datum **ohne Uhrzeit**, und der Vergleich muss das bleiben.
 */
function heute(): string {
	const now = new Date()
	const zwei = (n: number): string => String(n).padStart(2, '0')

	return `${now.getFullYear()}-${zwei(now.getMonth() + 1)}-${zwei(now.getDate())}`
}

/**
 * Ganze Tage zwischen zwei Zeitpunkten.
 *
 * **`since` ist ein Zeitstempel, kein Datum** — es kommt aus `assigned_at` und
 * sieht so aus: `2026-08-13T21:14:14+00:00`. Der erste Anlauf hat an `-`
 * zerlegt und als Tag `13T21:14:14+00:00` bekommen; daraus wurde `NaN` und
 * daraus „seit heute", immer. Der Fehler war unsichtbar, weil die Zeile
 * trotzdem dastand — nur mit der falschen Zahl.
 *
 * Verglichen wird deshalb auf **Tagesgrenzen**: Der Zeitanteil wird vorn
 * abgeschnitten, und gerechnet wird über `Date.UTC`. Über die lokale Zeitzone
 * zählte der Wechsel von Sommer- auf Winterzeit als halber Tag und die Zahl
 * spränge.
 *
 * @param von Der frühere Zeitpunkt, ISO — mit oder ohne Uhrzeit.
 * @param bis Der spätere Tag als `JJJJ-MM-TT`.
 */
function tageZwischen(von: string, bis: string): number {
	const alsTag = (iso: string): number => {
		const [jahr, monat, tag] = iso.slice(0, 10).split('-').map(Number)

		return [jahr, monat, tag].every((teil) => Number.isFinite(teil))
			? Date.UTC(jahr, monat - 1, tag)
			: Number.NaN
	}

	const abstand = alsTag(bis) - alsTag(von)

	return Number.isFinite(abstand) ? Math.max(0, Math.round(abstand / 86_400_000)) : 0
}

export const useOverviewStore = defineStore('overview', {
	state: (): State => ({
		tickets: [],
		waiting: {},
		boards: {},
		names: {},
		loading: false,
		today: heute(),
		error: null,
	}),

	getters: {
		/**
		 * **Was bei der Kundenseite liegt** — die längste Wartezeit oben.
		 *
		 * Das ist die ganze Aussage des Abschnitts: Ein Vorgang, der seit zwölf
		 * Tagen dort liegt, ist die Nachricht; einer von gestern ist es nicht.
		 * Nach Datum aufsteigend zu sortieren ergäbe dieselbe Ordnung, sagt aber
		 * nicht, warum — deshalb steht die Zahl in der Zeile und ordnet sie.
		 *
		 * Die Namen kommen **aufgelöst** vom Server und werden je Projekt
		 * nachgeschlagen (#104: Kennungen auf einer Startseite wären der
		 * sichtbarste Ort für denselben Fehler).
		 *
		 * @param state Der Speicher.
		 */
		waitingRows: (state): WaitingRow[] => {
			const rows = state.tickets
				.map((ticket): WaitingRow | null => {
					const wait = state.waiting[ticket.id]
					if (wait === undefined) {
						return null
					}

					const proProjekt = state.names[ticket.boardId] ?? {}

					return {
						ticket,
						board: state.boards[ticket.boardId] ?? null,
						// Fällt der Name aus, bleibt die Kennung stehen — besser
						// als eine leere Stelle, und derselbe Rückfall wie in
						// `WaitBadge`.
						names: wait.userIds.map((userId) => proProjekt[userId] ?? userId),
						since: wait.since,
						days: tageZwischen(wait.since, state.today),
					}
				})
				.filter((row): row is WaitingRow => row !== null)

			return rows.sort((a, b) => b.days - a.days || a.ticket.id - b.ticket.id)
		},

		/**
		 * **Projekte mit Bewegung** — und nur die.
		 *
		 * Bei über zwanzig gleichzeitigen Projekten (Axel, 2026-08-13) wäre der
		 * Bestand eine Wand: zwanzig Zeilen, davon fünfzehn mit „nichts Neues".
		 * Gezeigt wird deshalb, was offene Vorgänge hat; wo nichts offen ist,
		 * gibt es nichts zu berichten.
		 *
		 * Sortiert nach Wartendem, dann nach Offenem: Ein Projekt, in dem etwas
		 * bei der Kundenseite liegt, ist dringlicher als eines mit viel eigener
		 * Arbeit.
		 *
		 * @param state Der Speicher.
		 */
		projectRows: (state): ProjectRow[] => {
			const proBoard = new Map<number, { open: number, waiting: number }>()

			for (const ticket of state.tickets) {
				const zahlen = proBoard.get(ticket.boardId) ?? { open: 0, waiting: 0 }
				zahlen.open += 1
				if (state.waiting[ticket.id] !== undefined) {
					zahlen.waiting += 1
				}
				proBoard.set(ticket.boardId, zahlen)
			}

			const rows = [...proBoard.entries()].map(([boardId, zahlen]): ProjectRow => {
				const board = state.boards[boardId] ?? null

				return {
					boardId,
					title: board?.title ?? '',
					// Beide Firmennamen, nicht nur der des Kunden: Trüge nur die
					// Gegenseite einen, wäre die eigene stumm „der Normalfall".
					org: [board?.orgInternal, board?.orgExternal].filter(Boolean).join(' · '),
					open: zahlen.open,
					waiting: zahlen.waiting,
				}
			})

			return rows.sort((a, b) => b.waiting - a.waiting || b.open - a.open || a.boardId - b.boardId)
		},

		/** Nichts offen, nirgends — der Leerzustand der ganzen Seite. */
		nothingOpen(): boolean {
			return (this.projectRows as ProjectRow[]).length === 0
		},
	},

	actions: {
		async load(): Promise<void> {
			this.loading = true
			this.error = null
			try {
				this.apply(await fetchOverview())
				// Der Tag wird beim Laden festgehalten, nicht in der Rechnung
				// bestimmt — sonst friert die Standdauer über Mitternacht ein.
				this.today = heute()
			} catch (e) {
				this.error = (e as { message?: string }).message ?? 'Unbekannter Fehler'
				showError(this.error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param data Die Antwort des Servers.
		 */
		apply(data: OverviewData): void {
			this.tickets = data.tickets
			this.waiting = data.waiting
			this.boards = data.boards
			this.names = data.names
		},
	},
})
