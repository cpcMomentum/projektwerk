import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Der Überblick als Einstieg (#76), jetzt als **Kachel-Dashboard** (#226).
 *
 * **Zwei Zusicherungen, und die zweite ist die teure.**
 *
 * 1. Wer die App ohne Pfad öffnet, landet auf dem Überblick — nicht auf der
 *    Projektliste.
 * 2. Die Seite zeigt den Zustand eines Projekts — auch die Wartezeit an einem
 *    Vorgang, der dem Betrachter nicht gehört. Genau darin unterscheidet sie
 *    sich von „Meine Aufgaben".
 *
 * **Was der Umbau geändert hat:** Der Überblick zeigt die Projekte als Kacheln
 * mit kanonischen Status-Zählern (Neu/Offen/Wartet/Erledigt), nicht mehr als
 * Listen einzelner Vorgänge mit Namen. Wer konkret wartet, sieht man im Projekt;
 * das Dashboard trägt die Zahl. Deshalb prüft dieser Test die Wartezeit am
 * **Wartet-Zähler der Kachel**, nicht mehr an einer Namenszeile.
 */

let projekt: Projekt

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())

	const kontext = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const api = await Api.fuer(kontext.request)
		// „Wartet auf Kunde" ist berechnet und nicht setzbar (§9): Es entsteht
		// aus einem offenen Schritt, der einer Person mit Rolle `external`
		// gehört.
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

	test('die App startet auf dem Überblick, nicht auf der Projektliste', async ({ page }) => {
		// Ohne Pfad — so, wie der Menüeintrag in Nextcloud die App aufruft.
		await page.goto(APP_PFAD)

		await expect(page.getByRole('heading', { name: 'Überblick' })).toBeVisible({ timeout: 30_000 })

		// **Die Gegenprobe.** Das Dashboard trägt die Kennzahlen-Karte; die
		// Projektliste tut das nicht. Ohne sie wäre der Test auch grün, wenn
		// beide Ansichten übereinander stünden.
		await expect(page.locator('.pw-kpicard')).toBeVisible()

		// Und die Projektliste ist weiter erreichbar — sie ist nur umgezogen.
		await page.getByRole('link', { name: 'Projekte' }).click()
		await expect(page.getByRole('heading', { name: 'Projekte' })).toBeVisible()
		expect(page.url()).toContain('#/boards')
	})

	test('der Überblick zeigt das Projekt als Kachel mit Wartet-Zähler', async ({ page }) => {
		await page.goto(APP_PFAD)
		await expect(page.getByRole('heading', { name: 'Überblick' })).toBeVisible({ timeout: 30_000 })

		const kachel = page.locator('.pw-tile', { hasText: projekt.titel })
		await expect(kachel).toBeVisible()

		// Der wartende Vorgang schlägt sich im Wartet-Zähler nieder — die
		// Kennzeichnung steckt in der Beschriftung der ganzen Kachel (§9:
		// nicht nur Farbe).
		await expect(kachel).toHaveAttribute('aria-label', /1 wartet/)

		// Der Klick führt ins Board des Projekts — kein zweiter Ort für einen
		// Vorgang, in Stufe 2 wird daraus das Projekt-Dashboard.
		await kachel.click()
		await expect(page).toHaveURL(/#\/boards\/\d+/)
	})
})

test.describe('Kundenseite', () => {
	test.use({ storageState: KUNDE.sitzung })

	/**
	 * **Der Überblick ist der breiteste Lesepfad der App** — und damit die
	 * Stelle, an der ein Ausfall der Sichtbarkeitsregel am sichtbarsten wäre:
	 * auf der Startseite, ohne dass jemand etwas anklickt.
	 *
	 * Die Leak-Matrix prüft dieselbe Zusage am Endpunkt. Hier steht sie im
	 * Browser, gegen das ausgelieferte DOM.
	 */
	test('findet den internen Vorgang nirgends im Überblick', async ({ page }) => {
		await page.goto(APP_PFAD)
		await expect(page.getByRole('heading', { name: 'Überblick' })).toBeVisible({ timeout: 30_000 })

		// Erst die Gegenprobe: Das Projekt ist als Kachel da. Ohne sie wäre der
		// Test auch bei einer leeren Seite grün.
		await expect(page.locator('.pw-tile', { hasText: projekt.titel })).toBeVisible()

		// Kein interner Vorgang im ausgelieferten DOM — die Kacheln zeigen ohnehin
		// nur Zähler, keine Vorgangstitel; ein Leak wäre hier trotzdem sichtbar.
		expect(await page.content()).not.toContain(projekt.intern.title)
	})
})
