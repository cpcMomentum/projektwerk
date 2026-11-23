import type { Browser, Page } from '@playwright/test'
import type { Projekt } from './projekt.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN } from './rollen.ts'

let projekt: Projekt

const WURZEL = `E2E-Standard-${Date.now().toString(36)}`

test.use({ storageState: INTERN.sitzung })

async function dav(browser: Browser, method: string, pfad: string, headers: Record<string, string> = {}): Promise<number> {
	const kontext = await browser.newContext({ storageState: INTERN.sitzung })
	try {
		const seite = await kontext.request.get(APP_PFAD)
		const token = (await seite.text()).match(/data-requesttoken="([^"]+)"/)?.[1]
		if (token === undefined) {
			throw new Error('Kein data-requesttoken in der App-Seite gefunden')
		}
		const url = `/remote.php/dav/files/${INTERN.uid}/${pfad.split('/').map(encodeURIComponent).join('/')}`
		const antwort = await kontext.request.fetch(url, { method, headers: { requesttoken: token, ...headers } })
		return antwort.status()
	} finally {
		await kontext.close()
	}
}

async function dateiablageOeffnen(page: Page): Promise<void> {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}/settings`)
	const neuigkeiten = page.getByRole('button', { name: 'Alles klar' })
	if (await neuigkeiten.waitFor({ timeout: 3_000 }).then(() => true, () => false)) {
		await neuigkeiten.click()
	}
	await page.getByRole('button', { name: 'Dateiablage' }).click()
	await expect(page.locator('#pw-set-root')).toBeVisible({ timeout: 30_000 })
}

function zeile(page: Page, slot: 'root' | 'public' | 'internal') {
	return page.locator('.pw-settings__row', { has: page.locator(`#pw-set-${slot}`) })
}

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())
	expect([201, 405]).toContain(await dav(browser, 'MKCOL', WURZEL))
	expect([201, 405]).toContain(await dav(browser, 'MKCOL', `${WURZEL}/91_Tickets_intern`))
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
	await dav(browser, 'DELETE', WURZEL)
})

test('ohne Projektordner gibt es keinen Knopf, sondern einen Hinweis', async ({ page }) => {
	await dateiablageOeffnen(page)

	await expect(page.getByRole('button', { name: 'Anlegen' })).toHaveCount(0)
	await expect(page.getByText(/zuerst den Projektordner/).first()).toBeVisible()
})

test('Platzhalter zeigt den echten Zielpfad, sobald der Projektordner gesetzt ist', async ({ page }) => {
	await dateiablageOeffnen(page)

	await page.locator('#pw-set-root').fill(WURZEL)
	await zeile(page, 'root').getByRole('button', { name: 'Übernehmen' }).click()
	await expect(page.locator('#pw-set-root')).toHaveValue(WURZEL, { timeout: 15_000 })

	await expect(page.locator('#pw-set-public')).toHaveAttribute('placeholder', `${WURZEL}/90_Austausch`)
	await expect(page.locator('#pw-set-internal')).toHaveAttribute('placeholder', `${WURZEL}/91_Tickets_intern`)
	await expect(page.getByText(/zuerst den Projektordner/)).toHaveCount(0)
})

test('übernimmt einen vorhandenen internen Ordner', async ({ page, browser }) => {
	await dateiablageOeffnen(page)

	await zeile(page, 'internal').getByRole('button', { name: 'Anlegen' }).click()

	await expect(page.locator('#pw-set-internal')).toHaveValue(`${WURZEL}/91_Tickets_intern`, { timeout: 15_000 })
	await expect(zeile(page, 'internal').getByRole('button', { name: 'Anlegen' })).toHaveCount(0)
	expect(await dav(browser, 'PROPFIND', `${WURZEL}/91_Tickets_intern (2)`, { Depth: '0' })).toBe(404)
})

test('legt den Austauschordner an und trägt ihn ein', async ({ page, browser }) => {
	await dateiablageOeffnen(page)
	expect(await dav(browser, 'PROPFIND', `${WURZEL}/90_Austausch`, { Depth: '0' })).toBe(404)

	await zeile(page, 'public').getByRole('button', { name: 'Anlegen' }).click()

	await expect(page.locator('#pw-set-public')).toHaveValue(`${WURZEL}/90_Austausch`, { timeout: 15_000 })
	expect(await dav(browser, 'PROPFIND', `${WURZEL}/90_Austausch`, { Depth: '0' })).toBe(207)

	await page.reload()
	await expect(page.locator('#pw-set-public')).toHaveValue(`${WURZEL}/90_Austausch`, { timeout: 30_000 })
})
