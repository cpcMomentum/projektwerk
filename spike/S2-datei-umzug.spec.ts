import type { APIRequestContext, BrowserContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

/**
 * Spike S2 — Datei-Umzug im Team-Ordner. **Wegwerfcode.**
 *
 * Die Frage aus §11.3, wörtlich: Bleiben **Datei-ID, Versionen und
 * Freigabezustand** erhalten, wenn eine Datei zwischen zwei Unterordnern
 * **desselben** Team-Ordners verschoben wird?
 *
 * Warum das zählt: §5.18 verlangt, dass Anhänge beim Sichtbarkeitswechsel
 * mitwandern. Der MVP verweigert den Wechsel stattdessen (§3.10 Stufe 1), weil
 * diese Frage unbeantwortet ist — und weil ein halb gelungener Umzug ein Leck
 * wäre, das **physisch** ist und keine spätere Codekorrektur heilt. Fällt S2
 * negativ aus, bleibt der Riegel dauerhaft und Phase 7b entfällt.
 *
 * Gemessen wird an einem echten Team-Ordner (`occ groupfolders:create`), nicht
 * an zwei Heimatordnern: Nur dort stellt sich die Frage so.
 */

const INTERN = { uid: 'pw-e2e-intern', pass: process.env.PWERK_E2E_PASSWORT ?? 'e2e-Pw-2026-Test!' }
const GAST = 'pw-spike-gast'
const TEAM = 'Spike-Projekt'
const AUSTAUSCH = `${TEAM}/90_Austausch`
const INTERNORDNER = `${TEAM}/91_Tickets_intern`
const DATEI = `umzug-${Date.now().toString(36)}.txt`

const befunde: string[] = []

/**
 * @param zeile Ein Befund in einem Satz.
 */
function notieren(zeile: string): void {
	befunde.push(zeile)

	console.log('  BEFUND: ' + zeile)
}

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

/**
 * @param request Der Aufrufkontext.
 */
async function token(request: APIRequestContext): Promise<string> {
	const antwort = await request.get('/index.php/apps/files/')
	const treffer = (await antwort.text()).match(/data-requesttoken="([^"]+)"/)

	if (treffer === null) {
		throw new Error(`Kein requesttoken (HTTP ${antwort.status()})`)
	}

	return treffer[1]
}

