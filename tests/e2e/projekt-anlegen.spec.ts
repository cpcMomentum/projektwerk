import type { Browser } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { marke, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Ein Projekt über die Oberfläche anlegen — der Weg, den ein Mensch geht.
 *
 * Es gab ihn im Testbestand nicht: Jeder andere Test seedet sein Board über den
 * API-Helfer (`projektAufbauen`) und klickt danach *hinein*. Der Knopf „Neues
 * Projekt" wurde dadurch nie betätigt — und weil er im Frontend nie verdrahtet
 * war, konnte man auf Produktiv als neue Person kein Projekt anlegen (#135). Ein
 * seedender Test hätte das nie gesehen; genau deshalb geht dieser durch die UI.
 *
 * Aufgeräumt wird per API, weil das Board sonst zwischen den Läufen stehen
 * bliebe — angelegt wird es aber ausschliesslich über die Oberfläche.
 */

test.use({ storageState: INTERN.sitzung })

let angelegtesBoard: number | undefined

test.afterAll(async ({ browser }: { browser: Browser }) => {
	if (angelegtesBoard !== undefined) {
		await projektAufraeumen(browser, angelegtesBoard)
	}
})

test('legt ein Projekt über die Oberfläche an und wechselt hinein', async ({ page }) => {
	const titel = marke()

	await page.goto(APP_PFAD + '#/boards')

	// Der Knopf trägt den Fix: Ohne ihn steht die Projekte-Ansicht ohne Weg
	// nach vorn da. Er sitzt in der Kopfzeile und (bei null Projekten) auch im
	// Leerzustand — beide rufen denselben Dialog.
	await page.getByRole('button', { name: 'Neues Projekt' }).first().click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()
	await dialog.locator('#pw-newboard-title').fill(titel)
	await dialog.getByRole('button', { name: 'Anlegen' }).click()

	// Nach dem Anlegen wird gleich ins neue Board gewechselt: Die URL trägt
	// dessen Kennung, und die Überschrift trägt den Titel. Beides zusammen
	// belegt, dass wirklich ein Board entstanden ist und nicht bloss der Dialog
	// zuging.
	await expect(page).toHaveURL(/#\/boards\/\d+$/, { timeout: 15_000 })
	await expect(page.getByRole('heading', { name: titel })).toBeVisible()

	const treffer = page.url().match(/#\/boards\/(\d+)$/)
	expect(treffer, `Keine Board-ID in der URL: ${page.url()}`).not.toBeNull()
	angelegtesBoard = Number(treffer![1])
})
