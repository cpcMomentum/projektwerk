import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/**
 * Die Zusage in die andere Richtung.
 *
 * `sichtbarkeit.spec.ts` prueft, dass Internes verborgen bleibt. Das allein
 * waere auch von einer App zu erfuellen, die der Kundenseite grundsaetzlich
 * nichts zeigt. Hier wird geprueft, dass die Freigabe ankommt: Was die
 * Dienstleisterseite bewusst oeffnet, sieht die Kundenseite auch.
 *
 * Beides zusammen ist die Aussage — einzeln ist jede Haelfte wertlos.
 */

let projekt: Projekt
let geheimwort: string

test.beforeAll(async ({ browser }) => {
	geheimwort = marke()
	projekt = await projektAufbauen(browser, geheimwort)
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
})

test('ein freigegebener Vorgang kommt bei der Kundenseite an', async ({ browser }) => {
	// Erst die Gegenprobe: Vorher ist er dort nicht. Ohne sie wuerde der Test
	// auch dann gruen, wenn die Kundenseite von Anfang an alles saehe.
	const vorher = await browser.newContext({ storageState: KUNDE.sitzung })
	try {
		const seite = await vorher.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(seite.getByText(projekt.oeffentlich.title)).toBeVisible({ timeout: 30_000 })
		expect(await seite.content()).not.toContain(geheimwort)
	} finally {
		await vorher.close()
	}

	// Die Dienstleisterseite gibt den internen Vorgang frei — ueber die
	// Oberflaeche, nicht ueber die API: Der zweistufige Weg (aendern, dann
	// bestaetigen) ist selbst Teil der Zusage und soll mitgeprueft werden.
	const innen = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const seite = await innen.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await seite.getByText(projekt.intern.title).click()

		await seite.getByRole('button', { name: 'Ändern', exact: true }).click()
		// Die Auswahl, nicht irgendein gleichlautender Text: Hinter dem Overlay
		// liegt das Board, und dessen Karten tragen dieselben Stufennamen als
		// Marke. `.pw-visrow` grenzt auf die Auswahlzeile ein.
		await seite.locator('.pw-visrow').getByText('Alle Beteiligten', { exact: true }).click()
		await seite.getByRole('button', { name: 'Übernehmen' }).click()

		// Die Rueckfrage erscheint nur, wenn jemand Zugriff *verliert*. Beim
		// Hochstufen verliert niemand etwas, also darf sie ausbleiben — der
		// Test darf daran nicht haengen, aber auch nicht daran vorbeilaufen.
		//
		// **Erst warten, dann fragen.** „Übernehmen" loest einen Netzaufruf aus
		// (`visibility-impact`); ein `isVisible()` unmittelbar danach fragt
		// womoeglich, bevor die Antwort da ist, und bekaeme in beiden Faellen
		// „nein". Der Test liefe an einer noetigen Bestaetigung vorbei und
		// scheiterte spaeter mit einem Bild, das nicht auf die Ursache zeigt.
		// `or()` wartet, bis die Oberflaeche *eine* der beiden Stufen erreicht
		// hat — die Rueckfrage oder wieder den Ruhezustand.
		//
		// **`exact` ist hier keine Kosmetik.** `getByRole` sucht den barrierefreien
		// Namen als *Teilstring*: „Ändern" trifft „Sichtbarkeit ändern" mit. Ohne
		// `exact` waeren beide Locators dieselbe Menge, und die Unterscheidung
		// zwischen Rueckfrage und Ruhezustand loeste sich still auf.
		const rueckfrage = seite.getByRole('button', { name: 'Sichtbarkeit ändern' })
		const ruhezustand = seite.getByRole('button', { name: 'Ändern', exact: true })
		await expect(rueckfrage.or(ruhezustand).first()).toBeVisible()

		if (await rueckfrage.isVisible()) {
			await rueckfrage.click()
		}

		await expect(seite.getByText('Alle Beteiligten').first()).toBeVisible()
	} finally {
		await innen.close()
	}

	const nachher = await browser.newContext({ storageState: KUNDE.sitzung })
	try {
		const seite = await nachher.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(seite.getByText(projekt.intern.title)).toBeVisible({ timeout: 30_000 })
	} finally {
		await nachher.close()
	}
})

test('das Herunterstufen nennt die Betroffenen und entzieht den Zugriff', async ({ browser }) => {
	// Die andere Richtung — und die einzige, in der die Rueckfrage ueberhaupt
	// erscheint: Erst beim Herunterstufen verliert jemand Zugriff. Ohne diesen
	// Test bliebe der Bestaetigungszweig der Oberflaeche ungeprueft, und §9
	// verlangt dort ausdruecklich **Namen statt einer allgemeinen Warnung**.
	const innen = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const seite = await innen.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await seite.getByText(projekt.oeffentlich.title).click()

		await seite.getByRole('button', { name: 'Ändern', exact: true }).click()
		// Ohne die Eingrenzung haengt diese Zeile an der Reihenfolge: Karten mit
		// interner Sichtbarkeit tragen die Marke „Intern", und solange eine auf
		// dem Board liegt, treffen zwei Elemente. Gruen ist sie heute nur, weil
		// der Test darueber den einzigen internen Vorgang vorher hochstuft.
		await seite.locator('.pw-visrow').getByText('Intern', { exact: true }).click()
		await seite.getByRole('button', { name: 'Übernehmen' }).click()

		// Hier muss die Rueckfrage kommen — und sie muss die Kundenseite beim
		// Namen nennen. Eine Warnung ohne Namen liest man zweimal und danach nie
		// wieder; genau deshalb steht die Forderung in §9.
		const rueckfrage = seite.getByRole('button', { name: 'Sichtbarkeit ändern' })
		await expect(rueckfrage).toBeVisible()
		await expect(seite.locator('.pw-viscontrol__losing')).toContainText(KUNDE.name)

		// Zurueck im Ruhezustand — und `exact` entscheidet hier ueber die Aussage:
		// Bliebe die Rueckfrage stehen, weil das Speichern scheitert, traefe
		// „Ändern" als Teilstring den Knopf „Sichtbarkeit ändern" und die Zeile
		// wuerde gruen, obwohl nichts gespeichert wurde. Der Lauf scheiterte dann
		// erst unten an der Kundenprobe, mit einem Bild, das nicht auf die
		// Ursache zeigt.
		await rueckfrage.click()
		await expect(seite.getByRole('button', { name: 'Ändern', exact: true })).toBeVisible()
	} finally {
		await innen.close()
	}

	// Und der Entzug wirkt: Was eben noch sichtbar war, ist es nicht mehr.
	const nachher = await browser.newContext({ storageState: KUNDE.sitzung })
	try {
		const seite = await nachher.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		// Auf das Board warten, nicht auf ein Ausbleiben: Ein Test, der nur
		// prueft, dass etwas *nicht* da ist, meldet auch bei leerer Seite gruen.
		//
		// Der Anker ist der Boardtitel und nicht ein Vorgang: Ein Vorgang waere
		// hier nur sichtbar, weil der Test darueber ihn freigegeben hat — diese
		// Zeile scheiterte dann, sobald jemand allein diesen Test laufen laesst.
		await expect(seite.getByText(projekt.titel).first()).toBeVisible({ timeout: 30_000 })
		await expect(seite.getByText(projekt.oeffentlich.title)).toHaveCount(0)
	} finally {
		await nachher.close()
	}
})
