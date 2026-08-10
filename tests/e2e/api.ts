import type { APIRequestContext } from '@playwright/test'

import { APP_PFAD } from './rollen.ts'

/**
 * Testdaten ueber die HTTP-API aufbauen, nicht ueber die Oberflaeche.
 *
 * **Warum nicht klickend.** Ein Test, der sein Board erst zusammenklickt,
 * haengt an jedem Knopf, den er zum Aufbauen benutzt — faellt der Aufbau um,
 * faellt der Test um, und man sucht den Fehler an der falschen Stelle. Ueber
 * die API gebaut, prueft der Test nur noch das, wofuer er geschrieben wurde.
 *
 * **Warum kein Seed-Skript in PHP.** Das waere Produktionscode, den nur Tests
 * brauchen — und ein zweiter Weg in die Datenbank, an dem die
 * Sichtbarkeitsregel ein zweites Mal stimmen muesste.
 *
 * **Das `requesttoken`.** Unsere Schreibrouten tragen bewusst kein
 * `#[NoCSRFRequired]` (§3.5). Nextcloud nimmt den Token aus Query, Body oder
 * dem Header `requesttoken` (`Request.php:439-444`); wir nehmen den Header und
 * holen ihn aus der ausgelieferten Seite.
 */
export class Api {
	private constructor(
		private readonly request: APIRequestContext,
		private readonly token: string,
	) {}

	/**
	 * Liest das `requesttoken` aus der App-Seite.
	 *
	 * Die Sitzungs-Cookies kommen aus `storageState` — ohne sie liefert
	 * Nextcloud die Anmeldeseite, und dann steht dort zwar ein Token, aber ein
	 * anonymes. Deshalb die Gegenprobe auf `data-user`.
	 */
	static async fuer(request: APIRequestContext): Promise<Api> {
		const antwort = await request.get(APP_PFAD)
		if (!antwort.ok()) {
			throw new Error(`App-Seite nicht erreichbar: HTTP ${antwort.status()} auf ${APP_PFAD}`)
		}

		const html = await antwort.text()
		const benutzer = html.match(/data-user="([^"]*)"/)?.[1] ?? ''
		if (benutzer === '') {
			throw new Error('App-Seite ohne angemeldeten Benutzer — die Sitzung aus storageState greift nicht')
		}

		const token = html.match(/data-requesttoken="([^"]+)"/)?.[1]
		if (token === undefined) {
			throw new Error('Kein data-requesttoken in der App-Seite gefunden')
		}

		return new Api(request, token)
	}

	/**
	 * Ein Weg fuer alle Methoden — **auch fuer GET**.
	 *
	 * Nextcloud prueft das `requesttoken` nicht nur bei Schreibwegen: Ohne
	 * `#[NoCSRFRequired]` verlangt es die SecurityMiddleware bei *jeder*
	 * Controller-Methode, Leseweg eingeschlossen. Ein `GET` ohne Token
	 * beantwortet die App mit 412 „CSRF check failed" — was wie ein
	 * Rechteproblem aussieht und keines ist.
	 */
	private async rufen(
		methode: 'get' | 'post' | 'put' | 'patch' | 'delete',
		pfad: string,
		daten?: object,
	): Promise<any> {
		const antwort = await this.request[methode](`/index.php/apps/projektwerk${pfad}`, {
			headers: { requesttoken: this.token, 'Content-Type': 'application/json' },
			...(daten === undefined ? {} : { data: daten }),
		})

		if (!antwort.ok()) {
			// Der Rumpf steht mit in der Meldung: Nextcloud beschreibt in ihm,
			// woran es lag. Ohne ihn bleibt nur eine Zahl, und die schickt bei
			// 403 zuverlaessig in die falsche Richtung (Rechte statt Token).
			throw new Error(`${methode.toUpperCase()} ${pfad} -> HTTP ${antwort.status()}: ${await antwort.text()}`)
		}

		return antwort.json()
	}

	private schreiben(methode: 'post' | 'put' | 'patch' | 'delete', pfad: string, daten: object): Promise<any> {
		return this.rufen(methode, pfad, daten)
	}

	lesen(pfad: string): Promise<any> {
		return this.rufen('get', pfad)
	}

	boardAnlegen(titel: string, orgIntern: string, orgExtern: string): Promise<any> {
		return this.schreiben('post', '/api/v1/boards', {
			title: titel,
			orgInternal: orgIntern,
			orgExternal: orgExtern,
		})
	}

	boardZeigen(boardId: number): Promise<any> {
		return this.lesen(`/api/v1/boards/${boardId}`)
	}

	mitgliedHinzufuegen(boardId: number, uid: string, rolle: 'internal' | 'external'): Promise<any> {
		return this.schreiben('post', `/api/v1/boards/${boardId}/members`, { userId: uid, role: rolle })
	}

	ticketAnlegen(boardId: number, daten: {
		title: string
		columnId: number
		visibility: 'public' | 'internal' | 'private'
		description?: string
		responsibleUserId?: string
	}): Promise<any> {
		return this.schreiben('post', `/api/v1/boards/${boardId}/tickets`, daten)
	}

	/**
	 * Aufraeumen heisst archivieren, nicht loeschen.
	 *
	 * Es gibt bewusst keine Route zum Loeschen eines Boards — §5.18 sagt „sie
	 * loescht nie". Ein Test, der sich eine Ausnahme davon bauen wuerde, waere
	 * genau die Hintertuer, deren Fehlen die App verspricht.
	 */
	boardArchivieren(boardId: number): Promise<any> {
		return this.schreiben('put', `/api/v1/boards/${boardId}/archived`, { archived: true })
	}
}
