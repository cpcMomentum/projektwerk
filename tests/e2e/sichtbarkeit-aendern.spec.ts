import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Die Zusage in die andere Richtung.
 *
 * `sichtbarkeit.spec.ts` prueft, dass Internes verborgen bleibt. Das allein
 * waere auch von einer App zu erfuellen, die der Kundenseite grundsaetzlich
 * nichts zeigt. Hier wird geprueft, dass die Freigabe ankommt: Was die
 * Dienstleisterseite bewusst oeffnet, sieht die Kundenseite auch.
 *
 * Beides zusammen ist die Aussage — einzeln ist jede Haelfte wertlos.
 */

let projekt: Projekt
let geheimwort: string

test.beforeAll(async ({ browser }) => {
	geheimwort = marke()
	projekt = await projektAufbauen(browser, geheimwort)
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test('ein freigegebener Vorgang kommt bei der Kundenseite an', async ({ browser }) => {
	// Erst die Gegenprobe: Vorher ist er dort nicht. Ohne sie wuerde der Test
	// auch dann gruen, wenn die Kundenseite von Anfang an alles saehe.
	const vorher = await browser.newContext({ storageState: KUNDE.sitzung })
	try {
		const seite = await vorher.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(seite.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		expect(await seite.content()).not.toContain(geheimwort)
	} finally {
		await vorher.close()
	}

	// Die Dienstleisterseite gibt den internen Vorgang frei — ueber die
	// Oberflaeche, nicht ueber die API: Der zweistufige Weg (aendern, dann
	// bestaetigen) ist selbst Teil der Zusage und soll mitgeprueft werden.
	const innen = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const seite = await innen.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await seite.getByText(projekt.intern.title).click()

		await seite.getByRole('button', { name: 'Ändern' }).click()
		await seite.getByText('Alle Beteiligten').click()
		await seite.getByRole('button', { name: 'Übernehmen' }).click()

		// Die Rueckfrage erscheint nur, wenn jemand Zugriff *verliert*. Beim
		// Hochstufen verliert niemand etwas, also darf sie ausbleiben — der
		// Test darf daran nicht haengen, aber auch nicht daran vorbeilaufen.
		const rueckfrage = seite.getByRole('button', { name: 'Sichtbarkeit ändern' })
		if (await rueckfrage.isVisible().catch(() => false)) {
			await rueckfrage.click()
		}

		await expect(seite.getByText('Alle Beteiligten').first()).toBeVisible()
	} finally {
		await innen.close()
	}

	const nachher = await browser.newContext({ storageState: KUNDE.sitzung })
	try {
		const seite = await nachher.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(seite.getByText(projekt.intern.title)).toBeVisible({ timeout: 30_000 })
	} finally {
		await nachher.close()
	}
})
