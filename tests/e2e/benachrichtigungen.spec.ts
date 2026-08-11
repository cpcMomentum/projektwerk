import type { Page } from '@playwright/test'
import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Die Kanalschalter — **an einem Ort**, unten links im Seitenmenue.
 *
 * Der Zuschnitt davor verteilte die Entscheidung auf zwei Seiten: den
 * allgemeinen Schalter in Nextclouds Einstellungen, den je Projekt in den
 * Projekteinstellungen. Zwischen zwei Seiten kann niemand vergleichen — und
 * genau das Vergleichen ist hier die Aufgabe.
 *
 * **Diese Tests stellen ihren Ausgangszustand selbst her.** Sie laufen gegen
 * eine dauerhafte Instanz; die Zeilen ueberleben den Lauf. Ein frueherer
 * Durchgang hatte den allgemeinen Schalter umgelegt, und ein Test, der den
 * jungfraeulichen Zustand voraussetzte, war rot ohne Fehler im Code.
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
 * Den Einstellungsbereich im Seitenmenue oeffnen.
 *
 * Der Knopf wird ueber das Seitenmenue eingegrenzt: „Benachrichtigungen" heisst
 * auch Nextclouds eigene Glocke oben rechts.
 *
 * @param page Die Seite des Tests.
 */
async function bereichOeffnen(page: Page) {
	await page.goto(`${APP_PFAD}#/my-settings`)
	await page.locator('.pw-table').waitFor({ timeout: 30_000 })
}

/**
 * @param page Die Seite des Tests.
 * @param titel Titel des Projekts.
 */
function projektZeile(page: Page, titel: string) {
	return page.locator('.pw-table tbody tr', { hasText: titel })
}

test('zeigt den allgemeinen Schalter und jedes Projekt darunter', async ({ page, request }) => {
	const api = await Api.fuer(request)
	await api.kanalAusnahmenLeeren()
	await api.kanalSetzen('mail', true, 0)

	await bereichOeffnen(page)

	// Der allgemeine Teil.
	await expect(page.getByText('Gilt für alle Projekte — auch für die, die später dazukommen.')).toBeVisible()

	// Und das eben angelegte Projekt steht in der Liste — ohne eigene
	// Einstellung, also „wie allgemein".
	const zeile = projektZeile(page, projekt.titel)
	await expect(zeile).toBeVisible()
	await expect(
		zeile.locator('.pw-pin--set'),
		'Ohne eigene Einstellung ist nichts markiert',
	).toHaveCount(0)
})

/**
 * **Der Fall, der die Aufschluesselung noetig gemacht hat:** der Rundruf
 * allgemein aus, dieses eine Projekt aber an.
 *
 * Je Projekt sind nur die **Anlaesse** einstellbar; die Kanaele gelten global
 * (Entscheidung mit Axel, 2026-08-11). Ein Kanalschalter mit Projekt wird vom
 * Server abgewiesen — der Test darunter prueft das.
 */
test('eine Projekt-Ausnahme schlaegt die allgemeine Wahl', async ({ page, request }) => {
	const api = await Api.fuer(request)
	await api.kanalAusnahmenLeeren()
	await api.kanalSetzen('ticket_created', false, 0)

	await bereichOeffnen(page)

	const zeile = projektZeile(page, projekt.titel)
	// Die Zeile beginnt mit dem Projektnamen als `<th>`; die `<td>` sind die
	// drei Anlaesse in der Reihenfolge des Kopfes.
	const rundruf = zeile.locator('td').nth(2).locator('input[type="checkbox"]')

	await expect(rundruf, 'Ohne Ausnahme gilt die erste Zeile — und die ist aus').not.toBeChecked()
	await expect(zeile.locator('.pw-pin--set')).toHaveCount(0)

	await rundruf.check()

	// **Der Punkt sitzt am Kaestchen, nicht an der Zeile.** Die beiden anderen
	// Anlaesse weichen nicht ab und duerfen keine Markierung tragen.
	await expect(zeile.locator('.pw-pin--set'), 'Genau ein Kaestchen ist markiert').toHaveCount(1, { timeout: 15_000 })
	await expect(zeile.locator('td').nth(2).locator('.pw-pin--set'), 'und zwar der Rundruf').toHaveCount(1)
	await expect(zeile.locator('td').nth(0).locator('.pw-pin--set'), 'die Zuweisung nicht').toHaveCount(0)

	const stand = await api.lesen('/api/v1/notify-prefs')
	expect(stand.global.ticket_created).toBe(false)
	expect(stand.boards[String(projekt.boardId)].ticket_created).toBe(true)
})

/**
 * **Ein Kanal laesst sich nicht je Projekt setzen.**
 *
 * „Wie werde ich benachrichtigt" beantwortet niemand je Projekt anders. Der
 * Server weist es ab, statt still global zu speichern: Ein Wert, den die
 * Oberflaeche nicht anzeigt, aber die Aufloesung liest, ist genau die Sorte
 * Einstellung, die niemand mehr findet.
 */
test('ein Kanal je Projekt wird abgewiesen', async ({ request }) => {
	const api = await Api.fuer(request)

	await expect(api.kanalSetzen('mail', false, projekt.boardId)).rejects.toThrow(/400|nur allgemein/)
})

/**
 * Der Urlaubsschalter: Abweichungen weg, die erste Zeile bleibt stehen.
 */
test('hebt alle Abweichungen auf', async ({ page, request }) => {
	const api = await Api.fuer(request)
	await api.kanalSetzen('ticket_created', false, 0)
	await api.kanalSetzen('ticket_created', true, projekt.boardId)

	await bereichOeffnen(page)
	await page.getByRole('button', { name: /Alle Abweichungen aufheben/ }).click()

	await expect(
		projektZeile(page, projekt.titel).locator('.pw-pin--set'),
		'Nach dem Aufheben ist nichts mehr markiert',
	).toHaveCount(0, { timeout: 15_000 })

	const stand = await api.lesen('/api/v1/notify-prefs')
	expect(stand.boards, 'Die Abweichungen sind weg').toEqual({})
	expect(stand.global.ticket_created, 'Die erste Zeile bleibt — sie ist der Rueckfallwert').toBe(false)
})
