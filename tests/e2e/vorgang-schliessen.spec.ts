import type { Locator, Page } from '@playwright/test'
import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Der Weg aus dem Vorgang hinaus — der einzige, den ein Mensch benutzt.
 *
 * Es gab ihn im Testbestand nicht. Die uebrigen Tests oeffnen Vorgaenge und
 * lesen darin; hinaus kommen sie ueber `Escape` oder einen Seitenwechsel. Beides
 * laeuft an der Ecke oben rechts vorbei, und genau dort ist der Schliessen-Knopf
 * mit #99 unerreichbar geworden (#103): Die klebende Kopfzeile (`z-index: 2`)
 * deckte den Knopf von `NcModal` (`z-index: 1`) ab.
 *
 * **Ein Test, der nur `toBeVisible()` fragt, haette den Fehler nicht gesehen.**
 * Sichtbar war das X die ganze Zeit — es nahm den Klick nur nicht an. Deshalb
 * steht hier die Frage nach dem *Treffer*, so wie sie am 2026-08-13 im Browser
 * gestellt wurde.
 */

let projekt: Projekt

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

/**
 * Welches Element ein Klick auf die Mitte dieses Kastens wirklich traefe.
 *
 * Gibt den Namen des Ziels zurueck, wenn es getroffen wurde, und sonst eine
 * Beschreibung dessen, was stattdessen im Weg stand — damit ein Fehlschlag die
 * Ursache nennt („div.pw-meta") statt nur „Klick ging daneben".
 *
 * @param seite Die Playwright-Seite.
 * @param punkt Der Punkt, der geprueft wird.
 * @param ziel Auswahl dessen, was dort getroffen werden soll.
 * @returns `ziel`, `'nichts'` oder das im Weg stehende Element.
 */
async function trefferAn(seite: Page, punkt: { x: number, y: number }, ziel: string): Promise<string> {
	return seite.evaluate(
		({ x, y, ziel }) => {
			const getroffen = document.elementFromPoint(x, y)
			if (getroffen === null) {
				return 'nichts'
			}

			return getroffen.closest(ziel) !== null
				? ziel
				: `${getroffen.tagName.toLowerCase()}.${getroffen.getAttribute('class') ?? ''}`
		},
		{ ...punkt, ziel },
	)
}

/**
 * Dasselbe fuer die Mitte eines Elements.
 *
 * @param seite Die Playwright-Seite.
 * @param was Das Element, dessen Mitte geprueft wird.
 * @param ziel Auswahl dessen, was dort getroffen werden soll.
 * @returns `ziel`, `'nichts'` oder das im Weg stehende Element.
 */
async function trefferBei(seite: Page, was: Locator, ziel: string): Promise<string> {
	const kasten = await was.boundingBox()
	if (kasten === null) {
		throw new Error(`Kein Kasten fuer ${ziel} — das Element steht gar nicht da`)
	}

	return trefferAn(seite, { x: kasten.x + kasten.width / 2, y: kasten.y + kasten.height / 2 }, ziel)
}

/**
 * Einen Vorgang ueber das Board oeffnen, so wie ein Mensch es tut.
 *
 * @param seite Die Playwright-Seite.
 * @param titel Der Titel des Vorgangs auf der Karte.
 */
async function vorgangOeffnen(seite: Page, titel: string): Promise<void> {
	await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	await expect(seite.getByText(titel)).toBeVisible({ timeout: 30_000 })
	await seite.getByText(titel).click()
	await expect(seite.locator('.pw-detail')).toBeVisible()
}

test.describe('Dienstleisterseite', () => {
	test.use({ storageState: INTERN.sitzung })

	test('das X nimmt den Klick an — und die Zeile daneben ihre eigenen', async ({ page }) => {
		await vorgangOeffnen(page, projekt.oeffentlich.title)

		// **Die Ecke gehoert dem X.** Das `padding-inline-end: 42px` an `.pw-meta`
		// haelt sie nur optisch frei — der Polsterbereich gehoert weiter zum
		// Element und war bis #103 das Trefferziel.
		expect(await trefferBei(page, page.locator('.modal-container__close'), '.modal-container__close'))
			.toBe('.modal-container__close')

		// **Und die Zeile daneben gehoert weiter ihr selbst.**
		expect(await trefferBei(page, page.locator('.pw-vischoice'), '.pw-vischoice'))
			.toBe('.pw-vischoice')

		// **Der Kopf deckt weiterhin, was unter ihm weggescrollt ist.**
		//
		// Diese dritte Zusicherung ist die eigentlich teure. Der naheliegende Weg,
		// dem X seine Ecke zurueckzugeben, ist `pointer-events: none` am Kopf —
		// und er traegt nicht: Damit wird die *ganze* Zeile durchlaessig. Gemessen
		// wurden 70 Punkte innerhalb der Kopfzeile, an denen ein unter ihr
		// weggescrollter `button.pw-abschnitt__aktion` anklickbar wurde: dieselbe
		// Fehlerklasse wie #103, nur andersherum. Die beiden Zusicherungen oben
		// bleiben dabei gruen — allein faenden sie den Rueckfall nicht.
		//
		// Getroffen wird der untere Rand des Kopfes, links, weit weg vom X.
		const behaelter = page.locator('.modal-container__content')
		await behaelter.evaluate((el) => {
			el.scrollTop = el.scrollHeight
		})
		await expect.poll(async () => behaelter.evaluate((el) => el.scrollTop)).toBeGreaterThan(0)

		const kopf = await page.locator('.pw-kopf').boundingBox()
		expect(await trefferAn(page, { x: kopf!.x + 40, y: kopf!.y + kopf!.height - 2 }, '.pw-kopf'))
			.toBe('.pw-kopf')
	})

	test('der Klick auf das X schliesst den Vorgang', async ({ page }) => {
		await vorgangOeffnen(page, projekt.oeffentlich.title)

		// Ueber die Klasse und nicht ueber die Beschriftung: Der Knopf gehoert
		// `NcModal`, seine Beschriftung uebersetzt Nextcloud, und ein Test, der an
		// „Schließen" haengt, bricht beim naechsten Sprachwechsel des Testkontos.
		//
		// Playwright prueft vor dem Klick selbst, ob das Element Zeigerereignisse
		// bekommt — dieser Klick waere vor #103 mit „intercepts pointer events"
		// gescheitert. Die Zusicherung oben sagt trotzdem mehr: Sie nennt, *wer*
		// im Weg stand.
		await page.locator('.modal-container__close').click()

		await expect(page.locator('.pw-detail')).toHaveCount(0)

		// Und das Board steht wieder da — sonst waere „geschlossen" auch ein
		// abgestuerztes Overlay.
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible()
	})
})