test('S2 — Datei-ID, Versionen und Freigabe beim Umzug im Team-Ordner', async ({ browser }) => {
	const kontext = await browser.newContext()
	await anmelden(kontext, INTERN)
	const tk = await token(kontext.request)

	const dav = `/remote.php/dav/files/${INTERN.uid}`
	const kopf = { requesttoken: tk }

	/**
	 * @param methode WebDAV-Methode.
	 * @param pfad Pfad unterhalb des Dateibaums.
	 * @param zusatz Zusätzliche Kopfzeilen oder Rumpf.
	 */
	const webdav = (methode: string, pfad: string, zusatz: Record<string, unknown> = {}) => kontext.request.fetch(`${dav}/${pfad}`, { method: methode, headers: kopf, ...zusatz })

	// ---- Aufbau -------------------------------------------------------------
	for (const ordner of [AUSTAUSCH, INTERNORDNER]) {
		const angelegt = await webdav('MKCOL', ordner)
		if (!angelegt.ok() && angelegt.status() !== 405) {
			throw new Error(`MKCOL ${ordner}: ${angelegt.status()} ${await angelegt.text()}`)
		}
	}

	// Zwei Fassungen, damit es ueberhaupt eine Version zu verlieren gibt.
	await webdav('PUT', `${AUSTAUSCH}/${DATEI}`, { data: 'Fassung 1' })
	await new Promise((r) => setTimeout(r, 1200))
	await webdav('PUT', `${AUSTAUSCH}/${DATEI}`, { data: 'Fassung 2 — laenger als die erste' })

	/**
	 * Die Datei-ID über PROPFIND.
	 *
	 * @param pfad Pfad der Datei.
	 */
	const dateiId = async (pfad: string): Promise<string> => {
		const antwort = await kontext.request.fetch(`${dav}/${pfad}`, {
			method: 'PROPFIND',
			headers: { ...kopf, Depth: '0' },
			data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		return (await antwort.text()).match(/<oc:fileid>(\d+)<\/oc:fileid>/)?.[1] ?? `(keine — HTTP ${antwort.status()})`
	}

	/**
	 * Wie viele Versionen liegen zu dieser Datei-ID?
	 *
	 * @param id Datei-ID.
	 */
	const versionen = async (id: string): Promise<number> => {
		const antwort = await kontext.request.fetch(
			`/remote.php/dav/versions/${INTERN.uid}/versions/${id}`,
			{ method: 'PROPFIND', headers: { ...kopf, Depth: '1' } },
		)
		if (!antwort.ok() && antwort.status() !== 207) {
			return -1
		}
		// Die Sammlung selbst zaehlt als erster `<d:response>` mit.
		return Math.max(0, ((await antwort.text()).match(/<d:response>/g) ?? []).length - 1)
	}

	/**
	 * Freigaben zu einem Pfad.
	 *
	 * @param pfad Pfad der Datei.
	 */
	const freigaben = async (pfad: string): Promise<string> => {
		const antwort = await kontext.request.get(
			`/ocs/v2.php/apps/files_sharing/api/v1/shares?path=${encodeURIComponent('/' + pfad)}&format=json`,
			{ headers: { ...kopf, 'OCS-APIRequest': 'true' } },
		)
		const rumpf = await antwort.json().catch(() => null)
		const liste = rumpf?.ocs?.data ?? []
		return `${liste.length} (${liste.map((s: { share_with: string }) => s.share_with).join(', ') || '—'})`
	}

	const idVorher = await dateiId(`${AUSTAUSCH}/${DATEI}`)
	notieren(`Datei-ID vor dem Umzug: ${idVorher}`)
	notieren(`Versionen vor dem Umzug: ${await versionen(idVorher)}`)

	// Eine Einzelfreigabe an den Gast — der Freigabezustand, um den es geht.
	const geteilt = await kontext.request.post('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
		headers: { ...kopf, 'OCS-APIRequest': 'true' },
		form: { path: `/${AUSTAUSCH}/${DATEI}`, shareType: '0', shareWith: GAST, permissions: '17' },
	})
	notieren(`Einzelfreigabe an den Gast: HTTP ${geteilt.status()}`)
	notieren(`Freigaben vor dem Umzug: ${await freigaben(`${AUSTAUSCH}/${DATEI}`)}`)

	// ---- Der Umzug ----------------------------------------------------------
	const verschoben = await kontext.request.fetch(`${dav}/${AUSTAUSCH}/${DATEI}`, {
		method: 'MOVE',
		headers: { ...kopf, Destination: `${dav}/${INTERNORDNER}/${DATEI}` },
	})
	notieren(`MOVE zwischen den beiden Unterordnern: HTTP ${verschoben.status()}`)
	expect(verschoben.status(), 'Der Umzug selbst muss gelingen, sonst misst der Rest nichts').toBeLessThan(300)

	// ---- Danach -------------------------------------------------------------
	const idNachher = await dateiId(`${INTERNORDNER}/${DATEI}`)
	notieren(`Datei-ID nach dem Umzug: ${idNachher} — ${idNachher === idVorher ? 'GLEICH' : 'GEAENDERT'}`)
	notieren(`Versionen nach dem Umzug: ${await versionen(idNachher)}`)
	notieren(`Freigaben am neuen Ort: ${await freigaben(`${INTERNORDNER}/${DATEI}`)}`)
	notieren(`Freigaben am alten Ort: ${await freigaben(`${AUSTAUSCH}/${DATEI}`)}`)

	// Und die entscheidende Frage fuer die Sichtbarkeit: Kommt der Gast noch ran?
	const gast = await browser.newContext()
	await anmelden(gast, { uid: GAST, pass: 'Gast-Spike-2026!' })
	const gastZugriff = await gast.request.get(`/index.php/f/${idNachher}`, { maxRedirects: 0 })
	notieren(`Gast auf /f/${idNachher} NACH dem Umzug: HTTP ${gastZugriff.status()} -> ${gastZugriff.headers().location ?? '—'}`)

	// **Die entscheidende Trennung.** Erreicht der Gast die Datei, weil die
	// Einzelfreigabe mitgewandert ist — oder weil ihm der Team-Ordner ohnehin
	// beide Unterordner zeigt? Ohne diese Gegenprobe verwechselt man beides.
	const alleFreigaben = await kontext.request.get(
		`/ocs/v2.php/apps/files_sharing/api/v1/shares?path=${encodeURIComponent('/' + INTERNORDNER + '/' + DATEI)}&format=json`,
		{ headers: { ...kopf, 'OCS-APIRequest': 'true' } },
	)
	for (const s of (await alleFreigaben.json())?.ocs?.data ?? []) {
		await kontext.request.delete(`/ocs/v2.php/apps/files_sharing/api/v1/shares/${s.id}`, {
			headers: { ...kopf, 'OCS-APIRequest': 'true' },
		})
	}
	notieren(`Einzelfreigabe entfernt — Rest: ${await freigaben(`${INTERNORDNER}/${DATEI}`)}`)

	const ohneFreigabe = await gast.request.get(`/index.php/f/${idNachher}`, { maxRedirects: 0 })
	notieren(`Gast auf /f/${idNachher} OHNE Einzelfreigabe: HTTP ${ohneFreigabe.status()} -> ${ohneFreigabe.headers().location ?? '—'}`)

	// Und sieht er den internen Ordner ueberhaupt? Beide Unterordner haengen in
	// EINEM Team-Ordner mit EINER Gruppe — ohne erweiterte Rechte sieht er alles.
	const gastDav = await gast.request.fetch(`/remote.php/dav/files/${GAST}/${INTERNORDNER}`, {
		method: 'PROPFIND',
		headers: { Depth: '0', requesttoken: await token(gast.request) },
	})
	notieren(`Gast sieht ${INTERNORDNER} per WebDAV: HTTP ${gastDav.status()}`)

	await gast.close()

	await kontext.close()

	console.log('\n===== S2-Befunde =====\n' + befunde.map((b) => '- ' + b).join('\n') + '\n')
})
