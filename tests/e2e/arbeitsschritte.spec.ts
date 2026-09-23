import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { fristLoeschen, fristSetzen, marke, personWaehlen, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Arbeitsschritte anlegen und pflegen — aus der Oberflaeche heraus.
 *
 * **Seit #308 legt die Eingabezeile nur den Titel an.** Zuweisung, Frist,
 * Beschreibung und Ergebnis werden ueber den Stift des Schritts nachgetragen —
 * die zuvor vierfeldrige Anlege-Zeile wurde als zu wuchtig empfunden. Diese
 * Tests gehen deshalb denselben Weg wie die Bedienung: erst der Titel, dann der
 * Stift.
 *
 * **Warum das ueber die API gegengeprueft wird und nicht nur im DOM.** Der
 * Fehler, der diese Tests veranlasst hat, war im DOM unsichtbar: Die Frist
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

/**
 * Einen Schritt anlegen (nur Titel) und den Stift oeffnen.
 *
 * Ein frisch angelegter Schritt hat weder Zuweisung noch Frist, dort steht also
 * der flache „Zuweisen oder Frist setzen"-Knopf (Variante C, #99); er oeffnet
 * denselben Bearbeiten-Modus wie der Stift.
 *
 * @param page Die Playwright-Seite.
 * @param titel Der Titel des neuen Schritts.
 */
async function schrittAnlegenUndOeffnen(page: Parameters<typeof personWaehlen>[0], titel: string) {
	const zeile = page.locator('.pw-step--new')
	await zeile.locator('.pw-step__neu-titel input').fill(titel)
	await page.locator('.pw-step__neu-plus').click()
	await expect(page.getByText(titel)).toBeVisible()

	const schritt = page.locator('.pw-step', { hasText: titel })
	await schritt.locator('.pw-step__flach').click()

	return schritt
}

test('traegt Zustaendige und Frist ueber den Stift nach', async ({ page, request }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
	await page.getByText(projekt.oeffentlich.title).click()

	const schritt = await schrittAnlegenUndOeffnen(page, 'Freigabe holen')

	// Fuer den Picker-Input brauchen wir die Schritt-ID (`#pw-step-user-<id>`).
	const angelegt = await schrittAusDerDatenbank(request, 'Freigabe holen')
	expect(angelegt, 'Der Schritt fehlt in der Antwort des Servers').toBeDefined()

	// Zuweisung und Frist schreiben sofort beim Aendern (kein „Fertig" noetig).
	await personWaehlen(page, `#pw-step-user-${angelegt.id}`, KUNDE.name)
	const frist = await fristSetzen(page, schritt, 20)

	await expect
		.poll(async () => (await schrittAusDerDatenbank(request, 'Freigabe holen'))?.assignedUserId)
		.toBe(KUNDE.uid)

	const nachher = await schrittAusDerDatenbank(request, 'Freigabe holen')
	// **Der Tag, der im Feld stand.** Ueber `toISOString()` waere daraus in
	// Mitteleuropa der Vortag geworden — eine Frist einen Tag zu frueh.
	expect(nachher.dueDate).toBe(frist)
	// Die Wartezeit beginnt mit dem Zuweisen; `assignedAt` ist danach gesetzt.
	expect(nachher.assignedAt).not.toBeNull()
})

/**
 * Beschreibung und Ergebnis über den Stift (#247, #308).
 *
 * Seit #308 wandert auch die Beschreibung in den Stift; sie wird dort — wie das
 * Ergebnis — gepuffert getippt und mit „Fertig" gespeichert. Die Gegenprobe am
 * Server, damit ein grüner Durchlauf nicht bloß heißt „irgendein Feld stand da".
 *
 * Im `.pw-step__felder-text` steht der Titel als erstes Textfeld, die
 * Beschreibung als zweites; das Ergebnis ist das Textarea. „Fertig" über das
 * aria-label, nicht über die Knopf-Reihenfolge: `NcSelectUsers` bringt im
 * Bearbeiten-Modus einen eigenen „Auswahl leeren"-Knopf mit.
 */
test('traegt Beschreibung und Ergebnis ueber den Stift nach', async ({ page, request }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
	await page.getByText(projekt.oeffentlich.title).click()

	const schritt = await schrittAnlegenUndOeffnen(page, 'Angebot einholen')

	await schritt.locator('.pw-step__felder-text input[type="text"]').nth(1).fill('Bei drei Anbietern anfragen')
	await schritt.locator('.pw-step__felder-text textarea').fill('Empfehlung: Hetzner')
	await schritt.locator('[aria-label="Fertig"]').click()

	await expect
		.poll(async () => (await schrittAusDerDatenbank(request, 'Angebot einholen'))?.description)
		.toBe('Bei drei Anbietern anfragen')
	await expect
		.poll(async () => (await schrittAusDerDatenbank(request, 'Angebot einholen'))?.result)
		.toBe('Empfehlung: Hetzner')
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

	// Titel anlegen, Stift oeffnen, Frist im Stift setzen (schreibt sofort).
	const schritt = await schrittAnlegenUndOeffnen(page, 'Mit Frist')
	const frist = await fristSetzen(page, schritt, 21)

	// Erst die Gegenprobe: Ohne sie wuerde die Zeile unten auch dann gruen,
	// wenn das Setzen schon nicht funktioniert haette.
	await expect
		.poll(async () => (await schrittAusDerDatenbank(request, 'Mit Frist')).dueDate)
		.toBe(frist)

	// Der Bearbeiten-Modus bleibt nach dem Schreiben offen; das Fristfeld steht
	// noch da und laesst sich ueber den Loesch-Knopf des Waehlers leeren.
	await fristLoeschen(schritt)

	await expect
		.poll(async () => (await schrittAusDerDatenbank(request, 'Mit Frist')).dueDate)
		.toBeNull()
})

/**
 * Zustaendige und Frist teilen sich auf dem Handy eine Zeile — im Stift.
 *
 * **Warum das ein Test ist und nicht nur eine CSS-Regel.** Im Bearbeiten-Modus
 * stehen Personenauswahl und Fristfeld nebeneinander; auf 390 px koennten sie
 * sich untereinander stapeln oder seitlich hinausragen. Dass sie
 * **nebeneinander** bleiben, steckt in einer einzigen `flex`-Angabe
 * (`.pw-step__picker` schrumpft, `.pw-step__datum` nicht) — eine spaetere
 * Aenderung am Kasten nimmt sie zurueck, ohne dass es jemandem auffaellt,
 * solange niemand ein Handy in die Hand nimmt. Seit #308 lebt diese Frage nur
 * noch im Stift; die Anlage-Zeile hat die beiden Felder nicht mehr.
 *
 * Geprueft wird die Zeilenlage ueber die Oberkanten und nicht ueber eine
 * Hoehe in Pixeln: Was die Felder hoch sind, haengt an Nextclouds Variablen
 * und aendert sich mit jeder Plattformversion. Dass sie **nebeneinander**
 * stehen, aendert sich damit nicht.
 */
test('auf dem Handy stehen Zustaendige und Frist im Stift nebeneinander', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 900 })

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
	await page.getByText(projekt.oeffentlich.title).click()

	const schritt = await schrittAnlegenUndOeffnen(page, 'Nebeneinander')

	const person = await schritt.locator('.pw-step__picker').boundingBox()
	const frist = await schritt.locator('.pw-step__datum').boundingBox()

	expect(person, 'Die Personenauswahl fehlt').not.toBeNull()
	expect(frist, 'Das Fristfeld fehlt').not.toBeNull()

	// Zwei Pixel Luft: Die beiden Felder sind unterschiedlich hoch und werden
	// mittig ausgerichtet, ihre Oberkanten treffen sich also nicht exakt.
	expect(
		Math.abs(person!.y - frist!.y),
		`Oberkanten ${person!.y} und ${frist!.y} — die Felder stehen untereinander`,
	).toBeLessThanOrEqual(2)

	// Nebeneinander ist nur dann ein Gewinn, wenn nichts hinausragt.
	const ueber = await schritt.locator('.pw-step__rechts').evaluate((el) => el.scrollWidth - el.clientWidth)
	expect(ueber, 'Die Schrittzeile ragt seitlich heraus').toBeLessThanOrEqual(1)
})
