import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Die Kernzusage, von aussen geprueft.
 *
 * Die Leak-Matrix prueft dieselbe Regel auf Datenbankebene — dort, wo sie
 * steht. Hier wird sie dort geprueft, wo der Kunde sie erlebt: im
 * ausgelieferten Browser. Beides braucht es, weil ein JOIN richtig sein kann
 * und die Oberflaeche trotzdem verraet, was er verbirgt — ueber einen Zaehler,
 * eine Sortierposition, einen Initial State, eine Fehlermeldung.
 *
 * Deshalb sucht dieser Test nicht an bestimmten Stellen, sondern im **ganzen**
 * DOM nach einer Marke, die sonst nirgends vorkommt.
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

test.describe('Kundenseite', () => {
	test.use({ storageState: KUNDE.sitzung })

	test('sieht den oeffentlichen Vorgang', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)

		// Erst auf die Karte warten, dann pruefen: Ohne das koennte der naechste
		// Test gruen melden, weil noch gar nichts geladen war — ein leeres Board
		// enthaelt die interne Marke naemlich auch nicht.
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
	})

	test('sieht den internen Vorgang nirgends im DOM', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })

		const inhalt = await page.content()
		expect(
			inhalt.includes(geheimwort),
			`Die Marke "${geheimwort}" steht im ausgelieferten DOM der Kundenseite`,
		).toBe(false)
	})

	test('bekommt den internen Vorgang auch nicht ueber die API', async ({ request }) => {
		// Der zweite Weg an dieselben Daten. Die Oberflaeche koennte etwas
		// verbergen, was die Antwort trotzdem enthaelt — dann liegt das Leck
		// eine Schicht tiefer und wandert beim naechsten Umbau nach oben.
		//
		// Ueber `Api`, weil Nextcloud das `requesttoken` auch bei GET verlangt;
		// ein blanker Aufruf bekaeme 412 und der Test wuerde am Waechter
		// scheitern statt an der Sache.
		const api = await Api.fuer(request)
		const tickets = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets`)

		expect(JSON.stringify(tickets)).not.toContain(geheimwort)
	})
})

test.describe('Dienstleisterseite', () => {
	test.use({ storageState: INTERN.sitzung })

	test('sieht beide Vorgaenge', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)

		// Die Gegenprobe zur Gegenprobe: Ohne sie waere ein Test, der schlicht
		// nichts laedt, in beiden Richtungen gruen — und wir haetten einen
		// Waechter, der nur noch bestaetigt, dass die Seite leer ist.
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText(projekt.intern.title)).toBeVisible()
	})
})
