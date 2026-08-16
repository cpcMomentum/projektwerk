import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Drag & Drop — der Komfortweg über den Spalten (#11, 7a).
 *
 * **Warum zusätzlich zum Menü-Test.** `verschieben.spec.ts` prüft den
 * barrierefreien Weg (Menü/Tastatur), der die Abnahme trägt. Dieser hier prüft
 * die Zieh-Geste selbst — dass sie dieselbe `moveTicket`-Kette auslöst und die
 * Karte wirklich in der Zielspalte landet, server-bestätigt. Die Maus wird von
 * Hand geführt (mehrere `mousemove`), weil `sortablejs` erst auf die Bewegung
 * reagiert; ein simpler `dragTo`-Sprung löst es nicht aus.
 *
 * **Der Touch-/Langdruck-Teil (S3) steht hier NICHT** — den trägt nur ein echtes
 * Gerät, und er ist Axels Abnahme.
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

test('zieht einen Vorgang in eine andere Spalte', async ({ page, request }) => {
	const titel = projekt.oeffentlich.title
	const quelle = projekt.spalten[0]
	const ziel = projekt.spalten[1]

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)

	const spalte = (name: string) => page.locator('.pw-col').filter({ hasText: name })

	// Ausgangslage: Die Karte liegt in der Quellspalte.
	const karte = spalte(quelle.title).locator('.pw-card').filter({ hasText: titel })
	await expect(karte).toBeVisible({ timeout: 30_000 })

	// Ziel ist der Zieh-Container der leeren Zielspalte.
	const zielZone = spalte(ziel.title).locator('.pw-stack__drag')
	const von = await karte.boundingBox()
	const nach = await zielZone.boundingBox()
	if (von === null || nach === null) {
		throw new Error('Karte oder Zielzone ohne Bounding-Box')
	}

	// Von Hand ziehen: aufnehmen, ein Stück bewegen (löst den Zug aus), dann in
	// Schritten ins Ziel, dort kurz halten, loslassen.
	await page.mouse.move(von.x + von.width / 2, von.y + von.height / 2)
	await page.mouse.down()
	await page.mouse.move(von.x + von.width / 2 + 8, von.y + von.height / 2 + 8)
	await page.mouse.move(nach.x + nach.width / 2, nach.y + nach.height / 2, { steps: 12 })
	await page.mouse.move(nach.x + nach.width / 2, nach.y + nach.height / 2 + 4, { steps: 4 })
	await page.mouse.up()

	// Die Karte steht jetzt in der Zielspalte und nicht mehr in der Quelle.
	await expect(spalte(ziel.title).getByText(titel)).toBeVisible({ timeout: 15_000 })
	await expect(spalte(quelle.title).getByText(titel)).toHaveCount(0)

	// Gegenprobe beim Server: Die Karte springt schon vor der Antwort — nur die
	// Serverantwort belegt, dass der Zug wirklich ankam und nicht bloß im DOM
	// verrutschte.
	const api = await Api.fuer(request)
	await expect
		.poll(async () => {
			const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)
			return detail.ticket.columnId
		})
		.toBe(ziel.id)
})
