import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Die Wartemarke im geoeffneten Vorgang — mit **Namen**, nicht mit Kennungen.
 *
 * Der Fehler aus #104 bestand seit Phase 3 und ist durch jede Pruefung
 * gerutscht, weil der Testbestand die Marke nur auf der **Karte** fuhr. Dort
 * zeigt die kompakte Fassung Avatare, und der Satz steht nur im `title` — die
 * rohen Kennungen waren unsichtbar. Im Vorgang standen sie ausgeschrieben da:
 *
 *     wartet auf pw-carla, pw-dirk · seit 06.08.
 *
 * Ursache war eine fehlende Zeile: `TicketCard` reichte `:names` durch,
 * `TicketDetail` nicht. Ohne die Zuordnung faellt `WaitBadge` auf die Kennung
 * zurueck — absichtlich, denn eine leere Stelle waere schlimmer.
 *
 * **Der Test prueft beides, und das zweite ist das wichtigere:** dass der Name
 * dasteht UND dass die Kennung es nicht tut. Nur die erste Haelfte waere auch
 * dann gruen, wenn die Kennung daneben stuende.
 */

let projekt: Projekt

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())

	// „Wartet auf Kunde" ist berechnet und nicht setzbar (§9): Er entsteht,
	// sobald ein **offener** Schritt einer Person mit Rolle `external` gehoert.
	const kontext = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const api = await Api.fuer(kontext.request)
		await api.schrittAnlegen(projekt.boardId, projekt.oeffentlich.id, 'Freigabe abwarten', KUNDE.uid)
	} finally {
		await kontext.close()
	}
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test.describe('Dienstleisterseite', () => {
	test.use({ storageState: INTERN.sitzung })

	test('die Marke im Vorgang nennt den Namen, nicht die Kennung', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		await page.getByText(projekt.oeffentlich.title).click()

		const marke = page.locator('.pw-detail .pw-wait')
		await expect(marke).toBeVisible()
		await expect(marke).toContainText(KUNDE.name)

		// Die eigentliche Zusicherung. `pw-e2e-kunde` ist die Kennung; sie darf
		// in der Marke nirgends auftauchen.
		await expect(marke).not.toContainText(KUNDE.uid)
	})

	test('der Satz unter der Marke ist entfallen', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		await page.getByText(projekt.oeffentlich.title).click()

		// Variante A aus #104 (Axel, 2026-08-13): Marke und Satz sagten dasselbe,
		// einmal kaputt und einmal richtig. Der Satz ist gegangen, weil die Marke
		// mehr traegt — Uhr, Datum, und mit #144 dieselbe Uhr ruhig vs. laut.
		await expect(page.locator('.pw-wait__sentence')).toHaveCount(0)
		await expect(page.getByText(/Dieser Vorgang wartet auf die Kundenseite/)).toHaveCount(0)

		// Gegenprobe: Die Auskunft selbst ist nicht mit dem Satz verschwunden.
		await expect(page.locator('.pw-detail .pw-wait')).toContainText(KUNDE.name)
	})
})

/**
 * Die Intensität der Marke hängt an der Fälligkeit (#144).
 *
 * Ballbesitz und Verzug sind zwei Fragen: Der Ball liegt beim Kunden, aber
 * „wirklich zu spät" ist der Vorgang erst, wenn seine Fälligkeit (#72) gerissen
 * ist. Innerhalb der Frist bleibt die Marke ruhig (`.pw-wait` ohne
 * `--overdue`), bei Verzug wird sie laut (`.pw-wait--overdue`).
 *
 * Der Wartezustand selbst ändert sich dabei nie — nur seine Darstellung. Die
 * Daten liegen bewusst weit in Vergangenheit und Zukunft, damit der Test nicht
 * am Testtag kippt.
 */
test.describe('Fälligkeit steuert die Intensität', () => {
	test.use({ storageState: INTERN.sitzung })

	test('ruhig innerhalb der Frist, laut bei Verzug', async ({ page }) => {
		const api = await Api.fuer(page.request)

		// Ausgangslage ohne Fälligkeit: wartend, aber ruhig.
		await api.ticketAktualisieren(projekt.boardId, projekt.oeffentlich.id, { dueDate: null })
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		await page.getByText(projekt.oeffentlich.title).click()
		await expect(page.locator('.pw-detail .pw-wait')).toBeVisible()
		await expect(page.locator('.pw-detail .pw-wait--overdue')).toHaveCount(0)

		// Fälligkeit in der Zukunft: weiterhin ruhig — der Ball liegt im Plan.
		await api.ticketAktualisieren(projekt.boardId, projekt.oeffentlich.id, { dueDate: '2099-12-31' })
		await page.reload()
		await page.getByText(projekt.oeffentlich.title).click()
		await expect(page.locator('.pw-detail .pw-wait')).toBeVisible()
		await expect(page.locator('.pw-detail .pw-wait--overdue')).toHaveCount(0)

		// Fälligkeit gerissen: jetzt die kräftige Marke.
		await api.ticketAktualisieren(projekt.boardId, projekt.oeffentlich.id, { dueDate: '2020-01-01' })
		await page.reload()
		await page.getByText(projekt.oeffentlich.title).click()
		await expect(page.locator('.pw-detail .pw-wait--overdue')).toBeVisible()
	})
})
