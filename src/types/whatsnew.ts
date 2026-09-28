/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Formen des „Was ist neu?"-Fensters (#315).
 */

/** Ein Eintrag, bereits in Nutzersprache. */
export interface WhatsNewEntry {
	title: string
	text: string
	/** Name aus der Symbolliste des Dialogs; unbekannt oder leer = Stern. */
	icon: string
	/** Wo die Neuerung sitzt, etwa „Projekte". Leer = keine Fundort-Zeile. */
	where: string
	/** Stelle ist adminpflichtig; der Dialog schreibt es dazu. */
	adminOnly: boolean
	/** WerkPlus-Feature: Badge und Link auf werkwolke.de. */
	plus: boolean
}

/** Antwort von `GET /whatsnew`. Leere Liste heisst: kein Fenster. */
export interface WhatsNewPayload {
	version: string
	entries: WhatsNewEntry[]
}

/** Eine Version mit ihren Einträgen im Archiv (#329). */
export interface WhatsNewGroup {
	version: string
	entries: WhatsNewEntry[]
}

/** Antwort von `GET /whatsnew/all` — alle Versionen, neueste zuerst. Für Gäste leer. */
export interface WhatsNewArchive {
	versions: WhatsNewGroup[]
}
