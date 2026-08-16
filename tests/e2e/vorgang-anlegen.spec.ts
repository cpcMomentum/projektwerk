import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, personWaehlen, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Einen Vorgang über die Oberfläche anlegen — mit Zuständiger, und danach direkt
 * im Detail (#146).
 *
 * Zwei Zusagen aus #146 auf einmal: Die/der Zuständige lässt sich **schon beim
 * Anlegen** wählen (der schlanke Dialog #100 wächst um genau ein Feld), und nach
 * dem Anlegen führt der Weg **direkt in den Detail-View** (Variante a), wo
 * Anhänge und Arbeitsschritte „wie im Detail" möglich sind. Der Picker folgt der
 * Sichtbarkeit: Bei einem öffentlichen Vorgang gehört die Kundenseite dazu.
 */

let projekt: Projekt

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test.use({ storageState: INTERN.sitzung })

test('legt einen Vorgang mit Zuständiger an und landet im Detail', async ({ page }) => {
	const titel = marke()

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await page.getByRole('button', { name: 'Neuer Vorgang' }).first().click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()

	await dialog.locator('#pw-new-title').fill(titel)

	// Sichtbarkeit bleibt „Alle Beteiligten" (public) — dort ist die Kundenseite
	// zuweisbar. Die Auswahl kommt vom Server (`assignable-new`).
	await personWaehlen(page, '#pw-new-responsible', KUNDE.name)

	await dialog.getByRole('button', { name: 'Anlegen' }).click()

	// Variante (a): nach dem Anlegen steht der Detail-View offen — mit dem Titel
	// und der eben gewählten Zuständigen. Beides zusammen belegt, dass der Vorgang
	// entstand UND die Zuständigkeit gleich mitgesetzt wurde.
	const detail = page.locator('.pw-detail')
	await expect(detail).toBeVisible({ timeout: 15_000 })
	await expect(detail.getByRole('heading', { name: titel })).toBeVisible()
	// Die zuständige Zeile, nicht die des Erstellers: beide sind `.pw-person`.
	await expect(detail.locator('.pw-person', { hasText: 'zuständig' })).toContainText(KUNDE.name)
})
