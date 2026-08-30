/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Sparkline-Punkte (#232).
 *
 * Geprüft wird die eine Umrechnung, die still falsch aussehen kann: Wert →
 * Koordinate. Die Fehler hier brechen nichts, sie entstellen — eine Kurve, die
 * verkehrt herum läuft, durch Null geteilt verschwindet oder alle Punkte auf
 * eine Höhe legt, sieht plausibel aus und ist trotzdem gelogen.
 */

import { describe, expect, it } from 'vitest'
import { sparklinePoints } from '@/utils/sparkline'

const W = 100
const H = 26

/** Zerlegt die `points`-Zeichenkette in [x, y]-Paare. */
function parse(points: string): Array<[number, number]> {
	return points.split(' ').map((p) => p.split(',').map(Number) as [number, number])
}

describe('sparklinePoints', () => {
	it('gibt nichts zurück für weniger als zwei Werte', () => {
		expect(sparklinePoints([], W, H)).toBe('')
		expect(sparklinePoints([5], W, H)).toBe('')
	})

	it('verteilt die Punkte gleichmäßig über die volle Breite', () => {
		const pts = parse(sparklinePoints([1, 2, 3], W, H))
		expect(pts.map((p) => p[0])).toEqual([0, 50, 100])
	})

	it('legt den größten Wert nach oben (kleinstes y), den kleinsten nach unten', () => {
		const pts = parse(sparklinePoints([0, 10], W, H))
		// Erster Wert (0) am unteren Rand, zweiter (Maximum) am oberen.
		expect(pts[0][1]).toBeGreaterThan(pts[1][1])
		// Der Maximalwert liegt am oberen Innenrand (pad = 1).
		expect(pts[1][1]).toBeCloseTo(1, 5)
		// Der Nullwert am unteren Innenrand (height - pad).
		expect(pts[0][1]).toBeCloseTo(H - 1, 5)
	})

	it('legt eine flache Null-Reihe auf die Grundlinie statt durch Null zu teilen', () => {
		const pts = parse(sparklinePoints([0, 0, 0], W, H))
		const ys = pts.map((p) => p[1])
		expect(ys.every((y) => Number.isFinite(y))).toBe(true)
		// Alle auf derselben unteren Höhe.
		expect(new Set(ys).size).toBe(1)
		expect(ys[0]).toBeCloseTo(H - 1, 5)
	})

	it('skaliert relativ zum Maximum, nicht absolut', () => {
		// Zwei Reihen mit gleichem Verlaufsverhältnis liefern dieselben y-Werte.
		const klein = parse(sparklinePoints([1, 2], W, H)).map((p) => p[1])
		const gross = parse(sparklinePoints([50, 100], W, H)).map((p) => p[1])
		expect(klein).toEqual(gross)
	})
})
