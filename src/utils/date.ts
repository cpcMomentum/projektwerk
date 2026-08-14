/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Datumshelfer für Fälligkeiten — **immer auf der Zeichenkette, nie über UTC.**
 *
 * Eine Fälligkeit ist ein Datum **ohne** Uhrzeit (`Types::DATE` am Server). Jeder
 * Umweg über einen Zeitpunkt (`toISOString()`, `new Date(iso)` ohne Uhrzeit)
 * verschiebt es westlich von Greenwich um einen Tag: Eine Frist zum 11. stünde
 * dann als 10. im Kalender, und niemand sieht warum. Diese Helfer rechnen mit den
 * lokalen Bestandteilen, genau denen, die im Feld stehen.
 *
 * Dieselbe Regel steckt bereits im Schritt (`StepList`) und im „Meine
 * Aufgaben"-Speicher; hier liegt sie einmal, damit die drei Fassungen nicht
 * auseinanderlaufen.
 */

/**
 * Ein `JJJJ-MM-TT` als `Date` (Ortszeit, Mitternacht), oder `null`.
 *
 * @param iso Datum als JJJJ-MM-TT, leer oder null.
 */
export function asDate(iso: string | null): Date | null {
	return iso === null || iso === '' ? null : new Date(`${iso}T00:00`)
}

/**
 * Ein `Date` als `JJJJ-MM-TT` (Ortszeit), oder `null` — das Format, das der
 * Server verlangt.
 *
 * @param date Was der Picker geliefert hat.
 */
export function toIsoDay(date: Date | null): string | null {
	if (date === null) {
		return null
	}

	const zwei = (wert: number): string => String(wert).padStart(2, '0')

	return `${date.getFullYear()}-${zwei(date.getMonth() + 1)}-${zwei(date.getDate())}`
}

/**
 * Der heutige Tag als `JJJJ-MM-TT` (Ortszeit).
 *
 * Für den Überfällig-Vergleich: `dueDate < heute()`. Tag gegen Tag, nicht
 * Zeitpunkt gegen Zeitpunkt, deshalb kippt es nicht mit der Uhrzeit.
 */
export function heute(): string {
	return toIsoDay(new Date()) as string
}

/**
 * `2026-08-20` als `20.08.2026`, oder `''` für null.
 *
 * Auf der Zeichenkette, aus demselben Grund wie oben.
 *
 * @param iso Datum als JJJJ-MM-TT, oder null.
 */
export function germanDate(iso: string | null): string {
	if (iso === null || iso === '') {
		return ''
	}

	const [jahr, monat, tag] = iso.split('-')

	return `${tag}.${monat}.${jahr}`
}

/**
 * Ist dieses Datum vor heute? (Ein fehlendes Datum ist nie überfällig.)
 *
 * @param iso Fälligkeit als JJJJ-MM-TT, oder null.
 */
export function isOverdue(iso: string | null): boolean {
	return iso !== null && iso !== '' && iso < heute()
}
