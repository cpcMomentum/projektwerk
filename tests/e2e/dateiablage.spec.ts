import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Die beiden Projektordner am Board festlegen.
 *
 * **Warum das im Browser geprueft wird und nicht nur im Dienst.** Der
 * Ordnerpfad ist der einzige Wert in den Einstellungen, den der Server nicht
 * uebernimmt, sondern *aufloest*: Was zurueckkommt, ist die Datei-ID plus der
 * kanonische Pfad, und im Feld soll danach der aufgeloeste stehen — nicht der
 * getippte. Diese Runde ueber den Server und zurueck ins Feld ist genau das,
 * was ein Dienst-Test nicht sieht.
 *
 * Der Ordner entsteht ueber WebDAV und nicht ueber `occ`: So legt ihn dieselbe
 * Person an, die ihn danach eintraegt, und der Test braucht keinen Zugriff auf
 * das Dateisystem des Servers — lokal wie in der CI derselbe Weg.
 */

let projekt: Projekt

/** Je Lauf eigener Name, damit zwei Laeufe sich nicht ins Gehege kommen. */
const ORDNER = `E2E-Ablage-${Date.now().toString(36)}`

test.use({ storageState: INTERN.sitzung })

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())

	// **Ueber die angemeldete Sitzung, mit `requesttoken`.** Basic-Auth am
	// DAV-Endpunkt beantwortet Nextcloud mit 401 „CSRF check not passed" —
	// dieselbe Falle wie bei den API-Aufrufen, nur an einer anderen Tuer. Der
	// Token steht in der ausgelieferten App-Seite; genauer beschrieben in
	// `api.ts`.
	const kontext = await browser.newContext({ storageState: INTERN.sitzung })

	try {
		const seite = await kontext.request.get(APP_PFAD)
		const token = (await seite.text()).match(/data-requesttoken="([^"]+)"/)?.[1]
		if (token === undefined) {
			throw new Error('Kein data-requesttoken in der App-Seite gefunden')
		}

		const antwort = await kontext.request.fetch(
			`/remote.php/dav/files/${INTERN.uid}/${ORDNER}`,
			{ method: 'MKCOL', headers: { requesttoken: token } },
		)

		// 201 beim ersten Mal, 405 wenn er schon steht — beides ist in Ordnung.
		if (!antwort.ok() && antwort.status() !== 405) {
			throw new Error(`Ordner anlegen: HTTP ${antwort.status()} ${await antwort.text()}`)
		}
	} finally {
		await kontext.close()
	}
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test('traegt einen Ordner ein und gibt den aufgeloesten Pfad zurueck', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)

	const feld = page.locator('#pw-set-public')
	await expect(feld).toBeVisible({ timeout: 30_000 })
	await expect(feld, 'Ein neues Projekt hat noch keinen Ordner').toHaveValue('')

	// Bewusst krumm getippt: fuehrender und schliessender Schraegstrich. Was
	// zurueckkommt, muss die Schreibweise aus dem Dateibaum sein — sonst stuende
	// in den Einstellungen ein Pfad, den es so nirgends gibt.
	await feld.fill(`/${ORDNER}/`)
	await page.getByRole('button', { name: 'Übernehmen' }).first().click()

	await expect(feld).toHaveValue(ORDNER, { timeout: 15_000 })

	// Und er ueberlebt einen Neuaufbau der Seite — steht also wirklich in der
	// Datenbank und nicht nur im Entwurf.
	await page.reload()
	await expect(feld).toHaveValue(ORDNER, { timeout: 30_000 })
})

/**
 * Ein Pfad, den es nicht gibt, wird abgewiesen — und das Feld bleibt stehen.
 *
 * Die Meldung ist absichtlich dieselbe wie fuer „ist eine Datei" und fuer „darf
 * ich nicht sehen": Ob es den Ordner anderswo gibt, geht die fragende Person
 * nichts an.
 */
test('weist einen unbekannten Pfad ab, ohne den bisherigen zu verlieren', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)

	const feld = page.locator('#pw-set-public')
	await expect(feld).toHaveValue(ORDNER, { timeout: 30_000 })

	await feld.fill('Gibt/Es/Nicht')
	await page.getByRole('button', { name: 'Übernehmen' }).first().click()

	await expect(page.getByText('Dieser Ordner ist nicht erreichbar.')).toBeVisible({ timeout: 15_000 })

	// Der gespeicherte Stand ist unberuehrt: Ein Fehlversuch darf keine
	// bestehende Zuordnung wegraeumen.
	await page.reload()
	await expect(feld).toHaveValue(ORDNER, { timeout: 30_000 })
})

test('ein leeres Feld entfernt die Zuordnung', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)

	const feld = page.locator('#pw-set-public')
	await expect(feld).toHaveValue(ORDNER, { timeout: 30_000 })

	await feld.fill('')
	await page.getByRole('button', { name: 'Übernehmen' }).first().click()

	await expect(feld).toHaveValue('', { timeout: 15_000 })
	await page.reload()
	await expect(feld).toHaveValue('', { timeout: 30_000 })
})
