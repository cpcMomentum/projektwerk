/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Sicht auf ältere Erledigte (#59).
 *
 * Geprüft wird **eine** Regel und ihre Ränder: Von den geschlossenen Vorgängen
 * einer Spalte bleiben die zuletzt geschlossenen zehn stehen, offene sind nie
 * betroffen, und der Zähler nennt genau die Differenz.
 *
 * Die Fehler, die hier drohen, brechen alle nichts — sie *entstellen* nur, und
 * genau das ist der Grund für diese Tests:
 *
 * - Ausgewählt wird nach Schliessdatum, angezeigt nach Spaltenposition. Wer
 *   beides verwechselt, bekommt eine Spalte, die nach Datum sortiert aussieht.
 * - Ein offener Vorgang darf nie verschwinden, auch nicht in einer Spalte voller
 *   Erledigter.
 * - Bei „Nur wartend" ist nichts Geschlossenes in der Menge; ein Angebot
 *   „12 ältere anzeigen" führte dort ins Leere.
 */

import type { Ticket, TicketList } from '@/types/ticket'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CLOSED_TAIL, useBoardStore } from '@/stores/boardStore'

// Der Speicher wird hier ohne Server gefahren: Geprüft wird die Regel, nicht
// das Laden. `open()` braucht die beiden Aufrufe trotzdem, weil es der Pfad
// ist, an dem der Aufklappzustand hängt.
vi.mock('@/services/boards', () => ({
	fetchBoards: () => Promise.resolve([]),
	fetchBoard: (id: number) => Promise.resolve({
		board: { id },
		members: [],
		columns: [],
		viewer: null,
	}),
}))
const markTicketReadSpy = vi.hoisted(() => vi.fn(() => Promise.resolve()))
vi.mock('@/services/tickets', () => ({
	fetchTickets: () => Promise.resolve({ tickets: [], counts: {}, waiting: {} }),
	moveTicket: () => Promise.resolve(),
	markTicketRead: markTicketReadSpy,
}))
vi.mock('@/services/toast', () => ({ showError: () => {} }))

const COLUMN = 7

/**
 * Ein Ticket mit genau den Feldern, die die Regel liest.
 *
 * @param id Kennung.
 * @param closedAt Schliessdatum oder null für offen.
 */
function ticket(id: number, closedAt: string | null): Ticket {
	return { id, columnId: COLUMN, closedAt } as unknown as Ticket
}

/**
 * Den Speicher mit einer Spalte füllen — in der übergebenen Reihenfolge, die
 * damit die Spaltenreihenfolge ist.
 *
 * @param tickets Die Vorgänge der Spalte.
 */
function fill(tickets: Ticket[]) {
	const store = useBoardStore()
	store.tickets = new Map(tickets.map((t) => [t.id, t]))
	store.columnOrder = new Map([[COLUMN, tickets.map((t) => t.id)]])

	return store
}

/**
 * `closedAt` absteigend: Ticket 1 ist das zuletzt geschlossene.
 *
 * @param id Kennung; die kleinere Kennung ist das jüngere Datum.
 */
function closedRank(id: number): string {
	return `2026-08-${String(31 - id).padStart(2, '0')}T10:00:00+00:00`
}

