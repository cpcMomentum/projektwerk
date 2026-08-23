import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Kommentare erben die Sichtbarkeit des Vorgangs — im Browser nachgeprueft.
 *
 * `CommentWritePathTest` prueft die Regel am Dienst, die Leak-Matrix an der
 * Antwort. Hier steht die dritte Frage: Kommt ein Kommentar an einem internen
 * Vorgang irgendwo in dem an, was die Kundenseite ausgeliefert bekommt? Der
 * Kommentar traegt dafuer eine **eigene** Marke, getrennt von der des Tickets —
 * sonst waere schon gruen, was allein das verborgene Ticket verbirgt.
 *
 * Beide Richtungen, wie ueberall: Was verborgen bleiben soll, bleibt es. Was
 * offen ist, kommt an. Einzeln ist jede Haelfte wertlos.
 */

let projekt: Projekt
let geheimwort: string
let internerKommentar: string
let offenerKommentar: string

test.beforeAll(async ({ browser }) => {
	geheimwort = marke()
	projekt = await projektAufbauen(browser, geheimwort)

	internerKommentar = `${marke()}KOMMINTERN`
	offenerKommentar = `${marke()}KOMMOFFEN`

	const kontext = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const api = await Api.fuer(kontext.request)
		await api.kommentarAnlegen(projekt.boardId, projekt.intern.id, `Intern besprochen: ${internerKommentar}`)
		await api.kommentarAnlegen(projekt.boardId, projekt.oeffentlich.id, `Fuer alle: ${offenerKommentar}`)
	} finally {
		await kontext.close()
	}
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test.describe('Kundenseite', () => {
	test.use({ storageState: KUNDE.sitzung })

	test('sieht den Kommentar am offenen Vorgang', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })

		// Die Gegenprobe zur Gegenprobe: Ohne sie waere ein Overlay, das gar
		// keine Kommentare laedt, in der Pruefung darunter still gruen.
		await page.getByText(projekt.oeffentlich.title).click()
		await expect(page.getByText(offenerKommentar)).toBeVisible()
	})

	test('findet den internen Kommentar nirgends im DOM', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })

		// Das Overlay des offenen Vorgangs mit aufmachen: Der Kommentarbereich
		// existiert erst dann, und ein Leck ueber eine zu weit gefasste Abfrage
		// zeigte sich genau dort — nicht auf dem Board.
		await page.getByText(projekt.oeffentlich.title).click()
		await expect(page.getByText(offenerKommentar)).toBeVisible()

		const inhalt = await page.content()
		expect(
			inhalt.includes(internerKommentar),
			`Die Marke "${internerKommentar}" steht im ausgelieferten DOM der Kundenseite`,
		).toBe(false)
	})

	test('bekommt ihn auch nicht ueber die API', async ({ request }) => {
		// Eine Schicht tiefer. Die Oberflaeche koennte etwas verbergen, was die
		// Antwort trotzdem enthaelt — dann wandert das Leck beim naechsten Umbau
		// nach oben.
		const api = await Api.fuer(request)

		const liste = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets`)
		expect(JSON.stringify(liste)).not.toContain(internerKommentar)

		const detail = await api.lesen(`/api/v1/boards/${projekt.boardId}/tickets/${projekt.oeffentlich.id}`)
		expect(JSON.stringify(detail)).not.toContain(internerKommentar)
	})
})

test.describe('Dienstleisterseite', () => {
	test.use({ storageState: INTERN.sitzung })

	test('sieht ihren internen Kommentar', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.intern.title)).toBeVisible({ timeout: 30_000 })

		await page.getByText(projekt.intern.title).click()
		await expect(page.getByText(internerKommentar)).toBeVisible()
	})

	test('schreibt, aendert und loescht einen Kommentar ueber die Oberflaeche', async ({ page }) => {
		// Der Aufbau legt seine Kommentare ueber die API an — schnell, aber damit
		// bliebe der Weg ungeprueft, den ein Mensch nimmt. Dieser Test geht ihn:
		// tippen, speichern, aendern, loeschen.
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		await page.getByText(projekt.oeffentlich.title).click()

		await expect(page.locator('.pw-comment')).toHaveCount(1)

		const getippt = `${offenerKommentar}GETIPPT`
		await page.locator('.pw-comment-new').getByRole('textbox').fill(getippt)
		await page.getByRole('button', { name: 'Kommentieren' }).click()

		// **Nicht ueber `hasText` eingrenzen.** Beim Aendern weicht der Text dem
		// Eingabefeld, und der Wert eines `textarea` ist kein Textinhalt — ein
		// Locator mit `hasText` loeste nach dem ersten Klick ins Leere auf.
		// Stattdessen die Position: Der Verlauf ist aelteste zuerst sortiert, der
		// neue steht also hinten. Die Eingabezeile zaehlt nicht mit, weil sie
		// `pw-comment-new` traegt und nicht `pw-comment`.
		await expect(page.locator('.pw-comment')).toHaveCount(2)
		const eigener = page.locator('.pw-comment').last()
		await expect(eigener).toContainText(getippt)

		// `exact`, weil `getByRole` den Namen als Teilstring sucht: „Ändern"
		// traefe sonst auch „Sichtbarkeit ändern" im selben Overlay, und die
		// Zeile pruefte den falschen Knopf.
		await eigener.getByRole('button', { name: 'Ändern', exact: true }).click()
		const nachher = `${getippt}NEU`
		await eigener.getByRole('textbox').fill(nachher)
		await eigener.getByRole('button', { name: 'Speichern' }).click()

		await expect(eigener).toContainText(nachher)

		// Loeschen ist zweistufig: Der erste Klick blendet die Knopfzeile aus und
		// die Rueckfrage ein — im Overlay selbst, nicht in einem zweiten Dialog.
		// Danach gibt es wieder genau einen Knopf „Löschen".
		await eigener.getByRole('button', { name: 'Löschen', exact: true }).click()
		await expect(eigener).toContainText('endgültig entfernt')
		await eigener.getByRole('button', { name: 'Löschen', exact: true }).click()

		await expect(page.locator('.pw-comment')).toHaveCount(1)
		await expect(page.getByText(nachher)).toHaveCount(0)
	})

	/**
	 * Die @-Erwähnung von der Auswahl bis zur Anzeige (#202, Teil 2).
	 *
	 * Getippt wird `@` und ein Stück des Namens, aus der Auswahl kommt die
	 * Person, gespeichert wird die **Kennung** (`@pw-e2e-kunde`) — genau das
	 * Format, das der Server parst. In der Anzeige darf keine rohe Kennung
	 * stehen: `renderBody` löst sie zum hervorgehobenen Namen auf.
	 *
	 * Die Auswahl speist sich aus der sichtbarkeitsgefilterten Menge; am
	 * öffentlichen Vorgang gehört die Kundenseite dazu. Die Leak-Seite der
	 * Regel prüft `CommentWritePathTest` am Dienst — hier steht der sichtbare
	 * Weg durch die Oberfläche.
	 */
	test('erwaehnt jemanden ueber die Auswahl und zeigt den Namen statt der Kennung', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		await page.getByText(projekt.oeffentlich.title).click()

		const feld = page.locator('.pw-comment-new').getByRole('textbox')
		await feld.click()
		// `@` öffnet die Auswahl, der Namensteil grenzt sie ein.
		await feld.pressSequentially('@Kunden')

		// Die Auswahl hängt (wie in NcRichContenteditable üblich) am `body`, nicht
		// im Overlay — deshalb ohne Eingrenzung auf `.pw-comment-new`.
		const vorschlag = page.getByRole('option', { name: /E2E Kundenseite/ })
		await expect(vorschlag).toBeVisible({ timeout: 10_000 })
		await vorschlag.click()

		await page.getByRole('button', { name: 'Kommentieren' }).click()

		await expect(page.locator('.pw-comment')).toHaveCount(2)
		const eigener = page.locator('.pw-comment').last()

		// Angezeigt wird der Name, hervorgehoben — nicht die Kennung.
		await expect(eigener.locator('.pw-comment__text strong')).toContainText('@E2E Kundenseite')
		await expect(eigener.locator('.pw-comment__text')).not.toContainText('pw-e2e-kunde')

		// Aufraeumen, damit der Zaehler-Test wieder von genau einem Kommentar ausgeht.
		await eigener.getByRole('button', { name: 'Löschen', exact: true }).click()
		await eigener.getByRole('button', { name: 'Löschen', exact: true }).click()
		await expect(page.locator('.pw-comment')).toHaveCount(1)
	})

	test('der Zaehler auf der Karte zieht mit', async ({ page }) => {
		// Der Zaehler steht auf der Karte, nicht im Overlay — er kommt aus
		// `ticket#index` und nicht aus `ticket#show`. Ohne diesen Test bliebe
		// unbemerkt, wenn das Overlay nach einem Kommentar nur sich selbst neu
		// laedt und das Board mit einer veralteten Zahl stehen bliebe.
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		const karte = page.locator('.pw-card', { hasText: projekt.oeffentlich.title })
		await expect(karte).toBeVisible({ timeout: 30_000 })

		// Die Zahl steht nicht als Text auf der Karte, sondern nur im
		// `aria-label` des Symbols — sichtbar ist ein Sprechblasensymbol. Wer
		// hier auf sichtbaren Text prueft, prueft nichts.
		//
		// **Einzahl.** Hier stand „1 Kommentare", weil die Beschriftung ueber
		// `t()` mit Platzhalter lief statt ueber `n()`. Genau der Fall, der ohne
		// diese Zeile beim naechsten Umbau zurueckkommt.
		await expect(karte.getByRole('img', { name: '1 Kommentar', exact: true })).toBeVisible()

		await page.getByText(projekt.oeffentlich.title).click()
		await page.locator('.pw-comment-new').getByRole('textbox').fill('Noch einer.')
		await page.getByRole('button', { name: 'Kommentieren' }).click()
		await expect(page.locator('.pw-comment')).toHaveCount(2)

		// Escape statt eines benannten Knopfes: Die Beschriftung des
		// Schliessknopfes gehoert NcModal und nicht uns.
		await page.keyboard.press('Escape')
		await expect(karte.getByRole('img', { name: '2 Kommentare' })).toBeVisible()
	})

	/**
	 * Gefunden beim Geraetedurchgang am 2026-08-10, hier festgehalten.
	 *
	 * Wer ueber die Tastatur schreibt, druckt auf „Kommentieren" — und genau
	 * dann leert sich das Feld, der Knopf wird dadurch deaktiviert und nimmt den
	 * Fokus mit auf den `body`. Danach faengt man das Tabben von vorn an.
	 * Dieselbe Falle wie beim Ausklappen der aelteren Erledigten in `BoardView`.
	 */
	test('der Fokus faellt nach dem Schreiben nicht auf den Rumpf zurueck', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		await page.getByText(projekt.oeffentlich.title).click()

		await page.locator('.pw-comment-new').getByRole('textbox').focus()
		await page.keyboard.type('Per Tastatur geschrieben.')
		await page.keyboard.press('Tab')
		await page.keyboard.press('Enter')

		await expect(page.getByText('Per Tastatur geschrieben.')).toBeVisible()
		await expect
			.poll(async () => page.evaluate(() => !!document.activeElement?.closest?.('.pw-comment-new')))
			.toBe(true)
	})

	/**
	 * Ebenfalls aus dem Geraetedurchgang: Eine eingefuegte Tabelle ragte auf
	 * 390 px um 14 px aus dem Kommentar und liess sich nicht schieben.
	 *
	 * Die Ursache ist eine Eigenheit von CSS, die man nicht sieht, sondern
	 * misst: `overflow` **greift auf `display: table` nicht** — der berechnete
	 * Wert faellt auf `visible` zurueck. Erst `display: block` macht aus der
	 * Tabelle einen Kasten, der scrollen kann. Genau diese Klasse Fehler faengt
	 * kein Blick auf den Code.
	 */
	test('eine breite Tabelle bleibt im Kommentar und laesst sich schieben', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 })
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		await page.getByText(projekt.oeffentlich.title).click()

		// **Zeilenweise mit Enter statt `fill()` mit `\n`.** Das Eingabefeld ist
		// seit den @-Erwähnungen (#202) ein `contenteditable`, kein `textarea`.
		// Playwrights `fill()` schreibt ein `\n` dort als nichts — der Umbruch
		// geht verloren und die Tabelle bliebe eine Zeile. Ein Mensch tippt (oder
		// fügt ein) mit echten Umbrüchen; die entstehen nur über Enter. Erst so
		// steht der Umbruch im Wert und die Tabelle rendert (empirisch geprüft).
		const tabellenzeilen = [
			'| Position | Bezeichnung | Menge | Einzelpreis | Gesamt |',
			'|---|---|---:|---:|---:|',
			'| 1 | Standortanalyse inklusive Begehung | 1 | 2.400,00 | 2.400,00 |',
		]
		const feld = page.locator('.pw-comment-new').getByRole('textbox')
		await feld.click()
		for (let i = 0; i < tabellenzeilen.length; i++) {
			await feld.pressSequentially(tabellenzeilen[i])
			if (i < tabellenzeilen.length - 1) {
				await page.keyboard.press('Enter')
			}
		}
		await page.getByRole('button', { name: 'Kommentieren' }).click()

		const tabelle = page.locator('.pw-comment__text table')
		await expect(tabelle).toBeVisible()

		const befund = await tabelle.evaluate((el) => {
			const kommentar = el.closest('.pw-comment__text')
			if (kommentar === null) {
				throw new Error('Die Tabelle haengt nicht in einem Kommentar')
			}

			const a = el.getBoundingClientRect()
			const b = kommentar.getBoundingClientRect()
			el.scrollLeft = 999

			return {
				ragtRaus: Math.round(a.right - b.right),
				zuBreit: el.scrollWidth > el.clientWidth,
				laesstSichSchieben: el.scrollLeft > 0,
			}
		})

		expect(befund.ragtRaus, 'Die Tabelle ragt aus dem Kommentar').toBeLessThanOrEqual(1)
		// Nur wenn sie ueberhaupt zu breit ist, muss sie sich schieben lassen —
		// sonst pruefte die Zeile bei schmalen Tabellen eine Selbstverstaendlichkeit.
		if (befund.zuBreit) {
			expect(befund.laesstSichSchieben, 'Zu breit, aber nicht schiebbar').toBe(true)
		}
	})
})
