/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Form von „Meine Aufgaben" — projektübergreifend.
 *
 * **Zwei Abschnitte, zwei Mengen.** `stepTickets` sind die Vorgänge, an denen
 * mir ein offener Schritt gehört; `tickets` die, für die ich verantwortlich
 * bin oder an denen ich mitarbeite. Sie überschneiden sich, sind aber
 * verschieden: Ein Schritt kann mir an einem Vorgang zugewiesen sein, an dem
 * ich weder das eine noch das andere bin.
 */

import type { Step, Ticket } from '@/types/ticket'

/** Die Herkunftszeile eines Vorgangs — beide Firmennamen, nicht nur einer. */
export interface TaskBoard {
	title: string
	orgInternal: string | null
	orgExternal: string | null
}

export interface TaskList {
	/** Die Vorgänge zu meinen offenen Schritten. */
	stepTickets: Ticket[]
	/** Genau diese Schritte — meine, offen. */
	steps: Step[]
	/** Verantwortlich oder mitarbeitend. */
	tickets: Ticket[]
	/** Board-Kennung => Herkunftszeile. Einmal je Board, nicht je Vorgang. */
	boards: Record<number, TaskBoard>
}

/**
 * Ein Schritt mit allem, was die Zeile über ihn zeigen muss.
 *
 * Der Vorgang und sein Board stehen dabei, weil eine Aufgabe ohne ihre
 * Herkunft nicht handhabbar ist: „Freigabe erteilen" sagt nichts, „Freigabe
 * erteilen · #0007 · Relaunch Website" sagt alles.
 */
export interface StepRow {
	step: Step
	ticket: Ticket
	board: TaskBoard | null
	/** Fälligkeit liegt in der Vergangenheit. */
	overdue: boolean
}