describe('Ältere Erledigte', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sortiert nach Zeitpunkt, nicht nach Zeichenkette', () => {
		// Beide Vorgaenge meinen denselben Moment, geschrieben mit
		// unterschiedlichem Zeitzonenversatz — als Zeichenkette steht `+02:00`
		// vor `+01:00`, chronologisch ist es umgekehrt. Nextcloud liefert heute
		// durchgaengig UTC; dieser Test haelt fest, dass die Sortierung nicht
		// daran haengt.
		// Die neun Fuellvorgaenge liegen spaeter als beide, damit genau einer
		// der beiden wegfaellt — und zwar der chronologisch aeltere.
		const store = fill([
			ticket(1, '2026-10-25T02:30:00+02:00'), // = 00:30 UTC, aelter
			ticket(2, '2026-10-25T02:30:00+01:00'), // = 01:30 UTC, juenger
			...Array.from({ length: 9 }, (_, i) => ticket(i + 3, `2026-12-0${i + 1}T00:00:00+00:00`)),
		])

		// Elf Geschlossene, einer faellt weg — und zwar der aelteste, also #1.
		const shown = store.ticketsIn(COLUMN).map((t) => t.id)

		expect(shown).not.toContain(1)
		expect(shown).toContain(2)
	})

	it('lässt eine Spalte unangetastet, solange nicht mehr als zehn geschlossen sind', () => {
		const store = fill(Array.from({ length: CLOSED_TAIL }, (_, i) => ticket(i + 1, closedRank(i + 1))))

		expect(store.ticketsIn(COLUMN)).toHaveLength(CLOSED_TAIL)
		expect(store.hiddenClosedCount(COLUMN)).toBe(0)
	})

	it('behält die zuletzt geschlossenen zehn und zählt den Rest', () => {
		// 14 geschlossene Vorgaenge; Kennung 1 ist das juengste Schliessdatum.
		const store = fill(Array.from({ length: 14 }, (_, i) => ticket(i + 1, closedRank(i + 1))))

		const shown = store.ticketsIn(COLUMN).map((t) => t.id)

		expect(shown).toHaveLength(CLOSED_TAIL)
		expect(shown).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
		expect(store.hiddenClosedCount(COLUMN)).toBe(4)
	})

	it('wählt nach Schliessdatum aus, zeigt aber in Spaltenreihenfolge', () => {
		// Die Spaltenreihenfolge ist hier absichtlich die **umgekehrte** des
		// Schliessens: Wer nach Datum sortiert statt nur auszuwaehlen, dreht die
		// Spalte um — und niemand koennte erklaeren, warum sie anders aussieht
		// als vor dem letzten Schliessen.
		const ids = Array.from({ length: 12 }, (_, i) => 12 - i)
		const store = fill(ids.map((id) => ticket(id, closedRank(id))))

		const shown = store.ticketsIn(COLUMN).map((t) => t.id)

		// Ausgeblendet sind die beiden aeltesten (11 und 12), der Rest steht in
		// der Reihenfolge der Spalte.
		expect(shown).toEqual([10, 9, 8, 7, 6, 5, 4, 3, 2, 1])
	})

	it('blendet niemals einen offenen Vorgang aus', () => {
		const store = fill([
			ticket(100, null),
			...Array.from({ length: 20 }, (_, i) => ticket(i + 1, closedRank(i + 1))),
			ticket(101, null),
		])

		const shown = store.ticketsIn(COLUMN).map((t) => t.id)

		expect(shown).toContain(100)
		expect(shown).toContain(101)
		expect(shown).toHaveLength(2 + CLOSED_TAIL)
		// Der Zaehler nennt nur Geschlossene — die beiden Offenen zaehlen nicht mit.
		expect(store.hiddenClosedCount(COLUMN)).toBe(10)
	})

	it('zeigt nach dem Aufklappen alles und danach wieder nur den Rest', () => {
		const store = fill(Array.from({ length: 15 }, (_, i) => ticket(i + 1, closedRank(i + 1))))

		store.toggleOlder(COLUMN)
		expect(store.ticketsIn(COLUMN)).toHaveLength(15)
		expect(store.hiddenClosedCount(COLUMN)).toBe(0)

		store.toggleOlder(COLUMN)
		expect(store.ticketsIn(COLUMN)).toHaveLength(CLOSED_TAIL)
		expect(store.hiddenClosedCount(COLUMN)).toBe(5)
	})

	it('bietet bei „Nur wartend" nichts an, was das Aufklappen nicht zeigt', () => {
		const store = fill(Array.from({ length: 15 }, (_, i) => ticket(i + 1, closedRank(i + 1))))
		store.onlyWaiting = true

		// Ein geschlossener Vorgang wartet nie (E8) — die Spalte ist leer, und
		// ein Knopf „5 ältere anzeigen" führte ins Leere.
		expect(store.ticketsIn(COLUMN)).toHaveLength(0)
		expect(store.hiddenClosedCount(COLUMN)).toBe(0)
		// Auch der Umschalter darf dort nicht stehen — er haengt an
		// `collapsibleCount`, und der schweigt beim Filter ebenfalls. Sonst
		// stuende „Ältere wieder ausblenden" ueber einer leeren Spalte.
		expect(store.collapsibleCount(COLUMN)).toBe(0)
	})

	it('unterscheidet „gerade nichts verborgen" von „nichts zu verbergen"', () => {
		const store = fill(Array.from({ length: 15 }, (_, i) => ticket(i + 1, closedRank(i + 1))))
		store.toggleOlder(COLUMN)

		// Aufgeklappt ist nichts verborgen — aber es gaebe etwas zu verbergen,
		// und nur deshalb darf der Umschalter stehen bleiben.
		expect(store.hiddenClosedCount(COLUMN)).toBe(0)
		expect(store.collapsibleCount(COLUMN)).toBe(5)
	})

	it('nennt in der Kopfzahl die Spalte, nicht den Ausschnitt', () => {
		const store = fill(Array.from({ length: 30 }, (_, i) => ticket(i + 1, closedRank(i + 1))))

		// Die Kopfzeile liest `visibleIn().length`. Folgte sie `ticketsIn()`,
		// bliebe sie bei zehn stehen, waehrend das Team dreissig Vorgaenge
		// abschliesst — und widerspraeche der Rueckfrage beim Entfernen
		// derselben Spalte, die aus derselben Menge zaehlt.
		expect(store.visibleIn(COLUMN)).toHaveLength(30)
		expect(store.ticketsIn(COLUMN)).toHaveLength(CLOSED_TAIL)
	})

	it('hält die Spalten auseinander', () => {
		const store = fill(Array.from({ length: 12 }, (_, i) => ticket(i + 1, closedRank(i + 1))))
		store.columnOrder = new Map([...store.columnOrder, [99, []]])

		store.toggleOlder(99)

		// Aufgeklappt wurde eine andere Spalte; diese bleibt eingeklappt.
		expect(store.hiddenClosedCount(COLUMN)).toBe(2)
	})

	it('lässt eine aufgeklappte Spalte beim Neuladen desselben Boards offen', async () => {
		const store = fill(Array.from({ length: 15 }, (_, i) => ticket(i + 1, closedRank(i + 1))))
		store.board = { id: 574 } as never
		store.toggleOlder(COLUMN)

		// `open()` ist auch der allgemeine Nachladepfad — nach einem abgehakten
		// Arbeitsschritt, einem angelegten Vorgang, einem 409. Klappte er die
		// Spalte jedes Mal zu, verlöre der Nutzer dabei auch seine
		// Scrollposition, ohne dass irgendetwas es erklärte.
		await store.open(574)
		expect(store.expandedColumns).toContain(COLUMN)

		// Beim Wechsel auf ein anderes Board dagegen gehört der Zustand zum
		// vorigen und muss weg.
		await store.open(999)
		expect(store.expandedColumns).toEqual([])
	})

	it('liefert über visibleIn() den letzten Nachbarn der Spalte, nicht den letzten sichtbaren', () => {
		// Das Verschieben ans Ende einer Spalte nennt den letzten Nachbarn. Käme
		// der aus `ticketsIn()`, landete das Ticket vor den ausgeblendeten
		// Erledigten statt dahinter — sichtbar erst, wenn jemand aufklappt.
		const store = fill(Array.from({ length: 14 }, (_, i) => ticket(i + 1, closedRank(i + 1))))

		const lastShown = store.ticketsIn(COLUMN).at(-1)?.id
		const lastInColumn = store.visibleIn(COLUMN).at(-1)?.id

		expect(lastShown).toBe(10)
		expect(lastInColumn).toBe(14)
	})
})

