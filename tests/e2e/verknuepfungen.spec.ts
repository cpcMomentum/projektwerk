import type { APIRequestContext, Browser, Page } from '@playwright/test'
import type { Projekt } from './projekt.ts'
import type { Rolle } from './rollen.ts'

import { expect, test } from '@playwright/test'
import { marke, projektAufbauen, projektAufraeumen } from './projekt.ts'
import { APP_PFAD, INTERN, KUNDE } from './rollen.ts'

let projekt: Projekt

const ORDNER = `E2E-Link-${Date.now().toString(36)}`

async function alsPerson<T>(browser: Browser, rolle: Rolle, run: (request: APIRequestContext, token: string) => Promise<T>): Promise<T> {
	const kontext = await browser.newContext({ storageState: rolle.sitzung })
	try {
		const seite = await kontext.request.get(APP_PFAD)
		const token = (await seite.text()).match(/data-requesttoken="([^"]+)"/)?.[1]
		if (token === undefined) {
			throw new Error('Kein data-requesttoken in der App-Seite gefunden')
		}
		return await run(kontext.request, token)
	} finally {
		await kontext.close()
	}
}

async function detailOeffnen(page: Page, titel: string): Promise<void> {
	await page.goto(`${APP_PFAD}#/boards/${projekt.boardId}`)
	const neuigkeiten = page.getByRole('button', { name: 'Alles klar' })
	if (await neuigkeiten.waitFor({ timeout: 3_000 }).then(() => true, () => false)) {
		await neuigkeiten.click()
	}
	const verknuepfen = page.getByRole('button', { name: 'Verknüpfen' })
	if (!(await verknuepfen.waitFor({ timeout: 3_000 }).then(() => true, () => false))) {
		await page.getByText(titel, { exact: true }).first().click()
	}
	await expect(verknuepfen).toBeVisible({ timeout: 30_000 })
}

test.beforeAll(async ({ browser }) => {
	projekt = await projektAufbauen(browser, marke())
	await alsPerson(browser, INTERN, async (request, token) => {
		for (const pfad of [ORDNER, `${ORDNER}/Schulungen`]) {
			const antwort = await request.fetch(`/remote.php/dav/files/${INTERN.uid}/${pfad}`, { method: 'MKCOL', headers: { requesttoken: token } })
			expect([201, 405]).toContain(antwort.status())
		}
		const datei = await request.fetch(`/remote.php/dav/files/${INTERN.uid}/${ORDNER}/Konzept.md`, { method: 'PUT', headers: { requesttoken: token }, data: 'v1' })
		expect([201, 204]).toContain(datei.status())
	})
})

test.afterAll(async ({ browser }) => {
	if (projekt !== undefined) {
		await projektAufraeumen(browser, projekt.boardId)
	}
	await alsPerson(browser, INTERN, (request, token) => request.fetch(`/remote.php/dav/files/${INTERN.uid}/${ORDNER}`, { method: 'DELETE', headers: { requesttoken: token } }))
})

test.describe('Dienstleisterseite', () => {
	test.use({ storageState: INTERN.sitzung })

	test('verknüpft einen Ordner, der Link öffnet ihn in Dateien', async ({ page }) => {
		await detailOeffnen(page, projekt.oeffentlich.title)

		await page.getByRole('button', { name: 'Verknüpfen' }).click()
		const dialog = page.getByRole('dialog', { name: 'Datei oder Ordner wählen' })
		await dialog.getByRole('button', { name: ORDNER }).click()
		await dialog.getByRole('button', { name: 'Schulungen' }).click()
		await page.getByRole('button', { name: 'Diesen Ordner wählen' }).click()

		const link = page.locator('.pw-attach--link').getByRole('link', { name: 'Schulungen' })
		await expect(link).toBeVisible({ timeout: 15_000 })
		await expect(link).toHaveAttribute('href', /\/f\/\d+$/)
		await expect(page.locator('.pw-attach--link')).toContainText(`liegt in ${ORDNER}`)
	})

	test('verknüpft eine Datei, sie bleibt an ihrer Stelle', async ({ page, browser }) => {
		await detailOeffnen(page, projekt.oeffentlich.title)

		await page.getByRole('button', { name: 'Verknüpfen' }).click()
		const dialog = page.getByRole('dialog', { name: 'Datei oder Ordner wählen' })
		await dialog.getByRole('button', { name: ORDNER }).click()
		await dialog.getByRole('button', { name: 'Konzept.md' }).click()

		await expect(page.locator('.pw-attach--link').getByRole('link', { name: 'Konzept.md' })).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.pw-attach').filter({ hasNotText: 'Verknüpfung' })).toHaveCount(0)
		const status = await alsPerson(browser, INTERN, async (request, token) => (await request.fetch(`/remote.php/dav/files/${INTERN.uid}/${ORDNER}/Konzept.md`, { method: 'PROPFIND', headers: { requesttoken: token, Depth: '0' } })).status())
		expect(status).toBe(207)
	})
})

test.describe('Kundenseite', () => {
	test.use({ storageState: KUNDE.sitzung })

	test('sieht ohne Freigabe keine Verknüpfung, mit Freigabe schon', async ({ page, browser }) => {
		await detailOeffnen(page, projekt.oeffentlich.title)
		await expect(page.locator('.pw-attach--link')).toHaveCount(0)

		await alsPerson(browser, INTERN, async (request, token) => {
			const antwort = await request.post('/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', {
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
				form: { path: `/${ORDNER}/Schulungen`, shareType: '0', shareWith: KUNDE.uid, permissions: '1' },
			})
			expect(antwort.ok(), await antwort.text()).toBeTruthy()
		})

		await page.reload()
		await detailOeffnen(page, projekt.oeffentlich.title)
		await expect(page.locator('.pw-attach--link').getByRole('link', { name: 'Schulungen' })).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.pw-attach--link').getByText('Konzept.md')).toHaveCount(0)
	})
})
