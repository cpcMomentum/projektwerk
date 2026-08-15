/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Datumshelfer rechnen auf der Zeichenkette, nicht über UTC. Der eine Test,
 * der wirklich zählt: Ein `Date` und zurück darf den Tag **nicht** verschieben,
 * auch nicht westlich von Greenwich.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { asDate, germanDate, heute, isOverdue, toIsoDay } from '@/utils/date'

describe('Datumshelfer', () => {
	it('macht aus einem Tag ein Date und zurück, ohne ihn zu verschieben', () => {
		// `asDate` setzt Mitternacht Ortszeit, `toIsoDay` liest die lokalen
		// Bestandteile — kein Umweg über UTC, also keine Verschiebung um einen
		// Tag. Über `toISOString()` fiele der 11. hier je nach Zone auf den 10.
		expect(toIsoDay(asDate('2026-08-11'))).toBe('2026-08-11')
	})

	it('gibt für leer und null nichts zurück', () => {
		expect(asDate(null)).toBeNull()
		expect(asDate('')).toBeNull()
		expect(toIsoDay(null)).toBeNull()
		expect(germanDate(null)).toBe('')
	})

	it('schreibt das Datum deutsch', () => {
		expect(germanDate('2026-08-20')).toBe('20.08.2026')
	})

	describe('überfällig ist ein Tagvergleich', () => {
		beforeEach(() => {
			// Mittag UTC: derselbe Kalendertag in UTC (CI) wie in der lokalen
			// Zone hier. Ein Zeitpunkt nahe Mitternacht ließe `heute()` je nach
			// Zone auf einen anderen Tag fallen und den Test grundlos wackeln.
			vi.useFakeTimers()
			vi.setSystemTime(new Date('2026-08-09T12:00:00Z'))
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		it('zählt gestern als überfällig, heute und morgen nicht', () => {
			expect(isOverdue('2026-08-08')).toBe(true)
			// Der Fälligkeitstag selbst hat noch den ganzen Tag.
			expect(isOverdue('2026-08-09')).toBe(false)
			expect(isOverdue('2026-08-10')).toBe(false)
			expect(isOverdue(null)).toBe(false)
		})

		it('nennt heute im lokalen Format', () => {
			expect(heute()).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		})
	})
})
