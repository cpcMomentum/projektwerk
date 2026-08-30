/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Punkte einer Sparkline (#232) — eine Zahlenreihe wird zu einer Polylinie.
 *
 * Reine Funktion und deshalb ohne Vue: Die Umrechnung Wert → Koordinate ist die
 * Stelle, an der eine Kurve still falsch aussehen kann (verkehrt herum, gestaucht,
 * durch Null geteilt), und genau die lässt sich hier ohne Browser prüfen.
 */

/**
 * Die `points`-Zeichenkette einer `<polyline>` für die gegebenen Werte.
 *
 * **Y ist invertiert:** In SVG wächst y nach unten, eine höhere Zahl soll aber
 * höher liegen. Der größte Wert der Reihe legt den oberen Rand fest (relative
 * Skala — die Sparkline zeigt den Verlauf, nicht die absolute Höhe; die steht
 * als Zahl daneben). Eine flache Null-Reihe ergibt eine Linie am Boden statt
 * einer Division durch Null.
 *
 * @param values Die Zahlenreihe, älteste zuerst.
 * @param width Breite der Zeichenfläche.
 * @param height Höhe der Zeichenfläche.
 * @param pad Innenabstand oben und unten, damit die Linie nicht am Rand klebt.
 * @return Die `points`-Zeichenkette, oder `''` bei weniger als zwei Werten.
 */
export function sparklinePoints(values: number[], width: number, height: number, pad = 1): string {
	// Eine Linie braucht mindestens zwei Punkte; bei null oder einem gibt es
	// nichts zu zeichnen, und die Aufrufende blendet die Kurve dann aus.
	if (values.length < 2) {
		return ''
	}

	const max = Math.max(...values, 1)
	const stepX = width / (values.length - 1)
	const nutzHoehe = height - pad * 2

	return values
		.map((v, i) => {
			const x = i * stepX
			const y = pad + nutzHoehe - (v / max) * nutzHoehe
			return `${round(x)},${round(y)}`
		})
		.join(' ')
}

/**
 * Auf zwei Nachkommastellen, ohne nachlaufende Nullen — hält das `points`-Attribut
 * kurz und lesbar.
 *
 * @param n Der Wert.
 */
function round(n: number): number {
	return Math.round(n * 100) / 100
}
