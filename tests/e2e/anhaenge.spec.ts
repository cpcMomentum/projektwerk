import type { Page } from '@playwright/test'
import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen, stufeWaehlen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Anhaenge an Vorgaengen — Anhaengen, Loesen und der Riegel davor.
 *
 * **Warum das im Browser geprueft wird und nicht nur im Dienst.** Der Anhang
 * ist der einzige Weg der App, der eine echte Datei anfasst: Der Browser
 * schickt ein `multipart/form-data`, der Server schreibt in einen Ordner, und
 * zurueck kommt eine Datei-ID. Jeder dieser drei Schritte kann fuer sich
 * stimmen und die Kette trotzdem reissen.
 *
 * Der Ordner entsteht ueber WebDAV — dieselbe Person, die ihn danach eintraegt,
 * und derselbe Weg lokal wie in der CI.
 */

let projekt: Projekt

const ORDNER = `E2E-Anhaenge-${Date.now().toString(36)}`

test.use({ storageState: INTERN.sitzung })

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())

	const kontext = await browser.newContext({ storageState: INTERN.sitzung })

	try {
		const seite = await kontext.request.get(APP_PFAD)
		const token = (await seite.text()).match(/data-requesttoken="([^"]+)"/)?.[1]
		if (token === undefined) {
			throw new Error('Kein data-requesttoken in der App-Seite gefunden')
		}

		const angelegt = await kontext.request.fetch(
			`/remote.php/dav/files/${INTERN.uid}/${ORDNER}`,
			{ method: 'MKCOL', headers: { requesttoken: token } },
		)
		if (!angelegt.ok() && angelegt.status() !== 405) {
			throw new Error(`Ordner anlegen: HTTP ${angelegt.status()} ${await angelegt.text()}`)
		}

		const api = await Api.fuer(kontext.request)
		await api.boardOrdnerSetzen(projekt.boardId, ORDNER)
	} finally {
		await kontext.close()
	}
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

/**
 * @param page Die Seite des Tests.
 * @param ticketId Der Vorgang, der geoeffnet werden soll.
 * @param titel Sein Titel — der Anker im Board.
 */
async function detailOeffnen(page: Page, ticketId: number, titel: string) {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(titel)).toBeVisible({ timeout: 30_000 })
	await page.getByText(titel).click()
	await expect(page.getByRole('button', { name: 'Datei anhängen' })).toBeVisible()

	return ticketId
}

test('haengt eine Datei an und zeigt sie mit ihrem Namen', async ({ page, request }) => {
	await detailOeffnen(page, projekt.oeffentlich.id, projekt.oeffentlich.title)

	await page.locator('.pw-attach__input').setInputFiles({
		name: 'angebot.txt',
		mimeType: 'text/plain',
		buffer: Buffer.from('Inhalt für den E2E-Lauf'),
	})

	// **Die Vorgangsnummer steht vorn.** Genau das haelt die Anhaenge eines
	// Vorgangs in einem flachen Ordner beieinander, weil die Dateiliste
	// alphabetisch sortiert.
	const erwartet = `${String(projekt.oeffentlich.number).padStart(4, '0')}_angebot.txt`
	await expect(page.getByText(erwartet)).toBeVisible({ timeout: 15_000 })

	// Gegenprobe beim Server: Der Name im DOM koennte auch aus einer optimistisch
	// eingefuegten Zeile stammen, die es in der Datenbank nie gab.
	const api = await Api.fuer(request)
	const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)
	expect(detail.attachments).toHaveLength(1)
	expect(detail.attachments[0].fileName).toBe(erwartet)
	expect(detail.attachments[0].fileId, 'Ohne Datei-ID ist der Anhang ein toter Verweis').toBeGreaterThan(0)
})

/**
 * **Ein zweiter Anhang gleichen Namens ueberschreibt den ersten nicht.**
 *
 * Zwei Personen, die am selben Tag „scan.pdf" anhaengen, duerfen einander nicht
 * die Datei wegnehmen. Gezaehlt wird deshalb, statt zu ersetzen.
 */
test('zaehlt bei Namensgleichheit, statt zu ueberschreiben', async ({ page, request }) => {
	await detailOeffnen(page, projekt.oeffentlich.id, projekt.oeffentlich.title)

	await page.locator('.pw-attach__input').setInputFiles({
		name: 'angebot.txt',
		mimeType: 'text/plain',
		buffer: Buffer.from('Der zweite Inhalt'),
	})

	const nummer = String(projekt.oeffentlich.number).padStart(4, '0')
	await expect(page.getByText(`${nummer}_angebot_2.txt`)).toBeVisible({ timeout: 15_000 })

	const api = await Api.fuer(request)
	const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)
	const namen = detail.attachments.map((a: { fileName: string }) => a.fileName).sort()
	expect(namen).toEqual([`${nummer}_angebot.txt`, `${nummer}_angebot_2.txt`])
})

/**
 * **Die Sichtbarkeit laesst sich nicht aendern, solange Anhaenge daran haengen**
 * (§3.10 Stufe 1).
 *
 * Der einzige Punkt, an dem ein Leck physisch wuerde: Laege die Datei erst in
 * `90_Austausch`, haette die Kundenseite sie gesehen, und keine spaetere
 * Codekorrektur naehme das zurueck.
 */
test('verweigert den Sichtbarkeitswechsel, solange Anhaenge haengen', async ({ page, request }) => {
	await detailOeffnen(page, projekt.oeffentlich.id, projekt.oeffentlich.title)

	// Ueber die Klasse des Umschalters: „Intern" steht auch auf einer Karte im
	// Hintergrund und in einem Aktionsmenue.
	await stufeWaehlen(page, 'Intern')

	// Die Absage steht **vor** der Bestaetigung: `visibility-impact` liefert die
	// Zahl mit, und eine Warnung zu bestaetigen, die ohnehin abgewiesen wuerde,
	// waere ein Handgriff ohne Wirkung.
	await expect(page.getByText(/zuerst vom Vorgang lösen/)).toBeVisible({ timeout: 15_000 })
	await expect(page.getByRole('button', { name: 'Sichtbarkeit ändern' })).toHaveCount(0)

	// Und der Vorgang steht unveraendert da: Ein abgewiesener Versuch darf nichts
	// halb erledigt hinterlassen.
	const api = await Api.fuer(request)
	const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)
	expect(detail.ticket.visibility).toBe('public')
})

test('loest einen Anhang wieder — nach Rueckfrage', async ({ page, request }) => {
	await detailOeffnen(page, projekt.oeffentlich.id, projekt.oeffentlich.title)

	const nummer = String(projekt.oeffentlich.number).padStart(4, '0')
	const zeile = page.locator('.pw-attach', { hasText: `${nummer}_angebot.txt` }).first()
	await zeile.getByRole('button').click()

	// Der Satz nennt ausdruecklich, was NICHT passiert — die Datei bleibt liegen.
	await expect(page.getByText(/diese App löscht keine Dateien/)).toBeVisible()

	await page.getByRole('button', { name: 'Lösen', exact: true }).click()

	const api = await Api.fuer(request)
	await expect
		.poll(async () => {
			const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)
			return detail.attachments.length
		})
		.toBe(1)
})
