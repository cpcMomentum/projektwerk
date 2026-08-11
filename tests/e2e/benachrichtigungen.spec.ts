import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/**
 * Die eigenen Kanalschalter, im Browser.
 *
 * **Warum das hier geprueft wird und nicht nur im Dienst.** Der angezeigte Stand
 * entsteht aus drei Stufen — Projektzeile, globale Zeile, Vorgabe — und die
 * Oberflaeche rechnet sie ein zweites Mal nach, damit ein Klick sofort wirkt.
 * Zwei Rechnungen, die auseinanderlaufen koennen, gehoeren gegeneinander
 * geprueft.
 *
 * Der Hinweis daneben ist dabei kein Beiwerk: Ohne ihn sieht ein geerbtes „an"
 * aus wie ein gesetztes.
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

test('uebernimmt die allgemeine Einstellung und merkt sich das Abschalten', async ({ page, request }) => {
	// **Den Ausgangszustand herstellen, nicht voraussetzen.** Diese Tests laufen
	// gegen eine dauerhafte Instanz; die Zeilen ueberleben den Lauf. Ein
	// frueherer Durchgang hatte den globalen Schalter umgelegt, und der Test
	// erwartete den jungfraeulichen Zustand — er war rot, ohne dass am Code
	// etwas falsch war.
	//
	// Der Zustand „gar keine Zeile" (Hinweis „Vorgabe") laesst sich von aussen
	// nicht wiederherstellen: Es gibt keinen Weg, eine Einstellung auf
	// „ungesetzt" zurueckzudrehen, nur auf „an". Funktional ist das dasselbe,
	// nur der Hinweis lautet anders — deshalb prueft dieser Test die beiden
	// Zustaende, die sich herstellen lassen.
	const api = await Api.fuer(request)
	await api.kanalAusnahmenLeeren()
	await api.kanalSetzen('mail', true, 0)

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)

	const zeile = page.locator('.pw-settings__row', { hasText: 'E-Mail' })
	await expect(zeile).toBeVisible({ timeout: 30_000 })

	const schalter = zeile.locator('input[type="checkbox"]')
	await expect(schalter, 'Ohne Projekt-Ausnahme gilt die allgemeine Einstellung').toBeChecked()
	await expect(zeile).toContainText('Aus Ihrer allgemeinen Einstellung übernommen')

	await schalter.uncheck()

	// Der Hinweis muss mitziehen — sonst stuende „Vorgabe" an einem Schalter,
	// der ausdruecklich gesetzt wurde.
	await expect(zeile).toContainText('Für dieses Projekt festgelegt', { timeout: 15_000 })

	// Gegenprobe beim Server: Der Haken im DOM koennte auch nur lokal sitzen.
	const stand = await api.lesen('/api/v1/notify-prefs')
	expect(stand.boards[String(projekt.boardId)].mail).toBe(false)

	// Und er ueberlebt einen Neuaufbau.
	await page.reload()
	await expect(zeile.locator('input[type="checkbox"]')).not.toBeChecked({ timeout: 30_000 })
})

/**
 * **Die drei Stufen im Zusammenspiel** — der eigentliche Punkt der Bauform.
 *
 * Der globale Schalter steht in Nextclouds persoenlichen Einstellungen, der
 * Projektschalter in den Projekteinstellungen. Beide wirken auf dieselbe Zeile
 * derselben Tabelle; dass sie es tun, sieht man nur, wenn man sie gegeneinander
 * stellt.
 *
 * Gesetzt wird der globale Wert hier ueber die API und **nicht** ueber
 * Nextclouds Schaltflaeche: Deren Aufbau ist Sache der Plattform und aendert
 * sich mit jeder Version — ein Test, der daran haengt, bricht bei einem
 * NC-Update, ohne dass an unserer App etwas falsch waere. Dass die Seite
 * denselben Speicher LIEST, prueft der Schritt darunter.
 */
test('global aus, dieses Projekt an — und der Hinweis sagt woher', async ({ page, request }) => {
	const api = await Api.fuer(request)

	// **Erst aufraeumen.** Der Test davor hinterlaesst eine Projekt-Ausnahme,
	// und die schlaegt die globale Einstellung — genau das, was hier geprueft
	// werden soll, waere damit vorweggenommen. Ein Test, der von der
	// Reihenfolge abhaengt, ist beim naechsten Einschub still falsch.
	await api.kanalAusnahmenLeeren()
	await api.kanalSetzen('mail', false, 0)

	// (1) Die Einstellungsseite zeigt unseren Stand — sie liest also dieselbe
	// Quelle und haelt keinen eigenen.
	await page.goto('/index.php/settings/user/notifications')
	await expect(page.getByText('Gilt für alle Projekte ohne eigene Einstellung')).toBeVisible({ timeout: 30_000 })
	const aus = await page.locator('input[type="checkbox"]:not(:checked)').count()
	expect(aus, 'Mindestens ein Schalter muss aus sein — der gerade abgeschaltete').toBeGreaterThan(0)

	// (2) Im Projekt schlaegt die globale Einstellung durch, und der Hinweis
	// benennt sie.
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)
	const zeile = page.locator('.pw-settings__row', { hasText: 'E-Mail' })
	await expect(zeile).toBeVisible({ timeout: 30_000 })
	await expect(zeile).toContainText('Aus Ihrer allgemeinen Einstellung übernommen')
	await expect(zeile.locator('input[type="checkbox"]')).not.toBeChecked()

	// (3) Und dieses eine Projekt wieder an — die Ausnahme schlaegt die globale.
	await zeile.locator('input[type="checkbox"]').check()
	await expect(zeile).toContainText('Für dieses Projekt festgelegt', { timeout: 15_000 })

	const stand = await api.lesen('/api/v1/notify-prefs')
	expect(stand.global.mail).toBe(false)
	expect(stand.boards[String(projekt.boardId)].mail).toBe(true)
})

/**
 * Die Kanaele sind unabhaengig: Mails abschalten heisst nicht Glocke
 * abschalten.
 */
test('der zweite Kanal bleibt davon unberuehrt', async ({ page, request }) => {
	const api = await Api.fuer(request)
	await api.kanalAusnahmenLeeren()
	await api.kanalSetzen('mail', false, 0)
	await api.kanalSetzen('bell', true, 0)

	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)

	const glocke = page.locator('.pw-settings__row', { hasText: 'Glocke in Nextcloud' })
	await expect(glocke.locator('input[type="checkbox"]')).toBeChecked({ timeout: 30_000 })

	const mail = page.locator('.pw-settings__row', { hasText: 'E-Mail' })
	await expect(
		mail.locator('input[type="checkbox"]'),
		'Mails aus heisst nicht Glocke aus — die Kanaele sind unabhaengig',
	).not.toBeChecked()
})
