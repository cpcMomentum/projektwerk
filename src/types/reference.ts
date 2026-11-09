/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Das Rich Object, das `TicketReferenceProvider` für die Vorschaukarte liefert. */
export interface TicketReference {
	id: number
	number: string
	title: string
	project: string
	board: string
	column: string
	closedOutcome: 'done' | 'discarded' | null
	responsible: { id: string, name: string } | null
	dueDate: string | null
	link: string
}