/**
 * Der Lesestand (#79) — „geändert seit deinem Blick".
 *
 * Der Punkt hängt an zwei Bewegungen, die auseinandergehalten gehören: die
 * optimistische Anzeige (Punkt weg beim Öffnen) und der Serverruf, der den
 * Stand setzt. Genau ihre Kopplung war der Fehler, den das Review fand: Solange
 * der Ruf nur mit sichtbarem Punkt ausging, bekam ein Vorgang beim allerersten
 * Öffnen nie einen Stand — und danach nie einen Punkt.
 */
describe('Lesestand', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		markTicketReadSpy.mockClear()
	})

	it('übernimmt changed aus der geladenen Liste', () => {
		const store = useBoardStore()
		store.applyTickets({ tickets: [], counts: {}, waiting: {}, changed: { 42: true } } as unknown as TicketList)

		expect(store.changed[42]).toBe(true)
	})

	it('nimmt den Punkt weg und meldet den Stand an den Server, wenn etwas geändert war', async () => {
		const store = useBoardStore()
		store.board = { id: 5 } as never
		store.changed = { 42: true }

		await store.markRead(42)

		expect(store.changed[42]).toBeUndefined()
		expect(markTicketReadSpy).toHaveBeenCalledWith(5, 42)
	})

	it('meldet den Stand auch dann, wenn kein Punkt zu sehen war', async () => {
		// Der Kern der Regression: Ein erstmals geöffneter Vorgang hat keinen
		// Punkt (`changed` leer), braucht aber trotzdem einen Lesestand — sonst
		// bliebe er für diese Person auf ewig „nie gesehen" und zeigte nie eine
		// spätere Änderung an.
		const store = useBoardStore()
		store.board = { id: 5 } as never

		await store.markRead(42)

		expect(markTicketReadSpy).toHaveBeenCalledWith(5, 42)
	})

	it('tut nichts ohne geladenes Board', async () => {
		const store = useBoardStore()

		await store.markRead(42)

		expect(markTicketReadSpy).not.toHaveBeenCalled()
	})
})
