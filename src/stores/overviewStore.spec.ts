/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Der Überblick — die beiden Abschnitte entstehen hier, nicht auf dem Server.
 *
 * Geprüft werden die drei Stellen, an denen diese Ansicht **rechnet**; alles
 * andere ist Anzeige:
 *
 * - **Die Standdauer.** `since` ist ein Zeitstempel (`assigned_at`), kein
 *   Datum. Der erste Anlauf hat an `-` zerlegt und als Tag
 *   `13T21:14:14+00:00` bekommen — daraus wurde `NaN` und daraus „seit heute",
 *   immer. Der Fehler war unsichtbar: Die Zeile stand da, nur mit der falschen
 *   Zahl. Kein Sichtbarkeitstest hätte ihn gefunden.
 * - **Die Zahlen je Projekt.** Sie kommen aus **derselben** Menge wie die
 *   Wartezeilen. Zwei Quellen hießen, dass sich die beiden Abschnitte einer
 *   Seite widersprechen können.
 * - **Namen statt Kennungen** (#104), und zwar je Projekt nachgeschlagen: Das
 *   Übersteuern des Anzeigenamens gilt pro Mitgliedschaft, nicht global.
 */

import type { OverviewData } from '@/types/overview'
import type { Ticket } from '@/types/ticket'

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useOverviewStore } from '@/stores/overviewStore'

vi.mock('@/services/overview', () => ({ fetchOverview: () => Promise.resolve({ tickets: [], waiting: {}, boards: {}, names: {} }) }))
vi.mock('@/services/toast', () => ({ showError: () => {} }))

/** Fester „heute"-Wert, damit die Standdauer nicht vom Testtag abhängt. */
const HEUTE = new Date('2026-08-13T09:00:00Z')

/**
 * @param id Kennung.
 * @param boardId Projekt.
 * @param title Titel.
 */
function ticket(id: number, boardId: number, title = 'Vorgang ' + id, updatedAt: string | null = null, responsibleUserId: string | null = null): Ticket {
	return { id, boardId, number: id, title, updatedAt, responsibleUserId } as unknown as Ticket
}

/**
 * @param teile Was für den jeweiligen Fall abweicht.
 */
function daten(teile: Partial<OverviewData> = {}): OverviewData {
	return {
		tickets: [],
		waiting: {},
		boards: { 1: { title: 'Relaunch', orgInternal: 'Wir', orgExternal: 'Kunde' } },
		names: {},
		me: '',
		withOpenSteps: [],
		...teile,
	}
}

