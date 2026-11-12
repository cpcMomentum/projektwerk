import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { Api } from './api.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

/** Eine Kachel je Projekt, zuletzt genutztes Board, archivierte Projekte. */

let projekt: { titel: string, ersterId: number, zweiterId: number }

async function alsIntern<T>(browser: Browser, arbeit: (api: Api) => Promise<T>): Promise<T> {
	const kontext = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		return await arbeit(await Api.fuer(kontext.request))
	} finally {
		await kontext.close()
	}
}

const kachel = (page: Page, titel: string) => page.locator('.pw-tile', { has: page.locator('.pw-tile__title', { hasText: titel }) })

test.use({ storageState: INTERN.sitzung })

test.beforeEach(async ({ browser }) => {
	projekt = await alsIntern(browser, async (api) => {
		const titel = `E2E-Projektliste ${Date.now().toString(36)}`
		const erstes = await api.boardAnlegen(titel, 'E2E Dienstleister', 'E2E Kunde')
		const zweites = await api.weiteresBoardAnlegen(Number(erstes.id), 'Zweites Board')
		return { titel, ersterId: Number(erstes.id), zweiterId: Number(zweites.id) }
	})
})

test.afterEach(async ({ browser }) => {
	if (projekt !== undefined) {
		await alsIntern(browser, (api) => api.projektArchivieren(projekt.ersterId))
	}
})

test('ein Projekt mit zwei Boards erscheint genau einmal und öffnet das zuletzt genutzte Board', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/boards`)
	await expect(kachel(page, projekt.titel)).toHaveCount(1, { timeout: 30_000 })
	await expect(kachel(page, 'Zweites Board')).toHaveCount(0)

	await page.goto(`${APP_PFAD}#/boards/${projekt.zweiterId}`)
	await expect(page.getByRole('heading', { name: projekt.titel, exact: true })).toBeVisible()

	await page.goto(`${APP_PFAD}#/boards`)
	await kachel(page, projekt.titel).click()
	await expect(page).toHaveURL(new RegExp(`#/boards/${projekt.zweiterId}$`))
})

test('ein archiviertes Projekt erscheint erst mit dem Schalter, markiert, und behält seine Board-Reiter', async ({ page, browser }) => {
	await alsIntern(browser, (api) => api.projektArchivieren(projekt.ersterId))

	await page.goto(`${APP_PFAD}#/boards`)
	await expect(page.getByRole('heading', { name: 'Projekte', exact: true })).toBeVisible({ timeout: 30_000 })
	await expect(kachel(page, projekt.titel)).toHaveCount(0)

	await page.getByText('Archivierte zeigen').click()
	const archiviert = kachel(page, projekt.titel)
	await expect(archiviert).toContainText('Archiviert')

	await archiviert.click()
	await expect(page.getByRole('heading', { name: projekt.titel, exact: true })).toBeVisible()
	await expect(page.locator('.pw-boardtabs').getByText('Zweites Board', { exact: true })).toBeVisible()
})
