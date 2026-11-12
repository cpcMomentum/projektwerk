import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/** Abgeschlossene Vorgänge archivieren, mit „Archivierte zeigen“ wieder einblenden und zurückholen. */

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

test('ein abgeschlossener Vorgang verschwindet beim Archivieren und kommt über den Schalter zurück', async ({ page, request }) => {
	const titel = projekt.oeffentlich.title
	const karte = page.locator('.pw-card', { hasText: titel })
	const api = await Api.fuer(request)

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(karte).toBeVisible({ timeout: 30_000 })
	await page.getByRole('button', { name: `Aktionen für ${titel}` }).click()
	await expect(page.getByRole('menuitem', { name: 'Archivieren' })).toHaveCount(0)
	await page.keyboard.press('Escape')

	await api.ticketSchliessen(projekt.boardId, projekt.oeffentlich.id)
	await page.reload()
	await expect(karte).toBeVisible({ timeout: 30_000 })

	await page.getByRole('button', { name: `Aktionen für ${titel}` }).click()
	await page.getByRole('menuitem', { name: 'Archivieren' }).click()
	await expect(karte).toHaveCount(0)

	await page.getByRole('button', { name: 'Archivierte zeigen' }).click()
	await expect(karte).toContainText('Archiviert')

	await page.getByRole('button', { name: `Aktionen für ${titel}` }).click()
	await page.getByRole('menuitem', { name: 'Aus dem Archiv holen' }).click()
	await expect(karte).not.toContainText('Archiviert')

	const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)
	expect(detail.ticket.archivedAt).toBeNull()
	expect(detail.ticket.closedAt).not.toBeNull()
})
