import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * „Verschieben nach …" — der Weg ohne Ziehen.
 *
 * **Warum ausgerechnet dieser Test.** Jede Zieh-Geste braucht laut CLAUDE.md
 * eine Alternative ohne Ziehen; Tastatur und Screenreader sind Abnahme-
 * kriterium, nicht Nachruestung. Ein Kriterium, das niemand nachprueft, ist
 * eine Absichtserklaerung. Dieser Test klickt genau den Weg, den auch eine
 * Tastaturbedienung nimmt — und er ruft dasselbe Kommando wie spaeter Drag &
 * Drop, prueft also beide Wege auf einmal.
 */

let projekt: Projekt

test.use({ storageState: INTERN.sitzung })

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test('verschiebt einen Vorgang in eine andere Spalte', async ({ page }) => {
	const titel = projekt.oeffentlich.title
	const quelle = projekt.spalten[0]
	const ziel = projekt.spalten[1]

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)

	const spalte = (name: string) => page.locator('.pw-col').filter({ hasText: name })

	// Ausgangslage festhalten, nicht annehmen: Ein Test, der nur das Ende
	// prueft, meldet auch dann gruen, wenn die Karte von Anfang an dort lag.
	await expect(spalte(quelle.title).getByText(titel)).toBeVisible({ timeout: 30_000 })

	await page.getByRole('button', { name: `Aktionen für ${titel}` }).click()
	await page.getByRole('menuitem', { name: ziel.title }).click()

	await expect(spalte(ziel.title).getByText(titel)).toBeVisible()
	await expect(spalte(quelle.title).getByText(titel)).toHaveCount(0)
})

test('haelt das Verschieben ueber einen Neuaufbau der Seite', async ({ page }) => {
	// Die Karte springt schon vor der Antwort des Servers — das ist gewollt und
	// faellt genau dann auf die Fuesse, wenn der Server den Zug ablehnt. Ohne
	// diesen zweiten Blick pruefte der Test nur, dass sich das Frontend etwas
	// merkt, was es sich selbst gesagt hat.
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)

	const ziel = projekt.spalten[1]
	await expect(page.locator('.pw-col').filter({ hasText: ziel.title }).getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
})
