import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

/** Einstellungen mit den Gruppen „Projekt“ und „Board“, Projektname und beide Archive. */

let projekt: { boardId: number, titel: string }

async function alsIntern<T>(browser: Browser, arbeit: (api: Api) => Promise<T>): Promise<T> {
	const kontext = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		return await arbeit(await Api.fuer(kontext.request))
	} finally {
		await kontext.close()
	}
}

async function einstellungen(page: Page, boardId: number, bereich?: string): Promise<void> {
	await page.goto(`${APP_PFAD}#/boards/${boardId}/settings${bereich ? `?bereich=${bereich}` : ''}`)
	await expect(page.getByRole('heading', { name: 'Einstellungen', exact: true })).toBeVisible({ timeout: 30_000 })
}

const gruppe = (page: Page, name: string) => page.getByRole('navigation').getByRole('group', { name, exact: true })

// Ohne Vorgänge: Die Einstellungen brauchen keine, und jeder angelegte Vorgang zählt gegen die Drossel.
test.beforeEach(async ({ browser }) => {
	projekt = await alsIntern(browser, async (api) => {
		const titel = `E2E-Einstellungen ${Date.now().toString(36)}`
		const board = await api.boardAnlegen(titel, 'E2E Dienstleister', 'E2E Kunde')
		await api.mitgliedHinzufuegen(Number(board.id), KUNDE.uid, 'external')
		return { boardId: Number(board.id), titel }
	})
})

test.afterEach(async ({ browser }) => {
	if (projekt !== undefined) {
		await alsIntern(browser, (api) => api.projektArchivieren(projekt.boardId))
	}
})

