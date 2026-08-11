import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Die eigenen Kanalschalter, im Browser.
 *
 * **Warum das hier geprueft wird und nicht nur im Dienst.** Der angezeigte Stand
 * entsteht aus drei Stufen — Projektzeile, globale Zeile, Vorgabe — und die
 * Oberflaeche rechnet sie ein zweites Mal nach, damit ein Klick sofort wirkt.
 * Zwei Rechnungen, die auseinanderlaufen koennen, gehoeren gegeneinander
 * geprueft.
 *
 * Der Hinweis daneben ist dabei kein Beiwerk: Ohne ihn sieht ein geerbtes „an"
 * aus wie ein gesetztes.
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

test('zeigt die Vorgabe und merkt sich das Abschalten', async ({ page, request }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)

	const zeile = page.locator('.pw-settings__row', { hasText: 'E-Mail' })
	await expect(zeile).toBeVisible({ timeout: 30_000 })

	const schalter = zeile.locator('input[type="checkbox"]')
	await expect(schalter, 'Ohne eigene Einstellung ist der Kanal an').toBeChecked()
	await expect(zeile).toContainText('Vorgabe')

	await schalter.uncheck()

	// Der Hinweis muss mitziehen — sonst stuende „Vorgabe" an einem Schalter,
	// der ausdruecklich gesetzt wurde.
	await expect(zeile).toContainText('Für dieses Projekt festgelegt', { timeout: 15_000 })

	// Gegenprobe beim Server: Der Haken im DOM koennte auch nur lokal sitzen.
	const api = await Api.fuer(request)
	const stand = await api.lesen('/api/v1/notify-prefs')
	expect(stand.boards[String(projekt.boardId)].mail).toBe(false)

	// Und er ueberlebt einen Neuaufbau.
	await page.reload()
	await expect(zeile.locator('input[type="checkbox"]')).not.toBeChecked({ timeout: 30_000 })
})

/**
 * Die Kanaele sind unabhaengig: Mails abschalten heisst nicht Glocke
 * abschalten.
 */
test('der zweite Kanal bleibt davon unberuehrt', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)

	const glocke = page.locator('.pw-settings__row', { hasText: 'Glocke in Nextcloud' })
	await expect(glocke.locator('input[type="checkbox"]')).toBeChecked({ timeout: 30_000 })
	await expect(glocke).toContainText('Vorgabe')
})
