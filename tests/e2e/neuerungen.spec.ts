import { expect, test } from '@playwright/test'
import { APP_PFAD, INTERN } from './rollen.ts'

test.use({ storageState: INTERN.sitzung })

test('„Neuerungen“ öffnet das Archiv und bleibt auf der aktuellen Seite', async ({ page }) => {
	await page.goto(`${APP_PFAD}#/tasks`)
	const neuigkeiten = page.getByRole('button', { name: 'Alles klar' })
	if (await neuigkeiten.waitFor({ timeout: 3_000 }).then(() => true, () => false)) {
		await neuigkeiten.click()
	}

	await page.getByRole('link', { name: 'Neuerungen' }).click()

	await expect(page.getByRole('dialog', { name: /Was ist neu in ProjektWerk/ })).toBeVisible()
	await page.getByRole('button', { name: /Schließen/ }).last().click()
	await expect(page).toHaveURL(/#\/tasks$/)
})
