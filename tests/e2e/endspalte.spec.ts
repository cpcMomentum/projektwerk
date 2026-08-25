import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * **„Auch abschließen?" beim Verschieben in eine Endspalte** (#172).
 *
 * Verschieben und Abschließen bleiben zwei Handlungen — die App bietet nur an,
 * automatisch schließt sie nicht. Der Test geht den tastaturbedienten Weg über
 * „Verschieben nach …", der dasselbe Kommando ruft wie Drag & Drop, prüft also
 * beide Wege. Der Abschluss wird über die API gegengeprüft, nicht über die
 * Karte: Ein geschlossener Vorgang klappt unter „Ältere anzeigen" weg, und ein
 * UI-Blick darauf wäre wackliger als die Frage an die Quelle.
 */

let projekt: Projekt
let endspalte: { id: number, title: string }

test.use({ storageState: INTERN.sitzung })

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())
	// Die zweite Spalte wird Endspalte mit Ergebnis „verworfen".
	endspalte = projekt.spalten[1]

	const kontext = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const api = await Api.fuer(kontext.request)
		await api.spalteEndergebnis(projekt.boardId, endspalte.id, 'discarded')
	} finally {
		await kontext.close()
	}
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test('das Verschieben in eine Endspalte bietet „Auch abschließen?" an und schließt mit dem Ergebnis der Spalte', async ({ page, request }) => {
	const titel = projekt.oeffentlich.title
	const quelle = projekt.spalten[0]
	const spalte = (name: string) => page.locator('.pw-col').filter({ hasText: name })

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(spalte(quelle.title).getByText(titel)).toBeVisible({ timeout: 30_000 })

	// In die Endspalte verschieben — über das Untermenü (#176), das dasselbe
	// Kommando wie Drag & Drop ruft.
	await page.getByRole('button', { name: `Aktionen für ${titel}` }).click()
	await page.getByRole('button', { name: /Verschieben nach/ }).click()
	await page.getByRole('menuitem', { name: endspalte.title }).click()

	// Der Prompt erscheint — kein automatisches Schließen.
	await expect(page.getByText('Auch abschließen?')).toBeVisible()
	await expect(page.getByText('Diesen Vorgang auch als verworfen abschließen?')).toBeVisible()

	await page.getByRole('button', { name: 'Abschließen', exact: true }).click()

	// Gegenprobe an der Quelle: Der Vorgang ist geschlossen, und zwar mit dem
	// Ergebnis der Endspalte.
	await expect.poll(async () => {
		const api = await Api.fuer(request)
		const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)

		return detail.ticket.closedOutcome
	}).toBe('discarded')
})
