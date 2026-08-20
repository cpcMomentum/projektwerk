import type { Browser } from '@playwright/test'
import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Den Ablageordner über den Wähler bestimmen — auswählen und anlegen (#139).
 *
 * Der Wähler ersetzt das reine Pfad-Textfeld: Nextclouds nativer FilePicker
 * lässt sich nicht ins IIFE-Bundle ziehen (dynamischer Import, Vite 8/Rolldown),
 * deshalb ein eigener über WebDAV. Dieser Test geht den Weg, den ein Mensch
 * geht — Ordner anlegen im Wähler, hineinwechseln, wählen — und prüft, dass der
 * Pfad danach im Feld steht. Er läuft als verwaltendes internes Mitglied, denn
 * nur das darf die Dateiablage ändern.
 */

test.use({ storageState: INTERN.sitzung })

let projekt: Projekt

test.beforeAll(async ({ browser }: { browser: Browser }) => {
	projekt = await projektAufbauen(browser, marke())
})

test.afterAll(async ({ browser }: { browser: Browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test('legt über den Wähler einen Ordner an und übernimmt ihn in die Dateiablage', async ({ page }) => {
	const ordner = 'PW-Picker-' + marke()

	await page.goto(APP_PFAD + '#/boards/' + projekt.boardId + '/settings')

	// Die Dateiablage liegt seit #196 Teil 2 hinter ihrem eigenen Nav-Punkt.
	await page.getByRole('button', { name: 'Dateiablage' }).click()

	// Der erste „Ordner wählen"-Knopf gehört zum Austausch-Ordner.
	await page.getByRole('button', { name: 'Ordner wählen' }).first().click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()

	// Im Wähler selbst anlegen — der Fall, für den es bisher keinen Weg gab.
	await dialog.getByRole('textbox', { name: 'Name des neuen Ordners' }).fill(ordner)
	await dialog.getByRole('button', { name: 'Ordner anlegen' }).click()

	// Nach dem Anlegen steht der Wähler im neuen Ordner: die Wegzeile trägt
	// seinen Namen, und er erscheint nicht als sein eigener Unterordner.
	await expect(dialog.getByText(ordner)).toBeVisible()

	await dialog.getByRole('button', { name: 'Diesen Ordner wählen' }).click()

	// Der Wähler schliesst, und der gewählte Pfad steht im Feld — die Auswahl
	// ist zugleich die Bestätigung, gespeichert wird sofort.
	await expect(dialog).toBeHidden()
	await expect(page.locator('#pw-set-public')).toHaveValue(new RegExp(ordner + '$'))
})