describe('Überblick', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers()
		vi.setSystemTime(HEUTE)
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	/**
	 * **Der Zeitstempel darf die Rechnung nicht kippen.**
	 *
	 * Drei Ränder in einem Test: heute, gestern, und ein Zeitpunkt, dessen
	 * Uhrzeit später liegt als die aktuelle. Der letzte ist der wichtige — wer
	 * auf Zeitpunkten statt auf Tagesgrenzen rechnet, bekommt dort 11 statt 12.
	 */
	it('rechnet die Standdauer in ganzen Tagen', async () => {
		const store = useOverviewStore()
		store.apply(daten({
			tickets: [ticket(1, 1), ticket(2, 1), ticket(3, 1)],
			waiting: {
				1: { since: '2026-08-13T21:14:14+00:00', userIds: ['carla'] },
				2: { since: '2026-08-12T06:00:00+00:00', userIds: ['carla'] },
				3: { since: '2026-08-01T23:59:00+00:00', userIds: ['carla'] },
			},
		}))
		store.today = '2026-08-13'

		// Längste Wartezeit oben — das ist die Aussage des Abschnitts.
		expect(store.waitingRows.map((r) => [r.ticket.id, r.days])).toEqual([
			[3, 12],
			[2, 1],
			[1, 0],
		])
	})

	/**
	 * Namen kommen aus dem Projekt des Vorgangs, nicht aus einer flachen Liste.
	 *
	 * Dieselbe Person kann in zwei Projekten unter verschiedenen Namen stehen —
	 * `display_name` ist ein Übersteuern je Mitgliedschaft. Eine flache
	 * Zuordnung müsste eines der beiden gewinnen lassen, und welches, wäre
	 * Zufall der Reihenfolge.
	 */
	it('löst Namen je Projekt auf und fällt sonst auf die Kennung zurück', () => {
		const store = useOverviewStore()
		store.apply(daten({
			tickets: [ticket(1, 1), ticket(2, 2)],
			boards: {
				1: { title: 'Relaunch', orgInternal: null, orgExternal: null },
				2: { title: 'Umzug', orgInternal: null, orgExternal: null },
			},
			waiting: {
				1: { since: '2026-08-10T08:00:00+00:00', userIds: ['carla', 'ohne-namen'] },
				2: { since: '2026-08-10T08:00:00+00:00', userIds: ['carla'] },
			},
			names: {
				1: { carla: 'Carla Müller' },
				2: { carla: 'C. Müller (Einkauf)' },
			},
		}))
		store.today = '2026-08-13'

		const proTicket = new Map(store.waitingRows.map((r) => [r.ticket.id, r.names]))
		expect(proTicket.get(1)).toEqual(['Carla Müller', 'ohne-namen'])
		expect(proTicket.get(2)).toEqual(['C. Müller (Einkauf)'])
	})

	/**
	 * **Projekte mit Bewegung — und nur die.**
	 *
	 * Ein Projekt ohne offene Vorgänge erscheint nicht. Bei über zwanzig
	 * gleichzeitigen Projekten (Axel, 2026-08-13) wäre der Bestand eine Wand
	 * aus Zeilen mit „nichts Neues".
	 */
	it('zählt je Projekt und lässt leere weg', () => {
		const store = useOverviewStore()
		store.apply(daten({
			tickets: [ticket(1, 1), ticket(2, 1), ticket(3, 2)],
			boards: {
				1: { title: 'Relaunch', orgInternal: 'Wir', orgExternal: 'Kunde' },
				2: { title: 'Umzug', orgInternal: null, orgExternal: null },
				3: { title: 'Nichts los', orgInternal: null, orgExternal: null },
			},
			waiting: { 3: { since: '2026-08-10T08:00:00+00:00', userIds: ['carla'] } },
		}))
		store.today = '2026-08-13'

		expect(store.projectRows.map((r) => [r.title, r.open, r.waiting])).toEqual([
			// Wo etwas wartet, steht oben — auch bei weniger offenen Vorgängen.
			['Umzug', 1, 1],
			['Relaunch', 2, 0],
		])

		// Beide Firmennamen, nicht nur der des Kunden.
		expect(store.projectRows.find((r) => r.title === 'Relaunch')?.org).toBe('Wir · Kunde')
	})

	/**
	 * Der Leerzustand hängt an den Projekten, nicht an den Wartezeilen.
	 *
	 * Wer nur auf `waitingRows` prüfte, zeigte „nichts hakt" an einer Seite, auf
	 * der zwei Projekte mit offenen Vorgängen stehen.
	 */
	it('ist erst leer, wenn nirgends etwas offen ist', () => {
		const store = useOverviewStore()

		store.apply(daten({ tickets: [ticket(1, 1)], waiting: {} }))
		expect(store.nothingOpen).toBe(false)
		expect(store.waitingRows).toEqual([])

		store.apply(daten({ tickets: [], waiting: {} }))
		expect(store.nothingOpen).toBe(true)
	})

	/**
	 * Die letzte Bewegung je Projekt ist die **jüngste** seiner Vorgänge — die
	 * Grundlage der „steht still"-Marke (#116).
	 */
	it('nimmt die jüngste Bewegung je Projekt als lastMovementDays', () => {
		const store = useOverviewStore()
		store.apply(daten({
			tickets: [
				ticket(1, 1, 'A', '2026-08-01T10:00:00+00:00'), // älter
				ticket(2, 1, 'B', '2026-08-10T23:00:00+00:00'), // jünger
			],
		}))
		store.today = '2026-08-13'

		// Der 10.08. ist drei Tage her, nicht der 01.08.
		expect(store.projectRows.find((r) => r.boardId === 1)?.lastMovementDays).toBe(3)
	})

	it('lässt lastMovementDays null, wenn kein Vorgang einen Zeitpunkt trägt', () => {
		const store = useOverviewStore()
		store.apply(daten({ tickets: [ticket(1, 1)] }))
		store.today = '2026-08-13'

		expect(store.projectRows[0].lastMovementDays).toBeNull()
	})

	/**
	 * „Meine Vorgänge" (#120): verantwortlich und **nicht** wartend — sonst
	 * stünde derselbe Vorgang doppelt (er ist dann schon im Warte-Abschnitt).
	 */
	it('zeigt meine Vorgänge, aber nicht die wartenden', () => {
		const store = useOverviewStore()
		store.apply(daten({
			tickets: [
				ticket(1, 1, 'A', null, 'ich'),
				ticket(2, 1, 'B', null, 'ich'), // wartet → gehört in den Warte-Abschnitt
				ticket(3, 1, 'C', null, 'wer-anders'),
			],
			waiting: { 2: { since: '2026-08-10', userIds: ['kunde'] } },
			me: 'ich',
		}))

		expect(store.myTicketRows.map((r) => r.ticket.id)).toEqual([1])
	})

	/**
	 * „Liegt bei niemandem" (#119): ohne Verantwortlichen, ohne offenen Schritt,
	 * und es wartet auch nicht. Alles andere fällt raus.
	 */
	it('zeigt nur die unbearbeiteten Vorgänge als „liegt bei niemandem"', () => {
		const store = useOverviewStore()
		store.apply(daten({
			tickets: [
				ticket(1, 1, 'A'), // niemand — der einzige Treffer
				ticket(2, 1, 'B', null, 'ich'), // hat einen Verantwortlichen
				ticket(3, 1, 'C'), // hat einen offenen Schritt
				ticket(4, 1, 'D'), // wartet auf den Kunden
			],
			withOpenSteps: [3],
			waiting: { 4: { since: '2026-08-10', userIds: ['kunde'] } },
			me: 'ich',
		}))

		expect(store.nobodyRows.map((r) => r.ticket.id)).toEqual([1])
	})
})