test.describe('Dienstleisterseite (Verwalter)', () => {
	test.use({ storageState: INTERN.sitzung })

	test('ein Board: zwei Gruppen, ohne Auswahl, Boardname und Board-Archiv', async ({ page }) => {
		await einstellungen(page, projekt.boardId)

		const projektGruppe = gruppe(page, 'Projekt')
		for (const name of ['Allgemein', 'Mitglieder', 'Dateiablage', 'Archiv']) {
			await expect(projektGruppe.getByRole('button', { name, exact: true })).toBeVisible()
		}
		const boardGruppe = gruppe(page, 'Board')
		await expect(boardGruppe.getByRole('button')).toHaveText(['Spalten', 'GitHub'])
		await expect(page.getByRole('combobox', { name: 'Board wählen' })).toHaveCount(0)
	})

	test('der Projektname lässt sich ändern und steht im Board-Kopf', async ({ page }) => {
		const neu = `${projekt.titel} umbenannt`
		await einstellungen(page, projekt.boardId)

		await gruppe(page, 'Projekt').getByRole('button', { name: 'Allgemein' }).click()
		await page.getByLabel('Projektname').fill(neu)
		await page.getByRole('button', { name: 'Speichern' }).first().click()
		await expect(page.getByText(`Projekt „${neu}“`)).toBeVisible()

		await page.getByRole('link', { name: `Board „${neu}“` }).click()
		await expect(page.getByRole('heading', { name: neu, exact: true })).toBeVisible()
	})

	test('das Zahnrad führt aus dem Board zu den Spalten und aus der Übersicht zum Projekt', async ({ page }) => {
		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await page.getByRole('button', { name: 'Einstellungen', exact: true }).click()
		await expect(page.getByRole('heading', { name: 'Spalten', exact: true })).toBeVisible()

		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/overview`)
		await page.getByRole('button', { name: 'Einstellungen', exact: true }).click()
		await expect(page.getByLabel('Projektname')).toBeVisible()
	})

	test('ein Board archivieren wirkt nur auf das Board und lässt sich über die Auswahl zurückholen', async ({ page, browser }) => {
		const zweites = await alsIntern(browser, (api) => api.weiteresBoardAnlegen(projekt.boardId, 'Arbeitsgruppe'))
		await einstellungen(page, Number(zweites.id), 'board-archiv')

		await expect(page.getByRole('combobox', { name: 'Board wählen' })).toBeVisible()
		await page.getByRole('main').getByRole('button', { name: 'Board archivieren' }).click()
		const dialog = page.getByRole('dialog', { name: 'Board archivieren' })
		await expect(dialog).toContainText('Nur das Board „Arbeitsgruppe“ wird archiviert.')
		await dialog.getByRole('button', { name: 'Archivieren' }).click()

		await expect(page.getByText('Dieses Board ist archiviert.')).toBeVisible()

		await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
		await expect(page.getByRole('heading', { name: projekt.titel, exact: true })).toBeVisible()
		await expect(page.getByText('Arbeitsgruppe', { exact: true })).toHaveCount(0)

		await einstellungen(page, projekt.boardId, 'board-archiv')
		await page.getByRole('combobox', { name: 'Board wählen' }).click()
		// NcSelect teilt lange Optionen zum Kürzen auf; der Textinhalt bleibt zusammenhängend.
		await page.getByRole('option').filter({ hasText: 'Arbeitsgruppe (archiviert)' }).click()
		await expect(page).toHaveURL(new RegExp(`/boards/${zweites.id}/settings`))
		await page.getByRole('button', { name: 'Board aus dem Archiv holen' }).click()
		await expect(page.getByText('Dieses Board ist archiviert.')).toHaveCount(0)
		await page.getByRole('combobox', { name: 'Board wählen' }).click()
		await expect(page.getByRole('option').filter({ hasText: 'Arbeitsgruppe' })).not.toContainText('archiviert')
	})

	test('ein Projekt archivieren blendet alle Boards aus, Zurückholen lässt einzeln archivierte archiviert', async ({ page, browser }) => {
		const zweites = await alsIntern(browser, async (api) => {
			const board = await api.weiteresBoardAnlegen(projekt.boardId, 'Altes Board')
			await api.boardArchivieren(Number(board.id))
			return board
		})

		await einstellungen(page, projekt.boardId, 'projekt-archiv')
		await page.getByRole('main').getByRole('button', { name: 'Projekt archivieren' }).click()
		const dialog = page.getByRole('dialog', { name: 'Projekt archivieren' })
		await expect(dialog).toContainText(`Das Projekt „${projekt.titel}“ wird mit allen Boards archiviert.`)
		await dialog.getByRole('button', { name: 'Archivieren' }).click()
		await expect(page.getByText('Dieses Projekt ist archiviert.')).toBeVisible()

		const liste = (await alsIntern(browser, (api) => api.lesen('/api/v1/boards'))).map((b: any) => Number(b.id))
		expect(liste).not.toContain(projekt.boardId)

		await page.getByRole('button', { name: 'Projekt aus dem Archiv holen' }).click()
		await expect(page.getByText('Dieses Projekt ist archiviert.')).toHaveCount(0)

		const danach = (await alsIntern(browser, (api) => api.lesen('/api/v1/boards'))).map((b: any) => Number(b.id))
		expect(danach).toContain(projekt.boardId)
		expect(danach).not.toContain(Number(zweites.id))
	})
})

test.describe('Board-Ersteller ohne Verwaltungsrecht', () => {
	test.use({ storageState: KUNDE.sitzung })

	test('sieht nur die Gruppe „Board“', async ({ page, browser }) => {
		await alsIntern(browser, (api) => api.boardAendern(projekt.boardId, { memberBoardsAllowed: true }))
		const kontext = await browser.newContext({ storageState: KUNDE.sitzung })
		const eigenes = await (async () => {
			try {
				return await (await Api.fuer(kontext.request)).weiteresBoardAnlegen(projekt.boardId, 'Kunden-AG')
			} finally {
				await kontext.close()
			}
		})()

		await einstellungen(page, Number(eigenes.id))

		await expect(gruppe(page, 'Projekt')).toHaveCount(0)
		await expect(gruppe(page, 'Board').getByRole('button')).toHaveText(['Allgemein', 'Spalten', 'Archiv'])
		await expect(page.getByRole('combobox', { name: 'Board wählen' })).toHaveCount(0)
		await expect(page.getByLabel('Boardname')).toHaveValue('Kunden-AG')
	})
})
