/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Zentraler Wrapper um @nextcloud/axios. Sitzung und CSRF erledigt
 * @nextcloud/axios selbst (OC.requestToken).
 *
 * Der eigentliche Zweck dieser Datei ist der Nicht-JSON-Wächter. Steht
 * ProjektWerk nicht auf der Freigabeliste der Guests-App, beantwortet
 * Nextcloud JEDE Anfrage eines Gastes mit einer HTML-Fehlerseite — auch
 * Schnittstellenaufrufe. Ohne diesen Wächter stirbt das Frontend beim ersten
 * Kunden an einem Parse-Fehler, der nichts über die Ursache sagt.
 *
 * ES DARF NICHT AM STATUSCODE HÄNGEN. Spike S1 hat am 2026-08-07 gemessen:
 * Der Status ist 500, nicht 403. Ein Berechtigungsfall im Gewand eines
 * Serverfehlers. Wer auf 403 prüft, baut einen Wächter, der genau im
 * gemessenen Fall schweigt.
 */

import type { AxiosError, AxiosResponse } from '@nextcloud/axios'

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@/services/toast'

const APP_ID = 'projektwerk'

/** Fehler, den die Aufrufer erwarten dürfen. */
export interface ApiError {
	status: number
	message: string
	/** Die Antwort war HTML statt JSON — fast immer die Guests-Freigabeliste. */
	notJson: boolean
}

/**
 * Baut die volle App-URL fuer einen API-Pfad.
 *
 * @param path Pfad relativ zu `/apps/projektwerk/api/v1`, z. B. `/boards`.
 */
export function apiUrl(path: string): string {
	return generateUrl(`/apps/${APP_ID}/api/v1${path}`)
}

/**
 * Sieht diese Antwort nach JSON aus?
 *
 * Zwei Merkmale, weil eines nicht reicht: Der Inhaltstyp fehlt bei manchen
 * Zwischenstationen ganz, und ein String-Rumpf kann auch dann JSON sein, wenn
 * axios ihn nicht geparst hat. Geprüft wird deshalb der Inhaltstyp UND — falls
 * der schweigt — der erste Zeichen des Rumpfs.
 *
 * @param response
 */
function looksLikeJson(response: AxiosResponse | undefined): boolean {
	if (response === undefined) {
		// Gar keine Antwort: Netzfehler, Abbruch, Zeitüberschreitung. Das ist
		// kein Freigabelisten-Fall und darf nicht als solcher gemeldet werden.
		return true
	}

	const contentType = String(response.headers?.['content-type'] ?? '').toLowerCase()

	if (contentType.includes('application/json')) {
		return true
	}
	if (contentType.includes('text/html')) {
		return false
	}

	// Kein aussagekräftiger Inhaltstyp: am Rumpf entscheiden.
	const body = response.data
	if (typeof body === 'object' && body !== null) {
		return true
	}
	if (typeof body === 'string') {
		return !body.trimStart().startsWith('<')
	}

	return true
}

/**
 * Die Meldung, die den wahrscheinlichsten Erst-Kunden-Fehlschlag erklärt.
 *
 * Sie nennt die Ursache und nicht das Symptom — wer sie liest, weiß, wen er
 * fragen muss.
 */
function reportNotJson(): void {
	// Ein einziges Literal, keine Verkettung: Übersetzungswerkzeuge lesen den
	// Aufruf statisch aus und fänden einen zusammengesetzten String nicht.
	showError(t(APP_ID, 'ProjektWerk ist für Gastkonten nicht freigeschaltet. Die Administration muss die App auf die Freigabeliste der Guests-App setzen.'))
}

/**
 * Verpackt einen abgelehnten Axios-Aufruf als `ApiError`.
 *
 * @param error der von axios geworfene Fehler, ungeprueft
 */
function wrapError(error: unknown): ApiError {
	const axiosError = error as AxiosError<{ error?: string }>
	const response = axiosError.response
	const status = response?.status ?? 0

	if (!looksLikeJson(response)) {
		// Absichtlich vor jeder Statusauswertung: Der gemessene Fall ist 500,
		// und ein 500 mit HTML ist hier der Normalfall, nicht die Ausnahme.
		reportNotJson()

		return {
			status,
			message: 'Antwort war kein JSON — App vermutlich nicht für Gäste freigeschaltet',
			notJson: true,
		}
	}

	if (response === undefined) {
		// **Gar keine Antwort** — Verbindungsabbruch, Zeitüberschreitung,
		// schlafendes Handy. Axios legt hier `message: 'Network Error'` bei,
		// und das stand bis 2026-08-10 wörtlich als Meldung vor einem deutschen
		// Nutzer: englischer Fachbegriff für den häufigsten aller Fehler.
		//
		// Gemessen an der Aufgabenansicht, gilt aber für jeden Aufruf der App.
		return {
			status,
			message: t(APP_ID, 'Keine Verbindung zum Server. Bitte später erneut versuchen.'),
			notJson: false,
		}
	}

	return {
		status,
		// Die Meldung des Servers hat Vorrang: Sie ist die einzige, die den
		// Fall kennt. Erst wenn sie fehlt, greift die des Aufrufers.
		message: response.data?.error ?? axiosError.message ?? 'Unbekannter Fehler',
		notJson: false,
	}
}

