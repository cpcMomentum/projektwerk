import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen, stufeWaehlen } from './projekt.ts'
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

		// **Ein Klick ist die ganze Handlung** (#75) — kein „Ändern" davor, kein
		// „Übernehmen" danach. Die Auswahl steht offen im Abschnitt.
		//
		// `stufeWaehlen` grenzt trotzdem auf `.pw-vischoice` ein: Hinter dem
		// Overlay liegt das Board, und dessen Karten tragen dieselben
		// Stufennamen als Marke.
		await stufeWaehlen(seite, 'Alle Beteiligten')

		// **Kein Zwischenschritt mehr** (#103). Bis dahin stand hier eine
		// Verzweigung: Rueckfrage abwarten, falls sie kommt, sonst weiter. Sie
		// kommt nicht mehr — weder beim Hochstufen noch beim Herunterstufen.
		//
		// Die Markierung wandert erst, wenn der Wechsel wirklich gilt; sie haengt
		// am Ticket und nicht am Klick. Diese Zeile ist damit zugleich die Probe
		// darauf, dass gespeichert wurde.
		await expect(seite.locator('.pw-viscontrol input[type="radio"]:checked')).toHaveValue('public')
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

test('das Herunterstufen entzieht den Zugriff — ohne Rueckfrage', async ({ browser }) => {
	// Die andere Richtung, und seit #103 mit **derselben** Handlung: ein Klick.
	// Bis dahin stand hier eine Rueckfrage mit Namen; sie ist abgeschafft (Axel,
	// 2026-08-13), weil die Beschriftung dieselbe Auskunft schon gibt.
	//
	// Der Test bleibt trotzdem stehen, und zwar wegen seines zweiten Teils: Dass
	// der Zugriff wirklich weg ist, prueft niemand sonst von aussen.
	const innen = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const seite = await innen.newPage()
		await seite.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await seite.getByText(projekt.oeffentlich.title).click()

		// Ohne die Eingrenzung haengt diese Zeile an der Reihenfolge: Karten mit
		// interner Sichtbarkeit tragen die Marke „Intern", und solange eine auf
		// dem Board liegt, treffen zwei Elemente. Gruen ist sie heute nur, weil
		// der Test darueber den einzigen internen Vorgang vorher hochstuft.
		await stufeWaehlen(seite, 'Intern')

		// **Kein Warnblock, kein zweiter Knopf.** Die Zeile ist die Gegenprobe
		// zur Abschaffung: Kaeme die Rueckfrage zurueck, faellt sie hier.
		await expect(seite.locator('.pw-viscontrol__warn')).toHaveCount(0)
		await expect(seite.getByRole('button', { name: 'Sichtbarkeit ändern' })).toHaveCount(0)

		// Die Markierung wandert, sobald der Wechsel gilt — zugleich die Probe
		// darauf, dass gespeichert wurde.
		await expect(seite.locator('.pw-viscontrol input[type="radio"]:checked')).toHaveValue('internal')

		// **Kein „Rückgängig" mehr** (#181). Der Widerruf ist entfernt: Der
		// Umschalter steht ohnehin offen und ist der Ein-Klick-Rückweg (vorige
		// Stufe erneut anklicken). Ein eigener Knopf war redundant und liess die
		// Kopfzeile springen. Nach dem Wechsel darf er deshalb nicht erscheinen.
		await expect(seite.getByRole('button', { name: 'Rückgängig' })).toHaveCount(0)
		await expect(seite.locator('.pw-viscontrol--offen')).toHaveCount(0)
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
