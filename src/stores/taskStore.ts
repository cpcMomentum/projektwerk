/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * „Meine Aufgaben" — projektübergreifend.
 *
 * Ein eigener Speicher neben dem Board-Speicher, kein Anbau: Diese Ansicht
 * gehört zu keinem Board, und ein `boardId`-freier Zustand in einem Speicher,
 * dessen ganzer Sinn das geöffnete Board ist, wäre eine Einladung, das eine
 * mit dem anderen zu verwechseln.
 */

import type { StepRow, TaskBoard, TaskList } from '@/types/task'
import type { Step, Ticket } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { updateStep } from '@/services/steps'
import { fetchTasks } from '@/services/tasks'
import { showError } from '@/services/toast'
import { reportWriteError } from '@/services/writeError'

interface State {
	stepTickets: Ticket[]
	steps: Step[]
	/**
	 * Verantwortlich oder mitarbeitend.
	 *
	 * **Hier ist §9s Sortierregel nur halb umsetzbar:** „nach Fälligkeit, dann
	 * Alter, Überfälliges oben" — ein Ticket trägt heute keine Fälligkeit, nur
	 * der Arbeitsschritt. Es bleibt deshalb bei „nach Alter" in der Reihenfolge
	 * des Servers, und Überfälliges kann in diesem Abschnitt nicht oben stehen,
	 * weil es kein Überfällig gibt. Mit #72 wird die Regel in beiden
	 * Abschnitten dieselbe.
	 */
	tickets: Ticket[]
	boards: Record<number, TaskBoard>
	loading: boolean
	/**
	 * Die Schritte, deren Kästchen gerade laufen — je Zeile eines.
	 *
	 * Eine **Liste** und kein einzelner Wert: Wer fünf Haken hintereinander
	 * setzt, soll nicht auf jeden warten. Ein einzelner Wert hätte jeden
	 * weiteren Klick stumm verschluckt, während die Liste sich darunter neu
	 * ordnet — der nächste Klick träfe dann eine andere Zeile.
	 */
	busySteps: number[]
	/**
	 * Der heutige Tag, beim Laden festgehalten.
	 *
	 * **Im Zustand und nicht in der Rechnung.** Ein Pinia-Getter merkt sich sein
	 * Ergebnis und rechnet nur bei Zustandsänderung neu; ein `new Date()` darin
	 * ist keine Abhängigkeit, die ihn dazu bringt. Eine über Nacht offene Seite
	 * — und auf dem Handy ist das die Startseite — hätte sonst am nächsten
	 * Morgen noch die Überfällig-Marken von gestern.
	 */
	today: string
	error: string | null
}

/**
 * Der heutige Tag als `JJJJ-MM-TT`.
 *
 * `dueDate` ist ein **Datum ohne Uhrzeit** (`Types::DATE`), und der Vergleich
 * muss das bleiben: Wer daraus einen Zeitpunkt macht, bekommt am Fälligkeitstag
 * je nach Uhrzeit und Zeitzone mal „überfällig" und mal nicht.
 */
function heute(): string {
	const now = new Date()
	const zwei = (n: number): string => String(n).padStart(2, '0')

	return `${now.getFullYear()}-${zwei(now.getMonth() + 1)}-${zwei(now.getDate())}`
}

