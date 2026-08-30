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

		// Die Verlaufs-Kurven (#232) hängen im Durchsatz — eine je Zähler. Sie
		// kommen mit der 30-Tage-Reihe vom Server und rendern auch bei einem
		// frischen Projekt (flache Linie), also immer zwei.
		await expect(page.locator('.pw-spark')).toHaveCount(2)

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
	 * Der Überblick ist ein **internes** Steuerungswerkzeug (#234). Ein Kunde,
	 * der in allen seinen Projekten extern ist, erreicht ihn gar nicht — er wird
	 * beim Öffnen der App auf seine Projekte geleitet.
	 *
	 * **Damit entfällt die frühere Browser-Prüfung „der Kunde sieht auf dem
	 * Überblick keinen internen Vorgang":** Er sieht den Überblick nicht mehr.
	 * Die Sichtbarkeitszusage selbst hängt nicht an diesem Gate — sie steht im
	 * `scopedQuery` und wird am Endpunkt von der Leak-Matrix gefahren
	 * (`overview#index`). Dieses Gate ist Verteidigung in der Tiefe auf der
	 * Produkt-/Routing-Ebene.
	 *
	 * **Geprüft wird die Abwesenheit, nicht ein festes Ziel.** Wohin der Kunde
	 * landet — sein Board bei einem Projekt, die Projektliste bei mehreren —
	 * hängt an der Zahl seiner Projekte und ist damit vom Testbestand abhängig.
	 * Dass es *nicht* der Überblick ist, hängt an nichts.
	 */
	test('wird vom Überblick weggeleitet — er ist ihm nicht zugänglich', async ({ page }) => {
		// Ohne Pfad — so, wie der Menüeintrag in Nextcloud die App aufruft.
		await page.goto(APP_PFAD)

		// Warten, bis der Rahmen steht: Den „Projekte"-Eintrag hat jede Rolle.
		await expect(page.getByRole('link', { name: 'Projekte' })).toBeVisible({ timeout: 30_000 })

		// Der Überblick ist weg: kein Menüeintrag, keine Kennzahlen-Karte, und
		// die Adresse steht nicht mehr auf dem bloßen Einstieg.
		await expect(page.getByRole('link', { name: 'Überblick' })).toHaveCount(0)
		await expect(page.locator('.pw-kpicard')).toHaveCount(0)
		expect(page.url()).not.toMatch(/#\/$/)
	})
})
