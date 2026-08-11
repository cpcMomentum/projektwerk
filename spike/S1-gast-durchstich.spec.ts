import type { APIRequestContext, BrowserContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

/**
 * Spike S1 — Gast-Durchstich. **Wegwerfcode, nicht nach `lib/` übernehmen.**
 *
 * §7 der Produktbeschreibung sagt es selbst: „Die Gast-Logik ist damit testbar,
 * aber bis heute ungetestet." Sechs Punkte aus §11.2 sind Umgebungsannahmen,
 * keine Designfragen — und ein Test mit einem normalen Konto beweist keinen
 * davon, weil die Gast-Beschränkungen erst in einer echten Gast-Sitzung
 * greifen.
 *
 * Der Spike **behauptet nichts**: Er schreibt auf, was er misst, und lässt
 * jeden Punkt einzeln bestehen oder scheitern. Ein Fehlschlag hier ist das
 * Ergebnis, nicht ein Fehler im Spike.
 */

const APP = '/index.php/apps/projektwerk/'
const INTERN = { uid: 'pw-e2e-intern', pass: process.env.PWERK_E2E_PASSWORT ?? 'e2e-Pw-2026-Test!' }
const GAST = { uid: 'pw-spike-gast', pass: 'Gast-Spike-2026!' }
const ORDNER = 'Spike-Austausch'

/** Was der Spike herausfindet — am Ende in einem Block ausgegeben. */
const befunde: string[] = []

/**
 * @param zeile Ein Befund in einem Satz.
 */
function notieren(zeile: string): void {
	befunde.push(zeile)
	// eslint-disable-next-line no-console
	console.log('  BEFUND: ' + zeile)
}

/**
 * Anmelden über das Formular und den Kontext behalten.
 *
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

/**
 * Das `requesttoken` aus einer ausgelieferten Seite.
 *
 * @param request Der Aufrufkontext.
 * @param pfad Eine Seite, die das Token trägt.
 */
async function token(request: APIRequestContext, pfad: string): Promise<string> {
	const antwort = await request.get(pfad)
	const treffer = (await antwort.text()).match(/data-requesttoken="([^"]+)"/)

	if (treffer === null) {
		throw new Error(`Kein requesttoken auf ${pfad} (HTTP ${antwort.status()})`)
	}

	return treffer[1]
}

