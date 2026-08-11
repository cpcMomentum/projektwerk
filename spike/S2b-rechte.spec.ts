import type { BrowserContext } from '@playwright/test'

import { test } from '@playwright/test'

/**
 * S2b — trennt der Team-Ordner die beiden Unterordner ueberhaupt? **Wegwerfcode.**
 *
 * S2 hat gezeigt: Ein Umzug erhaelt Datei-ID, Versionen und Freigabe. Die
 * Gegenprobe hat aber etwas Wichtigeres gezeigt — der Gast erreichte die Datei
 * im INTERNEN Ordner auch ohne jede Freigabe, weil ein Team-Ordner mit EINER
 * Gruppe allen Mitgliedern alles darin zeigt.
 *
 * Hier wird geprueft, ob die erweiterten Rechte (`groupfolders:permissions`) das
 * sauber trennen. Falls ja, gehoert die Einrichtung in die Betriebsanleitung —
 * und zwar als Bedingung, nicht als Empfehlung.
 */

const GAST = { uid: 'pw-spike-gast', pass: 'Gast-Spike-2026!' }
const INTERNORDNER = 'Spike-Projekt/91_Tickets_intern'
const AUSTAUSCH = 'Spike-Projekt/90_Austausch'

/**
 * @param kontext Ein frischer Browserkontext.
 * @param konto Zugangsdaten.
 */
async function anmelden(kontext: BrowserContext, konto: { uid: string, pass: string }): Promise<void> {
	const seite = await kontext.newPage()
	await seite.goto('/index.php/login')
	await seite.locator('#user').fill(konto.uid)
	await seite.locator('#password').fill(konto.pass)
	await seite.getByRole('button', { name: /Anmelden|Log in/ }).click()
	await seite.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await seite.close()
}

test('S2b — sperren erweiterte Rechte den internen Ordner fuer den Gast?', async ({ browser }) => {
	const gast = await browser.newContext()
	await anmelden(gast, GAST)

	const seite = await gast.request.get('/index.php/apps/files/')
	const tk = (await seite.text()).match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''

	for (const ordner of [AUSTAUSCH, INTERNORDNER]) {
		const antwort = await gast.request.fetch(`/remote.php/dav/files/${GAST.uid}/${ordner}`, {
			method: 'PROPFIND',
			headers: { Depth: '1', requesttoken: tk },
		})
		const eintraege = Math.max(0, ((await antwort.text()).match(/<d:response>/g) ?? []).length - 1)
		// eslint-disable-next-line no-console
		console.log(`  BEFUND: Gast auf ${ordner}: HTTP ${antwort.status()}, ${eintraege} Eintraege`)
	}

	await gast.close()
})
