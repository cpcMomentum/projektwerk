import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, personWaehlen, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Einen Vorgang über die Oberfläche anlegen — mit Zuständiger.
 *
 * Der Anlege-Dialog schließt mit einer Wahl (#165): „Anlegen" bleibt auf dem
 * Board, „Anlegen und öffnen" führt **direkt in den Detail-View** (der frühere
 * Automatismus aus #146, jetzt bewusst gewählt), wo Anhänge und Arbeitsschritte
 * „wie im Detail" möglich sind. Beide Wege sind hier je ein Test.
 *
 * Die/der Zuständige lässt sich **schon beim Anlegen** wählen (#146; der schlanke
 * Dialog #100 wächst um genau ein Feld). Der Picker folgt der Sichtbarkeit: Bei
 * einem öffentlichen Vorgang gehört die Kundenseite dazu.
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

test('„Anlegen und öffnen" legt mit Zuständiger an und öffnet das Detail', async ({ page }) => {
	const titel = marke()

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await page.getByRole('button', { name: 'Neuer Vorgang' }).first().click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()

	await dialog.locator('#pw-new-title').fill(titel)

	// Sichtbarkeit bleibt „Alle Beteiligten" (public) — dort ist die Kundenseite
	// zuweisbar. Die Auswahl kommt vom Server (`assignable-new`).
	await personWaehlen(page, '#pw-new-responsible', KUNDE.name)

	// „Anlegen und öffnen" (#165) — der Weg, der direkt ins Detail führt. „Anlegen"
	// allein bliebe auf dem Board; das prüft der nächste Test.
	await dialog.getByRole('button', { name: 'Anlegen und öffnen' }).click()

	// Nach diesem Weg steht der Detail-View offen — mit dem Titel und der eben
	// gewählten Zuständigen. Beides zusammen belegt, dass der Vorgang entstand UND
	// die Zuständigkeit gleich mitgesetzt wurde.
	const detail = page.locator('.pw-detail')
	await expect(detail).toBeVisible({ timeout: 15_000 })
	await expect(detail.getByRole('heading', { name: titel })).toBeVisible()
	// Die zuständige Zeile, nicht die des Erstellers: beide sind `.pw-person`.
	await expect(detail.locator('.pw-person', { hasText: 'zuständig' })).toContainText(KUNDE.name)
})

test('„Anlegen" bleibt auf dem Board, ohne das Detail zu öffnen', async ({ page }) => {
	const titel = marke()

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await page.getByRole('button', { name: 'Neuer Vorgang' }).first().click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()

	await dialog.locator('#pw-new-title').fill(titel)

	// Der primäre Abschluss (#165). `exact`, sonst träfe der Name auch „Anlegen
	// und öffnen".
	await dialog.getByRole('button', { name: 'Anlegen', exact: true }).click()

	// Der Dialog schließt, und es öffnet sich **kein** Detail — der Vorgang liegt
	// als Karte auf dem Board. Genau das ist die Zusage von #165.
	await expect(dialog).toBeHidden()
	await expect(page.locator('.pw-detail')).toHaveCount(0)
	await expect(page.locator('.pw-card', { hasText: titel })).toBeVisible()
})