test('S1 — was ein echtes Gastkonto kann und was nicht', async ({ browser }) => {
	// ---- Aufbau als Dienstleisterseite -------------------------------------
	const intern = await browser.newContext()
	await anmelden(intern, INTERN)

	const tokenIntern = await token(intern.request, APP)
	const kopf = { requesttoken: tokenIntern, 'Content-Type': 'application/json' }

	/**
	 * @param methode HTTP-Methode.
	 * @param pfad Pfad unterhalb der App-API.
	 * @param daten Rumpf.
	 */
	const api = async (methode: 'get' | 'post' | 'patch', pfad: string, daten?: object) => {
		const antwort = await intern.request[methode](`/index.php/apps/projektwerk${pfad}`, {
			headers: kopf,
			...(daten === undefined ? {} : { data: daten }),
		})
		if (!antwort.ok()) {
			throw new Error(`${methode.toUpperCase()} ${pfad} -> ${antwort.status()}: ${await antwort.text()}`)
		}
		return antwort.json()
	}

	const board = await api('post', '/api/v1/boards', {
		title: `Spike S1 ${Date.now().toString(36)}`,
		orgInternal: 'Dienstleister',
		orgExternal: 'Kunde',
	})
	const boardId = Number(board.id)

	await api('post', `/api/v1/boards/${boardId}/members`, { userId: GAST.uid, role: 'external' })
	await api('patch', `/api/v1/boards/${boardId}`, { folderPublicPath: ORDNER })

	const ansicht = await api('get', `/api/v1/boards/${boardId}`)
	const spalte = Number(ansicht.columns[0].id)

	const ticket = await api('post', `/api/v1/boards/${boardId}/tickets`, {
		title: 'Vorgang mit Anhang für den Gast',
		columnId: spalte,
		visibility: 'public',
	})
	const ticketId = Number(ticket.id)

	// Den Austauschordner mit dem Gast teilen — **Nextclouds Freigabe**, nicht
	// eine von der App angelegte (§5.18).
	const geteilt = await intern.request.post('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
		headers: { requesttoken: tokenIntern, 'OCS-APIRequest': 'true' },
		form: { path: `/${ORDNER}`, shareType: '0', shareWith: GAST.uid, permissions: '31' },
	})
	notieren(`Ordnerfreigabe an den Gast: HTTP ${geteilt.status()}`)

	// Eine Datei anhaengen — als Dienstleister, ueber unsere eigene Route.
	const hochgeladen = await intern.request.post(
		`/index.php/apps/projektwerk/api/v1/boards/${boardId}/tickets/${ticketId}/attachments`,
		{
			headers: { requesttoken: tokenIntern },
			multipart: {
				file: { name: 'spike.txt', mimeType: 'text/plain', buffer: Buffer.from('Inhalt für S1') },
			},
		},
	)
	expect(hochgeladen.ok(), `Anhang anlegen: ${hochgeladen.status()} ${await hochgeladen.text()}`).toBe(true)
	const anhang = await hochgeladen.json()
	notieren(`Anhang liegt als Datei ${anhang.fileId} unter ${anhang.filePath}`)

	await intern.close()

	// ---- Jetzt als echter Gast ---------------------------------------------
	const gast = await browser.newContext()
	await anmelden(gast, GAST)

	// (1) Freigabeliste: kommt der Gast ueberhaupt in die App?
	const appSeite = await gast.request.get(APP)
	notieren(`App-Seite in der Gast-Sitzung: HTTP ${appSeite.status()}`)
	const gastHtml = await appSeite.text()
	notieren(`Angemeldet als: ${gastHtml.match(/data-user="([^"]*)"/)?.[1] ?? '(niemand)'}`)

	// (2) Ein `#[NoAdminRequired]`-JSON-Endpunkt in einer echten Gast-Sitzung.
	const tokenGast = await token(gast.request, APP)
	const gelesen = await gast.request.get(`/index.php/apps/projektwerk/api/v1/boards/${boardId}`, {
		headers: { requesttoken: tokenGast, 'Content-Type': 'application/json' },
	})
	notieren(`board#show als Gast: HTTP ${gelesen.status()}`)
	if (gelesen.ok()) {
		const sicht = await gelesen.json()
		notieren(`Der Gast sieht ${sicht.columns?.length ?? 0} Spalten und ${sicht.members?.length ?? 0} Mitglieder`)
	} else {
		notieren(`Rumpf: ${(await gelesen.text()).slice(0, 200)}`)
	}

	// (3) Die Datei selbst — erreicht der Gast sie ueber die Datei-ID?
	const datei = await gast.request.get(`/index.php/f/${anhang.fileId}`, { maxRedirects: 0 })
	notieren(`/f/{fileId} als Gast: HTTP ${datei.status()} -> ${datei.headers().location ?? '(keine Weiterleitung)'}`)

	// Und der ganze Weg mit Browser: Landet der Gast wirklich bei seiner Datei?
	const dateiSeite = await gast.newPage()
	await dateiSeite.goto(`/index.php/f/${anhang.fileId}`)
	notieren(`Im Browser landet der Gast auf: ${new URL(dateiSeite.url()).pathname}${new URL(dateiSeite.url()).search}`)
	// Warten statt sofort messen: Die Dateiliste baut sich per JavaScript auf.
	try {
		await dateiSeite.getByText(anhang.fileName).first().waitFor({ timeout: 20_000 })
		notieren(`Dateiname „${anhang.fileName}" erscheint beim Gast: ja`)
	} catch {
		notieren(`Dateiname „${anhang.fileName}" erscheint beim Gast: NEIN`)
		notieren(`Was der Gast stattdessen sieht: ${(await dateiSeite.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 240)}`)
	}
	await dateiSeite.screenshot({ path: 'spike/S1-gast-datei.png', fullPage: true })
	await dateiSeite.close()

	// (3b) **Der entscheidende Fall fuer die Kundenseite: selbst anhaengen.**
	// Ein Gastkonto bekommt auf dieser Instanz `quota: 0 B` — die Frage ist, was
	// daraus in unserer App wird.
	const gastHaengtAn = await gast.request.post(
		`/index.php/apps/projektwerk/api/v1/boards/${boardId}/tickets/${ticketId}/attachments`,
		{
			headers: { requesttoken: tokenGast },
			multipart: {
				file: { name: 'vom-kunden.txt', mimeType: 'text/plain', buffer: Buffer.from('Antwort des Kunden') },
			},
		},
	)
	notieren(`Gast haengt selbst an: HTTP ${gastHaengtAn.status()} ${(await gastHaengtAn.text()).slice(0, 200)}`)

	// (4) Fragmentfreier Deep-Link **aus abgemeldetem Zustand**.
	const abgemeldet = await browser.newContext()
	const anonym = await abgemeldet.newPage()
	await anonym.goto(`/index.php/apps/projektwerk/t/${ticketId}`)
	notieren(`Deep-Link abgemeldet landet auf: ${new URL(anonym.url()).pathname}`)
	notieren(`Enthaelt die Rueckkehr-Adresse ein @? ${new URL(anonym.url()).search.includes('%40') || new URL(anonym.url()).search.includes('@') ? 'JA — Nextcloud verwirft solche Ziele' : 'nein'}`)

	await anonym.close()
	await abgemeldet.close()

	// (5) Auffindbarkeit von Gast-Konten beim Hinzufuegen neuer Mitglieder.
	const intern2 = await browser.newContext()
	await anmelden(intern2, INTERN)
	const tokenIntern2 = await token(intern2.request, APP)
	const suche = await intern2.request.get(
		`/index.php/apps/projektwerk/api/v1/boards/${boardId}/member-search?search=Spike`,
		{ headers: { requesttoken: tokenIntern2, 'Content-Type': 'application/json' } },
	)
	notieren(`Mitgliedersuche nach „Spike" (schon Mitglied): HTTP ${suche.status()} ${JSON.stringify(await suche.json()).slice(0, 160)}`)

	// Gegenprobe mit einem Gast, der NICHT Mitglied dieses Boards ist — sonst
	// misst man nur den Ausschluss vorhandener Mitglieder.
	// **Gegenprobe an einem ZWEITEN Board**, in dem der Spike-Gast kein Mitglied
	// ist. Sonst misst man nur den Ausschluss vorhandener Mitglieder und haelt
	// ihn faelschlich fuer „Gaeste sind nicht auffindbar".
	const zweites = await intern2.request.post('/index.php/apps/projektwerk/api/v1/boards', {
		headers: { requesttoken: tokenIntern2, 'Content-Type': 'application/json' },
		data: { title: `Spike S1 Gegenprobe ${Date.now().toString(36)}` },
	})
	const zweitesId = Number((await zweites.json()).id)

	for (const frage of ['pw-e2e-kunde', 'Spike', 'pw-spike-gast', 'Gastkonto']) {
		for (const [wo, id] of [['mit Mitgliedschaft', boardId], ['ohne Mitgliedschaft', zweitesId]] as const) {
			const antwort = await intern2.request.get(
				`/index.php/apps/projektwerk/api/v1/boards/${id}/member-search?search=${encodeURIComponent(frage)}`,
				{ headers: { requesttoken: tokenIntern2, 'Content-Type': 'application/json' } },
			)
			notieren(`Suche „${frage}" (${wo}): ${JSON.stringify(await antwort.json()).slice(0, 160)}`)
		}
	}
	await intern2.close()
	await gast.close()

	// eslint-disable-next-line no-console
	console.log('\n===== S1-Befunde =====\n' + befunde.map((b) => '- ' + b).join('\n') + '\n')
})
