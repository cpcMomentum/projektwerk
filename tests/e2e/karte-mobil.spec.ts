import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Die Karte auf Handybreite.
 *
 * **Warum es diesen Test gibt.** Beim Geraetetest am 2026-08-10 ragte die
 * Wartemarke sichtbar ueber den Kartenrand hinaus, und die Karte ist danach
 * dreimal umgebaut worden — ohne dass irgendetwas sie ansieht. Genau diese
 * Klasse Fehler findet kein Unit-Test: Der Code war jedes Mal richtig, nur zu
 * breit.
 *
 * **Ausdruecklich kein Bildvergleich.** Screenshot-Tests brechen bei jeder
 * Schriftglaettung und erzeugen Rot ohne Fehler. Geprueft wird stattdessen
 * eine Eigenschaft, die entweder gilt oder nicht: Nichts ragt aus seinem
 * Behaelter heraus.
 */

let projekt: Projekt
const LANGER_TITEL = 'Angebotsunterlagen fuer die Standortverlagerung zusammenstellen und pruefen'

test.use({
	storageState: INTERN.sitzung,
	// iPhone-Breite. Die Spalten sind dort `min(88vw, 340px)` — der Fall, in
	// dem es eng wird.
	viewport: { width: 390, height: 844 },
})

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())

	const kontext = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const api = await Api.fuer(kontext.request)
		// Ein Titel, der laenger ist als die Spalte. Ohne ihn prueft der Test
		// nur kurze Karten — und kurze Karten laufen nie ueber.
		await api.ticketAnlegen(projekt.boardId, {
			title: LANGER_TITEL,
			columnId: projekt.spalten[0].id,
			visibility: 'public',
		})
	} finally {
		await kontext.close()
	}
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test('keine Karte laeuft ueber ihren eigenen Rand', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(LANGER_TITEL)).toBeVisible({ timeout: 30_000 })

	const ueberlaeufe = await page.evaluate(() => {
		return Array.from(document.querySelectorAll('.pw-card'))
			.map((karte) => ({
				text: (karte.textContent ?? '').trim().slice(0, 60),
				// Ein Pixel Luft: Unterpixel-Layout erzeugt sonst Rauschen.
				ueber: karte.scrollWidth - karte.clientWidth,
			}))
			.filter((k) => k.ueber > 1)
	})

	expect(ueberlaeufe, `Karten mit Ueberlauf: ${JSON.stringify(ueberlaeufe)}`).toEqual([])
})

test('die Seite scrollt nicht waagerecht', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(LANGER_TITEL)).toBeVisible({ timeout: 30_000 })

	// Das Board selbst darf waagerecht scrollen — es ist ein Kanban. Der
	// Seitenkoerper darf es nicht: Dann waere der ganze Rahmen verschoben und
	// die Kopfzeile halb weg.
	const ueber = await page.evaluate(() => document.body.scrollWidth - document.body.clientWidth)
	expect(ueber, 'Der Seitenkoerper scrollt waagerecht').toBeLessThanOrEqual(1)
})

test('die Fusszeile der Karte bleibt einzeilig', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(LANGER_TITEL)).toBeVisible({ timeout: 30_000 })

	// Der Umbau vom 2026-08-10 hat die Zustandszeile ausdruecklich einzeilig
	// gebaut: Eine zweite Zeile waere genau die, die der Umbau einspart. Wenn
	// dort spaeter etwas hinzukommt, soll dieser Test es melden, statt dass es
	// auf einem Geraet auffaellt.
	const hoehen = await page.evaluate(() => {
		return Array.from(document.querySelectorAll('.pw-card__foot'))
			.map((zeile) => Math.round(zeile.getBoundingClientRect().height))
	})

	for (const hoehe of hoehen) {
		expect(hoehe, `Fusszeile ist ${hoehe} px hoch — das sind zwei Zeilen`).toBeLessThanOrEqual(26)
	}
})

/**
 * **Der Sichtbarkeitsschalter bleibt einzeilig und damit 34 px hoch** (#103).
 *
 * Dieselbe Klasse Fehler wie bei der Fusszeile oben, nur im Vorgang: „Alle
 * Beteiligten" brach bei knappem Platz um, das Segment wurde dadurch hoeher als
 * seine Nachbarn, und die Gruppe rutschte unter die Nummer statt rechtsbuendig
 * zu stehen.
 *
 * **34 px ist keine Geschmacksfrage**, sondern `--default-clickable-area` — die
 * dokumentierte Untergrenze fuer Anklickbares. Gemessen wurde am 2026-08-13:
 * ohne `white-space: nowrap` sind es 43 px, mit 34, und zwar bei **jeder**
 * Breite. Die Vermutung in #103, `NcFormBox` bemesse zu gross und tiefer ginge
 * es nur ueber ein drittes Eingreifen in fremde CSS-Module, war falsch — es war
 * allein der Umbruch.
 *
 * Der Wert wird zur Laufzeit gelesen und nicht als 34 hartkodiert: Nextcloud
 * darf ihn aendern, und ein Test, der eine fremde Konstante nachschreibt, meldet
 * dann einen Fehler, der keiner ist.
 */
test('der Sichtbarkeitsschalter im Vorgang bleibt einzeilig', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(page.getByText(LANGER_TITEL)).toBeVisible({ timeout: 30_000 })
	await page.getByText(LANGER_TITEL).click()

	const schalter = page.locator('.pw-vischoice')
	await expect(schalter).toBeVisible()

	const gemessen = await page.evaluate(() => {
		const soll = getComputedStyle(document.documentElement).getPropertyValue('--default-clickable-area').trim()

		return {
			hoehe: document.querySelector('.pw-vischoice')!.getBoundingClientRect().height,
			soll: Number.parseFloat(soll) || 34,
		}
	})

	// Ein Pixel Luft gegen Unterpixel-Layout, wie bei den Karten oben.
	expect(
		gemessen.hoehe,
		`Der Schalter ist ${gemessen.hoehe} px hoch statt ${gemessen.soll} — die Beschriftung bricht um`,
	).toBeLessThanOrEqual(gemessen.soll + 1)
})