/**
 * Auch der Erfolgsfall wird geprüft.
 *
 * Ein 200 mit HTML ist selten, aber möglich — ein Zwischenserver, der eine
 * Anmeldeseite ausliefert, tut genau das. Der Wächter würde ihn sonst gerade
 * dort verfehlen, wo er am unverständlichsten wäre.
 *
 * @param response
 */
function unwrap<T>(response: AxiosResponse<T>): T {
	if (!looksLikeJson(response)) {
		reportNotJson()
		throw {
			status: response.status,
			message: 'Antwort war kein JSON',
			notJson: true,
		} satisfies ApiError
	}

	return response.data
}

/**
 * GET gegen die App-API, durch den Nicht-JSON-Wächter geprüft.
 *
 * @param path Pfad relativ zu `/apps/projektwerk/api/v1`.
 */
export async function apiGet<T>(path: string): Promise<T> {
	try {
		return unwrap(await axios.get<T>(apiUrl(path)))
	} catch (e) {
		throw isApiError(e) ? e : wrapError(e)
	}
}

/**
 * POST gegen die App-API, durch den Nicht-JSON-Wächter geprüft.
 *
 * @param path Pfad relativ zu `/apps/projektwerk/api/v1`.
 * @param body Nutzlast, wird als JSON gesendet.
 */
export async function apiPost<T, B = unknown>(path: string, body: B): Promise<T> {
	try {
		return unwrap(await axios.post<T>(apiUrl(path), body))
	} catch (e) {
		throw isApiError(e) ? e : wrapError(e)
	}
}

/**
 * PUT gegen die App-API, durch den Nicht-JSON-Wächter geprüft.
 *
 * @param path Pfad relativ zu `/apps/projektwerk/api/v1`.
 * @param body Nutzlast, wird als JSON gesendet.
 */
export async function apiPut<T, B = unknown>(path: string, body: B): Promise<T> {
	try {
		return unwrap(await axios.put<T>(apiUrl(path), body))
	} catch (e) {
		throw isApiError(e) ? e : wrapError(e)
	}
}

/**
 * PATCH gegen die App-API, durch den Nicht-JSON-Wächter geprüft.
 *
 * @param path Pfad relativ zu `/apps/projektwerk/api/v1`.
 * @param body Nutzlast, wird als JSON gesendet.
 */
export async function apiPatch<T, B = unknown>(path: string, body: B): Promise<T> {
	try {
		return unwrap(await axios.patch<T>(apiUrl(path), body))
	} catch (e) {
		throw isApiError(e) ? e : wrapError(e)
	}
}

/**
 * DELETE gegen die App-API, durch den Nicht-JSON-Wächter geprüft.
 *
 * **Mit Rumpf, wenn einer mitgegeben wird.** Das ist kein Kunstgriff:
 * Nextclouds `Request` decodiert einen JSON-Rumpf für jede Methode außer GET
 * und legt ihn in dieselben Parameter, aus denen der Controller liest. Ein
 * Pflichtparameter wie die Zielspalte beim Entfernen einer Spalte gehört
 * dorthin und nicht in die Adresse — er ist Teil des Vorgangs, nicht Teil des
 * Ortes.
 *
 * @param path Pfad relativ zu `/apps/projektwerk/api/v1`.
 * @param body Nutzlast, wird als JSON gesendet. Ohne sie ein Aufruf wie bisher.
 */
export async function apiDelete<T = void, B = unknown>(path: string, body?: B): Promise<T> {
	try {
		return unwrap(await axios.delete<T>(apiUrl(path), body === undefined ? undefined : { data: body }))
	} catch (e) {
		throw isApiError(e) ? e : wrapError(e)
	}
}

/**
 * `unwrap()` wirft bereits einen fertigen ApiError. Ohne diese Unterscheidung
 * liefe er ein zweites Mal durch `wrapError()` und die Meldung erschiene
 * doppelt.
 *
 * @param value
 */
function isApiError(value: unknown): value is ApiError {
	return typeof value === 'object' && value !== null && 'notJson' in value
}
