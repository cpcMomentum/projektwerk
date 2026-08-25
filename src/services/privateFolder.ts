/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Der eigene Ordner für private Anhänge (#184, Phase B).
 *
 * User-scoped wie die Kanalschalter: kein Board im Pfad, die Grenze ist die
 * Sitzung. Der Server liefert den eingestellten Pfad — oder die Vorgabe, wenn
 * keiner gewählt ist.
 */

import { apiGet, apiPut } from '@/services/api'

export interface PrivateFolder {
	path: string
}

/** Den aktuell eingestellten (oder vorgegebenen) Ordner lesen. */
export function fetchPrivateFolder(): Promise<PrivateFolder> {
	return apiGet<PrivateFolder>('/my/private-folder')
}

/**
 * Einen anderen Ordner wählen. Der Server prüft ihn (und legt ihn bei Bedarf
 * an); ein unmöglicher Ordner kommt als Fehler zurück.
 *
 * @param path Der Ordnerpfad im eigenen Dateibaum.
 */
export function setPrivateFolder(path: string): Promise<PrivateFolder> {
	return apiPut<PrivateFolder, { path: string }>('/my/private-folder', { path })
}
