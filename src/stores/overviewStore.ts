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

import type { OverviewData, OverviewTicketRow, ProjectRow, ProjectStatusRow, WaitingRow } from '@/types/overview'
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
	/** Die eigene Kennung — für „Meine Vorgänge" (#120). */
	me: string
	/** Vorgänge mit offenem Schritt — für „liegt bei niemandem" (#119). */
	withOpenSteps: Set<number>
	/** Board => Zähler der erledigten/verworfenen Vorgänge (#226). */
	closedCounts: OverviewData['closedCounts']
	/** Board => Kennung der ersten Spalte, für den Status „Neu" (#226). */
	firstColumn: OverviewData['firstColumn']
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

/**
 * Ab wann ein Projekt als „steht still" gilt (#116/#226): so viele Tage ohne
 * Bewegung, **und nur wenn nichts auf den Kunden wartet**. Grob und ohne Frist,
 * wie im Überblick — ein Hinweis, keine Behauptung „zu spät".
 */
const STILLSTAND_TAGE = 14

export const useOverviewStore = defineStore('overview', {
	state: (): State => ({
		tickets: [],
		waiting: {},
		boards: {},
		names: {},
		me: '',
		withOpenSteps: new Set(),
		closedCounts: {},
		firstColumn: {},
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
			const proBoard = new Map<number, { open: number, waiting: number, movedDays: number }>()

			for (const ticket of state.tickets) {
				const zahlen = proBoard.get(ticket.boardId) ?? { open: 0, waiting: 0, movedDays: Number.POSITIVE_INFINITY }
				zahlen.open += 1
				if (state.waiting[ticket.id] !== undefined) {
					zahlen.waiting += 1
				}
				// Die letzte Bewegung des Projekts ist die jüngste seiner Vorgänge
				// — also die **kleinste** Zahl an Tagen. `tageZwischen` schneidet
				// die Uhrzeit ab und rechnet auf Tagesgrenzen, wie beim Warten.
				// Truthy-Prüfung statt `!== null`: fehlt der Wert (ältere Antwort,
				// Testdaten), zählt der Vorgang nicht zur Bewegung, statt zu werfen.
				if (ticket.updatedAt) {
					const tage = tageZwischen(ticket.updatedAt, state.today)
					if (tage < zahlen.movedDays) {
						zahlen.movedDays = tage
					}
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
					lastMovementDays: Number.isFinite(zahlen.movedDays) ? zahlen.movedDays : null,
				}
			})

			return rows.sort((a, b) => b.waiting - a.waiting || b.open - a.open || a.boardId - b.boardId)
		},

		/**
		 * **Die Projekt-Status-Tabelle** (#226) — je Projekt die kanonischen
		 * Status und ein abgeleitetes Zustandssignal.
		 *
		 * **Über alle aktiven Projekte**, nicht nur die mit offenen Vorgängen: Ein
		 * Projekt, dessen Arbeit erledigt ist, gehört mit seinem Fortschritt in die
		 * Übersicht, nicht aus ihr heraus. Neu/Offen/Wartet entstehen aus der
		 * offenen Menge (und dem Wartezustand, wie im Überblick), Erledigt kommt
		 * aus `closedCounts` vom Server.
		 *
		 * **Das Zustandssignal ist abgeleitet, nicht gepflegt** (Kernlehre der
		 * Recherche): rot bei echter Frist (ein offener Vorgang überfällig), gelb
		 * wenn der Ball beim Kunden liegt, grau bei Stillstand (nichts bewegt sich
		 * und niemand wartet), sonst grün. Sortiert nach Zustand — die
		 * Problemfälle oben.
		 *
		 * @param state Der Speicher.
		 */
		projectStatusRows: (state): ProjectStatusRow[] => {
			interface Roh { neu: number, offen: number, wartet: number, overdue: boolean, movedDays: number }
			const leer = (): Roh => ({ neu: 0, offen: 0, wartet: 0, overdue: false, movedDays: Number.POSITIVE_INFINITY })
			const proBoard = new Map<number, Roh>()

			for (const ticket of state.tickets) {
				const boardId = ticket.boardId
				const z = proBoard.get(boardId) ?? leer()

				// Wartet vor Neu: ein wartender Vorgang in der Eingangsspalte zählt
				// als wartend, nicht als neu — der Ball liegt schon beim Kunden.
				if (state.waiting[ticket.id] !== undefined) {
					z.wartet += 1
				} else if (state.firstColumn[boardId] !== undefined && ticket.columnId === state.firstColumn[boardId]) {
					z.neu += 1
				} else {
					z.offen += 1
				}

				// Überfällig: eine echte Frist ist verstrichen. Verglichen auf den
				// festgehaltenen Tag, damit es über Mitternacht nicht springt.
				if (ticket.dueDate !== null && ticket.dueDate < state.today) {
					z.overdue = true
				}

				// Jüngste Bewegung = kleinste Zahl an Tagen (wie in `projectRows`).
				if (ticket.updatedAt) {
					const tage = tageZwischen(ticket.updatedAt, state.today)
					if (tage < z.movedDays) {
						z.movedDays = tage
					}
				}

				proBoard.set(boardId, z)
			}

			const rang = { rot: 0, gelb: 1, grau: 2, gruen: 3 }

			return Object.keys(state.boards)
				.map(Number)
				.map((boardId): ProjectStatusRow => {
					const z = proBoard.get(boardId) ?? leer()
					const board = state.boards[boardId] ?? null
					const closed = state.closedCounts[boardId] ?? { done: 0, discarded: 0 }
					const offenGesamt = z.neu + z.offen + z.wartet
					const nenner = closed.done + offenGesamt
					const stillstand = z.wartet === 0
						&& Number.isFinite(z.movedDays)
						&& z.movedDays >= STILLSTAND_TAGE

					let zustand: ProjectStatusRow['zustand']
					if (z.overdue) {
						zustand = 'rot'
					} else if (z.wartet > 0) {
						zustand = 'gelb'
					} else if (stillstand) {
						zustand = 'grau'
					} else {
						zustand = 'gruen'
					}

					return {
						boardId,
						title: board?.title ?? '',
						org: [board?.orgInternal, board?.orgExternal].filter(Boolean).join(' · '),
						neu: z.neu,
						offen: z.offen,
						wartet: z.wartet,
						erledigt: closed.done,
						verworfen: closed.discarded,
						offenGesamt,
						fortschritt: nenner > 0 ? closed.done / nenner : 0,
						zustand,
					}
				})
				.sort((a, b) => rang[a.zustand] - rang[b.zustand] || b.offenGesamt - a.offenGesamt || a.boardId - b.boardId)
		},

		/**
		 * **Meine Vorgänge** (#120) — die, für die ich verantwortlich bin und die
		 * gerade **nicht** auf die Kundenseite warten.
		 *
		 * Die Ausgrenzung der wartenden ist die Antwort auf „nicht verdoppeln":
		 * Liegt der Ball beim Kunden, steht der Vorgang schon im Warte-Abschnitt;
		 * hier gehört, was bei **mir** liegt. Zwei-Achsen-Modell aus #114: bei mir
		 * gegen beim Kunden.
		 *
		 * Sortiert nach Fälligkeit, Überfälliges oben, dann Alter — dieselbe
		 * §9-Regel wie im gleichnamigen Abschnitt von „Meine Aufgaben".
		 *
		 * @param state Der Speicher.
		 */
		myTicketRows: (state): OverviewTicketRow[] => {
			const rows = state.tickets
				.filter((ticket) => ticket.responsibleUserId === state.me && state.waiting[ticket.id] === undefined)
				.map((ticket): OverviewTicketRow => ({ ticket, board: state.boards[ticket.boardId] ?? null }))

			return rows.sort((a, b) => {
				const dueA = a.ticket.dueDate
				const dueB = b.ticket.dueDate

				if (dueA !== dueB) {
					if (dueA === null) {
						return 1
					}
					if (dueB === null) {
						return -1
					}
					return dueA < dueB ? -1 : 1
				}

				return (a.ticket.createdAt ?? '').localeCompare(b.ticket.createdAt ?? '') || a.ticket.id - b.ticket.id
			})
		},

		/**
		 * **Liegt bei niemandem** (#119) — der dritte Ballbesitz-Zustand.
		 *
		 * Kein Verantwortlicher, kein offener Schritt, und es wartet auch nicht:
		 * Der Vorgang ist unbearbeitet, er liegt bei niemandem. Das ist eine
		 * eigene Auskunft, nicht „wartet" — der Wartebegriff hängt an einer
		 * externen Rolle, hier fehlt jede Zuweisung.
		 *
		 * Das am längsten Unbearbeitete zuerst: Es liegt am längsten brach.
		 *
		 * @param state Der Speicher.
		 */
		nobodyRows: (state): OverviewTicketRow[] => {
			const rows = state.tickets
				.filter((ticket) => (ticket.responsibleUserId === null || ticket.responsibleUserId === '')
					&& state.waiting[ticket.id] === undefined
					&& !state.withOpenSteps.has(ticket.id))
				.map((ticket): OverviewTicketRow => ({ ticket, board: state.boards[ticket.boardId] ?? null }))

			return rows.sort((a, b) => (a.ticket.createdAt ?? '').localeCompare(b.ticket.createdAt ?? '') || a.ticket.id - b.ticket.id)
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
			this.me = data.me
			this.withOpenSteps = new Set(data.withOpenSteps)
			this.closedCounts = data.closedCounts
			this.firstColumn = data.firstColumn
		},
	},
})
