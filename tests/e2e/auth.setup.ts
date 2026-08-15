import { expect, test as setup } from '@playwright/test'
import { PASSWORT, ROLLEN } from './rollen.ts'

/**
 * Meldet beide Rollen an und legt die Sitzungen ab.
 *
 * Laeuft einmal vor allen Tests. Ohne das muesste sich jeder Test einzeln
 * anmelden — bei Nextcloud unter `php -S` sind das je Test mehrere Sekunden,
 * und die Anmeldung waere in jedem Fehlerbild mit drin, auch wenn sie nichts
 * damit zu tun hat.
 */

for (const rolle of ROLLEN) {
	setup(`anmelden als ${rolle.uid}`, async ({ page }) => {
		await page.goto('/index.php/login')

		// Das Anmeldeformular ist Vue-gerendert; die Felder tragen `name="user"`
		// und `name="password"`. Ueber den Namen statt ueber eine Beschriftung
		// zu gehen macht den Aufbau unabhaengig von der Sprache der Instanz —
		// die Anmeldung ist nicht das, was hier geprueft wird.
		await page.locator('input[name="user"]').fill(rolle.uid)
		await page.locator('input[name="password"]').fill(PASSWORT)
		await page.locator('form').filter({ has: page.locator('input[name="password"]') })
			.locator('button[type="submit"]').click()

		// Warten auf die angemeldete Sitzung, nicht auf eine bestimmte Seite:
		// Wohin Nextcloud nach der Anmeldung schickt, haengt an den
		// Standard-App-Einstellungen und geht uns nichts an.
		await expect(page).not.toHaveURL(/\/login/, { timeout: 30_000 })

		// Gegenprobe, dass wirklich die richtige Person angemeldet ist. Ohne sie
		// wuerde eine stillschweigend fehlgeschlagene Anmeldung erst viel spaeter
		// auffallen — als Test, der „nichts sieht" und deshalb gruen meldet.
		const angemeldet = await page.evaluate(() => {
			const kopf = document.querySelector('head')
			return kopf?.getAttribute('data-user') ?? ''
		})
		expect(angemeldet, `Angemeldet als "${angemeldet}", erwartet "${rolle.uid}"`).toBe(rolle.uid)

		await page.context().storageState({ path: rolle.sitzung })
	})
}
