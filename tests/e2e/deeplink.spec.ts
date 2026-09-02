import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Der Deep-Link aus der Benachrichtigungs-Mail (#248).
 *
 * Der „Zum Vorgang"-Knopf führt auf `/t/{id}` — fragmentfrei, damit der Link den
 * Login-Umweg überlebt. Der Server legt das Ziel als Initial State ab, das
 * Frontend springt aufs Board und soll den Vorgang öffnen.
 *
 * **Der Fehler steckte im Frontend, nicht im Server.** `openFromQuery` öffnete
 * das Overlay nur, wenn der Vorgang schon in der geladenen Board-Menge stand —
 * und die blendet geschlossene Vorgänge aus (`TicketMapper` filtert `closed_at`).
 * Da `ticket_closed` ein Mail-Anlass ist, zeigte ausgerechnet dieser Link ins
 * Leere: Man landete auf dem Board, ohne dass sich der Vorgang öffnete.
 *
 * Deshalb prüft der zweite Test den **geschlossenen** Vorgang — den Fall, den es
 * im Testbestand nicht gab und der den Fehler durchgelassen hat.
 */

test.use({ storageState: INTERN.sitzung })

let projekt: Projekt

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test('öffnet einen offenen Vorgang samt Inhalt', async ({ page }) => {
	await page.goto(`${APP_PFAD}t/${projekt.oeffentlich.id}`)

	await expect(page.locator('.pw-detail')).toBeVisible({ timeout: 30_000 })
	await expect(page.locator('.pw-detail')).toContainText(projekt.oeffentlich.title)
})

test('öffnet auch einen geschlossenen Vorgang', async ({ page, request }) => {
	const api = await Api.fuer(request)
	await api.ticketSchliessen(projekt.boardId, projekt.oeffentlich.id)

	// Der geschlossene Vorgang steht nicht mehr in der Board-Ladung. Vor dem Fix
	// (#248) blieb das Overlay hier aus und man sah nur das Board.
	await page.goto(`${APP_PFAD}t/${projekt.oeffentlich.id}`)

	await expect(page.locator('.pw-detail')).toBeVisible({ timeout: 30_000 })
	await expect(page.locator('.pw-detail')).toContainText(projekt.oeffentlich.title)
})