export const useTaskStore = defineStore('tasks', {
	state: (): State => ({
		stepTickets: [],
		steps: [],
		tickets: [],
		boards: {},
		loading: false,
		busySteps: [],
		today: heute(),
		error: null,
	}),

	getters: {
		/**
		 * Meine offenen Schritte, sortiert — **das ist die Regel aus §9:**
		 * „nach Fälligkeit, dann Alter, Überfälliges oben".
		 *
		 * Aufsteigend nach Fälligkeit bringt Überfälliges von selbst nach oben,
		 * denn dessen Datum liegt am weitesten zurück. Ein eigener Rang für
		 * „überfällig" wäre dieselbe Ordnung zweimal ausgedrückt — die Marke an
		 * der Zeile macht ihn sichtbar, die Sortierung braucht ihn nicht.
		 *
		 * **Ohne Fälligkeit ans Ende**, danach nach Alter. Ein Schritt ohne
		 * Datum ist nicht dringlicher als einer mit; ihn vorn einzusortieren
		 * hieße, ein fehlendes Datum als „sofort" zu lesen.
		 *
		 * @param state Der Speicher.
		 */
		stepRows: (state): StepRow[] => {
			const byId = new Map(state.stepTickets.map((t) => [t.id, t]))
			const grenze = state.today

			const rows = state.steps
				.map((step): StepRow | null => {
					const ticket = byId.get(step.ticketId)
					if (ticket === undefined) {
						// Ein Schritt ohne seinen Vorgang ist nichts, was die
						// Ansicht anzeigen darf: Der Server liefert beides aus
						// derselben gefilterten Menge, und wenn hier eines fehlt,
						// ist die Antwort halb — dann lieber nichts als eine
						// Zeile ohne Herkunft.
						return null
					}

					return {
						step,
						ticket,
						board: state.boards[ticket.boardId] ?? null,
						overdue: step.dueDate !== null && step.dueDate < grenze,
					}
				})
				.filter((row): row is StepRow => row !== null)

			return rows.sort((a, b) => {
				const dueA = a.step.dueDate
				const dueB = b.step.dueDate

				if (dueA !== dueB) {
					if (dueA === null) {
						return 1
					}
					if (dueB === null) {
						return -1
					}
					return dueA < dueB ? -1 : 1
				}

				// Gleiche Fälligkeit (oder beide ohne): das Ältere zuerst.
				return (a.ticket.createdAt ?? '').localeCompare(b.ticket.createdAt ?? '')
					|| a.step.id - b.step.id
			})
		},

		/**
		 * Wie viele meiner Schritte überfällig sind.
		 *
		 * Aus derselben Rechnung wie die Marken selbst — ein eigener Zähler wäre
		 * der zweite Ort, an dem die Grenze stimmen müsste.
		 */
		overdueCount(): number {
			return (this.stepRows as StepRow[]).filter((row) => row.overdue).length
		},

		/**
		 * Die Herkunftszeile eines Vorgangs.
		 *
		 * @param state Der Speicher.
		 */
		boardOf: (state) => (ticket: Ticket): TaskBoard | null => state.boards[ticket.boardId] ?? null,
	},

	actions: {
		/**
		 * @param silent Ohne Ladeanzeige — für das Nachladen nach einem Häkchen.
		 *               Die ganze Liste zu Skeletten zu machen, weil eine Zeile
		 *               verschwindet, lässt die Seite flackern und den nächsten
		 *               Klick auf eine andere Zeile treffen.
		 */
		async load(silent = false): Promise<void> {
			this.loading = !silent
			this.error = null
			try {
				this.apply(await fetchTasks())
				// Der Tag wird beim Laden festgehalten, nicht in der Rechnung
				// bestimmt — sonst friert „überfällig" über Mitternacht ein.
				this.today = heute()
			} catch (e) {
				this.error = (e as { message?: string }).message ?? 'Unbekannter Fehler'
				showError(this.error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param list Die Antwort des Servers.
		 */
		apply(list: TaskList): void {
			this.stepTickets = list.stepTickets
			this.steps = list.steps
			this.tickets = list.tickets
			this.boards = list.boards
		},

		/**
		 * Einen Schritt erledigen — **ohne die Ansicht zu verlassen**.
		 *
		 * §9 nennt das den häufigsten Vorgang des Kunden; er darf keine drei
		 * Klicks kosten. Danach wird neu geladen statt lokal entfernt: Der
		 * Vorgang verschwindet nur dann aus dem Abschnitt, wenn es *sein
		 * letzter* offener Schritt war, und das weiß nur der Server. Lokal zu
		 * raten hiesse, eine Zeile zu entfernen, die gleich wiederkäme.
		 *
		 * Gesperrt wird nur diese eine Zeile, nicht die ganze Liste — wer fünf
		 * Häkchen hintereinander setzt, soll nicht auf jedes warten.
		 *
		 * @param row Die Zeile.
		 */
		async completeStep(row: StepRow): Promise<void> {
			if (this.busySteps.includes(row.step.id)) {
				return
			}
			this.busySteps = [...this.busySteps, row.step.id]
			try {
				await updateStep(row.ticket.boardId, row.step.id, { done: true })
				// Ohne Ladeanzeige: Die Zeile verschwindet, die uebrigen bleiben
				// stehen. Mit ihr flackerte die ganze Seite zu Skeletten.
				await this.load(true)
			} catch (e) {
				// **Neu laden auch im Fehlerfall**, damit die Liste den Stand
				// des Servers zeigt und nicht den, den der Klick vermutet hat.
				//
				// Gemeldet wird ueber `reportWriteError` wie ueberall sonst:
				// Der Versionskonflikt bekommt dort seinen eigenen Satz, und
				// ohne ihn stuende hier die rohe englische Axios-Meldung
				// („Network Error") vor einem deutschen Nutzer. Am laufenden
				// System gemessen, nicht vermutet.
				await this.load(true)
				reportWriteError(e, t('projektwerk', 'Arbeitsschritt konnte nicht erledigt werden'), true)
			} finally {
				this.busySteps = this.busySteps.filter((id) => id !== row.step.id)
			}
		},
	},
})
