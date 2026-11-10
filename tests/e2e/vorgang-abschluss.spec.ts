import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/** Abschließen, Archivieren und Wieder öffnen über die Fußzeile des geöffneten Vorgangs. */

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

test('der geöffnete Vorgang lässt sich verwerfen, archivieren, zurückholen und wieder öffnen', async ({ page, request }) => {
	const api = await Api.fuer(request)
	const lesen = async () => (await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)).ticket

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await page.locator('.pw-card', { hasText: projekt.oeffentlich.title }).click()

	await page.getByRole('button', { name: 'Verworfen' }).click()
	await expect(page.getByRole('button', { name: 'Wieder öffnen' })).toBeVisible()
	expect((await lesen()).closedOutcome).toBe('discarded')

	await page.getByRole('button', { name: 'Archivieren', exact: true }).click()
	await expect(page.getByRole('button', { name: 'Aus dem Archiv holen' })).toBeVisible()
	expect((await lesen()).archivedAt).not.toBeNull()

	await page.getByRole('button', { name: 'Aus dem Archiv holen' }).click()
	await expect(page.getByRole('button', { name: 'Archivieren', exact: true })).toBeVisible()

	await page.getByRole('button', { name: 'Wieder öffnen' }).click()
	await expect(page.getByRole('button', { name: 'Erledigt' })).toBeVisible()
	const ticket = await lesen()
	expect(ticket.closedAt).toBeNull()
	expect(ticket.archivedAt).toBeNull()
})
