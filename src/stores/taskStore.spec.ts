/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Sortierregel aus §9: „nach Fälligkeit, dann Alter, Überfälliges oben".
 *
 * Sie ist der einzige Teil dieser Ansicht, der rechnet — alles andere ist
 * Anzeige. Und sie hat drei Ränder, an denen sie still falsch sein kann:
 *
 * - **Ohne Fälligkeit ans Ende.** Ein fehlendes Datum als „sofort" zu lesen
 *   wäre die naheliegende Verwechslung; dann stünde alles Undatierte oben.
 * - **Überfällig ist ein Datumsvergleich, kein Zeitpunktvergleich.** `dueDate`
 *   ist ein Datum ohne Uhrzeit; wer daraus einen Zeitpunkt macht, bekommt am
 *   Fälligkeitstag je nach Uhrzeit mal „überfällig" und mal nicht.
 * - **Ein Schritt ohne seinen Vorgang** darf keine Zeile ergeben — der Server
 *   liefert beides aus derselben gefilterten Menge, und eine halbe Antwort
 *   zeigt man nicht an.
 */

import type { Step, Ticket } from '@/types/ticket'

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useTaskStore } from '@/stores/taskStore'

vi.mock('@/services/tasks', () => ({ fetchTasks: () => Promise.resolve({ stepTickets: [], steps: [], tickets: [], boards: {} }) }))
vi.mock('@/services/steps', () => ({ updateStep: () => Promise.resolve() }))
vi.mock('@/services/toast', () => ({ showError: () => {} }))

/** Fester „heute"-Wert, damit „überfällig" nicht vom Testtag abhängt. */
const HEUTE = new Date('2026-08-09T09:00:00Z')

/**
 * @param id Kennung.
 * @param dueDate Fälligkeit als JJJJ-MM-TT oder null.
 * @param ticketId Zugehöriger Vorgang.
 */
function step(id: number, dueDate: string | null, ticketId = 1): Step {
	return { id, ticketId, title: 'Schritt ' + id, dueDate, done: false } as unknown as Step
}

/**
 * @param id Kennung.
 * @param createdAt Anlegezeitpunkt.
 */
function ticket(id: number, createdAt: string): Ticket {
	return { id, boardId: 1, number: id, title: 'Vorgang ' + id, createdAt } as unknown as Ticket
}

describe('Meine Aufgaben — Sortierung', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers()
		vi.setSystemTime(HEUTE)
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('sortiert nach Fälligkeit aufsteigend — Überfälliges steht dadurch oben', () => {
		const store = useTaskStore()
		store.apply({
			stepTickets: [ticket(1, '2026-01-01T00:00:00+00:00')],
			steps: [
				step(1, '2026-09-01'), // künftig
				step(2, '2026-07-01'), // überfällig, älter
				step(3, '2026-08-20'), // künftig, näher
				step(4, '2026-08-01'), // überfällig, jünger
			],
			tickets: [],
			boards: {},
		})

		// Ein eigener Rang für „überfällig" ist nicht nötig: Aufsteigend nach
		// Datum bringt das am weitesten Zurückliegende von selbst nach oben.
		expect(store.stepRows.map((r) => r.step.id)).toEqual([2, 4, 3, 1])
		expect(store.stepRows.map((r) => r.overdue)).toEqual([true, true, false, false])
	})

	it('stellt Schritte ohne Fälligkeit ans Ende, nicht an den Anfang', () => {
		const store = useTaskStore()
		store.apply({
			stepTickets: [ticket(1, '2026-01-01T00:00:00+00:00')],
			steps: [step(1, null), step(2, '2026-09-01'), step(3, null)],
			tickets: [],
			boards: {},
		})

		// Ein fehlendes Datum heisst „kein Termin", nicht „sofort".
		expect(store.stepRows.map((r) => r.step.id)).toEqual([2, 1, 3])
	})

	it('sortiert bei gleicher Fälligkeit nach Alter des Vorgangs', () => {
		const store = useTaskStore()
		store.apply({
			stepTickets: [
				ticket(1, '2026-05-01T00:00:00+00:00'),
				ticket(2, '2026-02-01T00:00:00+00:00'),
			],
			steps: [step(1, '2026-09-01', 1), step(2, '2026-09-01', 2)],
			tickets: [],
			boards: {},
		})

		// Das ältere Anliegen zuerst — Schritt 2 haengt am aelteren Vorgang.
		expect(store.stepRows.map((r) => r.step.id)).toEqual([2, 1])
	})

	it('zählt den Fälligkeitstag selbst noch nicht als überfällig', () => {
		const store = useTaskStore()
		store.apply({
			stepTickets: [ticket(1, '2026-01-01T00:00:00+00:00')],
			steps: [step(1, '2026-08-09'), step(2, '2026-08-08')],
			tickets: [],
			boards: {},
		})

		// Heute ist der 09.08.: Wer heute faellig ist, hat noch den Tag. Ein
		// Zeitpunktvergleich haette hier je nach Uhrzeit ein anderes Ergebnis.
		expect(store.stepRows.map((r) => r.overdue)).toEqual([true, false])
		expect(store.overdueCount).toBe(1)
	})

	it('zeigt keinen Schritt, dessen Vorgang fehlt', () => {
		const store = useTaskStore()
		store.apply({
			stepTickets: [ticket(1, '2026-01-01T00:00:00+00:00')],
			steps: [step(1, null, 1), step(2, null, 999)],
			tickets: [],
			boards: {},
		})

		// Eine Zeile ohne Herkunft waere auf einer projektuebergreifenden Seite
		// nicht handhabbar — lieber nichts als halb.
		expect(store.stepRows.map((r) => r.step.id)).toEqual([1])
	})

	it('friert „überfällig" nicht auf dem Ladetag ein', async () => {
		const store = useTaskStore()
		store.apply({
			stepTickets: [ticket(1, '2026-01-01T00:00:00+00:00')],
			steps: [step(1, '2026-08-10')],
			tickets: [],
			boards: {},
		})
		store.today = '2026-08-09'

		// Am 09.08. ist der 10.08. noch nicht ueberfaellig.
		expect(store.stepRows[0].overdue).toBe(false)

		// Die Seite bleibt ueber Nacht offen. Ein `new Date()` **im** Getter
		// waere keine Abhaengigkeit, die ihn neu rechnen laesst — der Tag muss
		// deshalb im Zustand liegen und beim Laden nachgezogen werden.
		vi.setSystemTime(new Date('2026-08-11T09:00:00Z'))
		await store.load()

		expect(store.today).toBe('2026-08-11')
	})

	it('gibt die Zeile nach einem Fehlschlag wieder frei', async () => {
		const store = useTaskStore()
		store.apply({
			stepTickets: [ticket(1, '2026-01-01T00:00:00+00:00')],
			steps: [step(1, null)],
			tickets: [],
			boards: {},
		})

		await store.completeStep(store.stepRows[0])

		// Bliebe die Kennung stehen, waere das Kaestchen dieser Zeile fuer
		// immer gesperrt — der Nutzer koennte den Schritt nie erledigen.
		expect(store.busySteps).toEqual([])
	})

	it('reicht die Herkunftszeile an die Zeile durch', () => {
		const store = useTaskStore()
		store.apply({
			stepTickets: [ticket(1, '2026-01-01T00:00:00+00:00')],
			steps: [step(1, null)],
			tickets: [],
			boards: { 1: { title: 'Relaunch Website', orgInternal: 'cpcMomentum', orgExternal: 'Müller' } },
		})

		expect(store.stepRows[0].board?.title).toBe('Relaunch Website')
	})
})
