/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Teleportierter Inhalt braucht die App-Klasse in sich.
 *
 * `NcModal` und `NcDialog` hängen ihren Inhalt an den `body`. Damit ist
 * `.app-projektwerk` **kein Vorfahr mehr**, und jede so geschachtelte Regel
 * greift nicht — unser gesamtes CSS ist so geschachtelt.
 *
 * Das ist am 2026-08-09 im Ticket-Detail und im Anlege-Formular aufgefallen,
 * und es war beide Male seit dem Tag drin, an dem die Komponente entstand.
 * Der Grund, warum es niemandem auffiel: Der Fehler bricht nichts, er
 * *entstellt* nur. Alle Texte stehen da, alle Knöpfe reagieren, die Prüfungen
 * über den DOM-Inhalt bleiben grün — nur die Flex-Regeln fehlen, Namen laufen
 * ineinander und Klickflächen sinken unter `--default-clickable-area`.
 *
 * Deshalb ein Wächter über den Quelltext statt über den gerenderten Baum:
 * Weder happy-dom noch jsdom rechnen Stylesheets aus, ein Test über
 * `getComputedStyle` wäre hier also selbst blind.
 */

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = join(import.meta.dirname, '..')

/** Alles, was seinen Inhalt aus dem App-Baum heraus teleportiert. */
const TELEPORTING = ['<NcModal', '<NcDialog']

/**
 * Alle `.vue`-Dateien unter `src/`.
 *
 * @param dir Verzeichnis, in dem gesucht wird.
 */
function vueFiles(dir: string): string[] {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = join(dir, entry.name)
		if (entry.isDirectory()) {
			return vueFiles(full)
		}
		return entry.name.endsWith('.vue') ? [full] : []
	})
}

describe('Teleportierter Inhalt', () => {
	it('trägt die App-Klasse in sich, sonst greift kein einziges CSS', () => {
		const verstoesse = vueFiles(SRC)
			.map((file) => ({ file, text: readFileSync(file, 'utf8') }))
			.filter(({ text }) => TELEPORTING.some((tag) => text.includes(tag)))
			.filter(({ text }) => !text.includes('class="app-projektwerk"'))
			.map(({ file }) => file.slice(SRC.length + 1))

		expect(verstoesse).toEqual([])
	})

	it('findet überhaupt Dateien, sonst prüft der Wächter nichts', () => {
		// Ohne diese Gegenprobe bliebe der Test auch dann grün, wenn die Suche
		// ins Leere liefe — der häufigste Weg, auf dem ein Wächter still stirbt.
		const teleportierende = vueFiles(SRC)
			.filter((file) => TELEPORTING.some((tag) => readFileSync(file, 'utf8').includes(tag)))

		expect(teleportierende.length).toBeGreaterThan(0)
	})
})
