import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Arbeitsschritte anlegen und pflegen — aus der Oberflaeche heraus.
 *
 * **Warum das ueber die API gegengeprueft wird und nicht nur im DOM.** Der
 * Fehler, der diesen Test veranlasst hat, war im DOM unsichtbar: Die Frist
 * liess sich eintragen und stand danach da, aber der Controller verwarf das
 * ausdrueckliche „Frist entfernen" (`!== null`) — geloescht wurde nie. Erst der
 * Blick auf das, was der Server tatsaechlich gespeichert hat, zeigt so etwas.
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

/**
 * @param request Der Aufrufkontext des Tests.
 * @param titel Der gesuchte Arbeitsschritt.
 */
async function schrittAusDerDatenbank(request: Parameters<typeof Api.fuer>[0], titel: string) {
	const api = await Api.fuer(request)
	const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)

	return (detail.steps ?? []).find((s: { title: string }) => s.title === titel)
}

test('legt einen Schritt samt Zustaendiger und Frist in einem Zug an', async ({ page, request }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
	await page.getByText(projekt.oeffentlich.title).click()

	const zeile = page.locator('.pw-step--new')
	await zeile.locator('input[type="text"]').fill('Freigabe holen')
	await zeile.locator('select').selectOption(KUNDE.uid)
	await zeile.locator('input[type="date"]').fill('2026-09-01')
	await page.getByRole('button', { name: 'Hinzufügen' }).click()

	await expect(page.getByText('Freigabe holen')).toBeVisible()

	const schritt = await schrittAusDerDatenbank(request, 'Freigabe holen')
	expect(schritt, 'Der Schritt fehlt in der Antwort des Servers').toBeDefined()
	expect(schritt.assignedUserId).toBe(KUNDE.uid)
	// **Der Tag, der im Feld stand.** Ueber `toISOString()` waere daraus in
	// Mitteleuropa der 31.08. geworden — eine Frist einen Tag zu frueh.
	expect(schritt.dueDate).toBe('2026-09-01')
	// Die Wartezeit beginnt jetzt beim Anlegen und nicht erst beim spaeteren
	// Zuweisen; das ist der erwuenschte Nebeneffekt aus #86.
	expect(schritt.assignedAt).not.toBeNull()
})

/**
 * Der Fehler, der diesen Test veranlasst hat.
 *
 * `StepController::update` uebernahm nur, was `!== null` war, und verwarf damit
 * genau das ausdrueckliche „Frist entfernen". Aufgefallen ist es erst, als die
 * Faelligkeit mit #86 zum ersten Mal aus der Oberflaeche heraus zu setzen war —
 * vorher kam nie jemand an die Stelle.
 */
test('loescht eine Frist wieder, wenn das Feld geleert wird', async ({ page, request }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
	await page.getByText(projekt.oeffentlich.title).click()

	const zeile = page.locator('.pw-step--new')
	await zeile.locator('input[type="text"]').fill('Mit Frist')
	await zeile.locator('input[type="date"]').fill('2026-09-15')
	await page.getByRole('button', { name: 'Hinzufügen' }).click()
	await expect(page.getByText('Mit Frist')).toBeVisible()

	// Erst die Gegenprobe: Ohne sie wuerde die Zeile unten auch dann gruen,
	// wenn das Setzen schon nicht funktioniert haette.
	expect((await schrittAusDerDatenbank(request, 'Mit Frist')).dueDate).toBe('2026-09-15')

	const gesetzt = page.locator('.pw-step', { hasText: 'Mit Frist' })
	await gesetzt.locator('input[type="date"]').fill('')

	await expect
		.poll(async () => (await schrittAusDerDatenbank(request, 'Mit Frist')).dueDate)
		.toBeNull()
})
