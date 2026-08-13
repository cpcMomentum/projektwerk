import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Der Überblick als Einstieg (#76, entschieden am 2026-08-13).
 *
 * **Zwei Zusicherungen, und die zweite ist die teure.**
 *
 * 1. Wer die App ohne Pfad öffnet, landet auf dem Überblick — nicht mehr auf
 *    der Projektliste. Das ist die ganze Entscheidung aus #76.
 * 2. Die Seite zeigt, **was bei der Kundenseite liegt** — auch an einem
 *    Vorgang, der dem Betrachter nicht gehört. Genau darin unterscheidet sie
 *    sich von „Meine Aufgaben", und ohne diesen Unterschied wäre sie die Seite,
 *    die niemand öffnet.
 *
 * Der zweite Punkt ist gegen die naheliegende Fehlimplementierung gerichtet:
 * `task#index` wiederzuverwenden. Der Test legt den wartenden Schritt deshalb
 * an einem Vorgang an, für den `INTERN` **nicht** verantwortlich ist.
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

		// **Die Gegenprobe.** Ohne sie wäre der Test auch grün, wenn beide
		// Ansichten übereinander stünden.
		await expect(page.getByRole('heading', { name: 'Projekte', exact: true })).toHaveCount(0)

		// Und die Projektliste ist weiter erreichbar — sie ist nur umgezogen.
		await page.getByRole('link', { name: 'Projekte' }).click()
		await expect(page.getByRole('heading', { name: 'Projekte' })).toBeVisible()
		expect(page.url()).toContain('#/boards')
	})

	test('der Überblick zeigt, was bei der Kundenseite liegt — mit Namen', async ({ page }) => {
		await page.goto(APP_PFAD)
		await expect(page.getByRole('heading', { name: 'Überblick' })).toBeVisible({ timeout: 30_000 })

		const abschnitt = page.locator('.pw-ov__block', { hasText: 'Wartet auf die Kundenseite' })
		await expect(abschnitt).toBeVisible()

		const zeile = abschnitt.locator('.pw-ov__row', { hasText: projekt.oeffentlich.title })
		await expect(zeile).toBeVisible()

		// **Namen, keine Kennungen** — dieselbe Zusicherung wie in #104, und
		// hier steht sie auf der Startseite.
		await expect(zeile).toContainText(KUNDE.name)
		await expect(zeile).not.toContainText(KUNDE.uid)

		// Die Herkunft gehört an die Zeile: Auf einer projektübergreifenden
		// Seite ist der Ort die halbe Information.
		await expect(zeile).toContainText(projekt.titel)

		// Und der Klick führt ins Board, mit dem Vorgang offen — derselbe Weg
		// wie der Deep-Link, kein zweiter Ort für einen Vorgang.
		await zeile.click()
		await expect(page.locator('.pw-detail')).toBeVisible()
		await expect(page.locator('.pw-detail')).toContainText(projekt.oeffentlich.title)
	})

	test('der zweite Abschnitt zählt die Projekte', async ({ page }) => {
		await page.goto(APP_PFAD)
		await expect(page.getByRole('heading', { name: 'Überblick' })).toBeVisible({ timeout: 30_000 })

		const abschnitt = page.locator('.pw-ov__block', { hasText: 'Projekte mit Bewegung' })
		const zeile = abschnitt.locator('.pw-ov__row', { hasText: projekt.titel })
		await expect(zeile).toBeVisible()

		// Zwei offene Vorgänge legt `projektAufbauen` an, einer davon wartet.
		await expect(zeile).toContainText('2 offen')
		await expect(zeile).toContainText('1 wartet')
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
	 * Browser, gegen das ausgelieferte DOM — eine Regel, die im JOIN stimmt und
	 * in der Oberfläche trotzdem durchscheint, wäre gebrochen.
	 */
	test('findet den internen Vorgang nirgends im Überblick', async ({ page }) => {
		await page.goto(APP_PFAD)
		await expect(page.getByRole('heading', { name: 'Überblick' })).toBeVisible({ timeout: 30_000 })

		// Erst die Gegenprobe: Der öffentliche Vorgang ist da. Ohne sie wäre
		// der Test auch bei einer leeren Seite grün.
		await expect(page.locator('.pw-ov__block', { hasText: 'Projekte mit Bewegung' })).toContainText(projekt.titel)

		expect(await page.content()).not.toContain(projekt.intern.title)
	})
})
