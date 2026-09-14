/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Das Antwort-Postfach (#286) — Admin-Einstellungen.
 *
 * Instanz-weite IMAP-Konfiguration, aus der der Einlese-Job (#287) Antworten
 * liest. Alle Endpunkte verlangen ein Admin-Konto (der Server weist andere ab);
 * die Bedienung liegt darum hinter einem admin-only Menüeintrag. Das Passwort
 * verlässt den Server nie — gelesen wird nur `imapPasswordSet`.
 */

import { apiGet, apiPost, apiPut } from '@/services/api'

/** Die Einstellungen, wie die Oberfläche sie liest — ohne Passwort. */
export interface ReplyMailboxConfig {
	replyEnabled: boolean
	replyAddress: string
	imapHost: string
	imapPort: number
	imapSecurity: string
	imapUser: string
	imapFolder: string
	/** Ob ein Passwort hinterlegt ist — nie das Passwort selbst. */
	imapPasswordSet: boolean
}

/**
 * Der Speicher-Rumpf: die Verbindungsfelder plus optional ein neues Passwort.
 * `imapPassword` wird nur bei Neueingabe mitgeschickt (leer = unverändert),
 * `imapPasswordClear` löscht das gespeicherte.
 */
export interface ReplyMailboxSave extends Omit<ReplyMailboxConfig, 'imapPasswordSet'> {
	imapPassword?: string
	imapPasswordClear?: boolean
}

/** Die Verbindungsfelder für den „Verbindung testen"-Aufruf. */
export interface ReplyMailboxTest {
	imapHost: string
	imapPort: number
	imapSecurity: string
	imapUser: string
	imapFolder: string
	imapPassword?: string
}

/** Die aktuellen Einstellungen laden. */
export async function fetchReplyMailbox(): Promise<ReplyMailboxConfig> {
	return apiGet<ReplyMailboxConfig>('/reply-mailbox')
}

/**
 * Einstellungen speichern; gibt die neue (passwortlose) Fassung zurück.
 *
 * @param data Die zu speichernden Felder, optional mit neuem Passwort.
 */
export async function saveReplyMailbox(data: ReplyMailboxSave): Promise<ReplyMailboxConfig> {
	return apiPut<ReplyMailboxConfig, ReplyMailboxSave>('/reply-mailbox', data)
}

/**
 * Die Verbindung testen. Erfolg → `{ ok: true }`; ein Fehlschlag kommt als
 * abgelehnter Aufruf (`ApiError` mit der Server-Meldung) zurück, den der
 * Aufrufer anzeigt.
 *
 * @param data Die Verbindungsfelder; `imapPassword` optional (sonst gespeichertes).
 */
export async function testReplyMailbox(data: ReplyMailboxTest): Promise<{ ok: boolean }> {
	return apiPost<{ ok: boolean }, ReplyMailboxTest>('/reply-mailbox/test', data)
}
