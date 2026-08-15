/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Der Ordner-Wähler spricht WebDAV, nicht unsere API (#139).
 *
 * Nextclouds nativer FilePicker (`@nextcloud/dialogs`) lässt sich nicht in unser
 * IIFE-Bundle ziehen — er lädt seine Oberfläche als eigenen Chunk per
 * dynamischem `import()`, und Vite 8/Rolldown baut IIFE nicht mit Code-Splitting
 * (bestätigt per Spike, siehe #139). Deshalb ein eigener, kleiner Wähler, der
 * die Ordner der Person direkt über WebDAV liest und anlegt — dieselbe
 * Philosophie wie der selbst gebaute Personen-Picker.
 *
 * Gelesen und geschrieben wird ausschließlich im eigenen Dateibaum der Person
 * (`dav/files/<uid>`). Die Sitzung und das `requesttoken` erledigt
 * `@nextcloud/axios`; WebDAV toleriert den zusätzlichen Header.
 */

import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'

/** Ein Ordnereintrag: Anzeigename und der Pfad relativ zur Files-Wurzel. */
export interface FolderEntry {
	name: string
	path: string
}

/** Die Kennung der angemeldeten Person — aus dem Kopf, ohne zusätzliche Abhängigkeit. */
function currentUid(): string {
	const uid = document.querySelector('head')?.getAttribute('data-user')
	if (uid === null || uid === undefined || uid === '') {
		throw new Error('Keine angemeldete Person gefunden.')
	}
	return uid
}

/** Die WebDAV-Wurzel des eigenen Dateibaums. */
function davBase(): string {
	return generateRemoteUrl('dav/files/' + currentUid())
}

/**
 * Ein Pfad relativ zur Files-Wurzel als URL-Segmente — jedes Segment einzeln
 * kodiert, damit Leer- und Sonderzeichen tragen, die Schrägstriche aber
 * Trenner bleiben.
 *
 * @param path Pfad relativ zur Files-Wurzel (führende Schrägstriche egal).
 */
function toUrl(path: string): string {
	const segments = path.split('/').filter((s) => s !== '')
	const encoded = segments.map((s) => encodeURIComponent(s)).join('/')
	return encoded === '' ? davBase() + '/' : davBase() + '/' + encoded + '/'
}

/**
 * Aus einer WebDAV-`href` den Pfad relativ zur Files-Wurzel gewinnen.
 *
 * Die href trägt den vollen DAV-Pfad (`/remote.php/dav/files/uid/Projekte/…`);
 * gebraucht wird der Teil dahinter, dekodiert.
 *
 * @param href Die `href` aus der WebDAV-Antwort.
 */
function pathFromHref(href: string): string {
	// Erst dekodieren, dann die Kennung suchen: Die `href` kodiert jedes
	// Segment einzeln (auch die Kennung selbst, etwa bei Umlauten), die
	// Kennung aus currentUid() aber nicht — ein Vergleich vor dem Dekodieren
	// schlüge für solche Kennungen immer fehl.
	const decoded = decodeURIComponent(href)
	const marker = '/dav/files/' + currentUid() + '/'
	const at = decoded.indexOf(marker)
	const rest = at === -1 ? decoded : decoded.slice(at + marker.length)
	return rest.replace(/\/+$/, '').replace(/^\/+/, '')
}

/**
 * Die Unterordner eines Verzeichnisses, alphabetisch.
 *
 * Nur Ordner, keine Dateien: Der Wähler soll einen Ablageort bestimmen, keine
 * Datei. Der Eintrag zum Verzeichnis selbst (der erste in der Antwort) fällt
 * heraus.
 *
 * @param path Pfad relativ zur Files-Wurzel; leer heißt Wurzel.
 * @return Die Unterordner mit Anzeigename und Pfad.
 */
export async function folderChildren(path: string): Promise<FolderEntry[]> {
	const body = '<?xml version="1.0"?>'
		+ '<d:propfind xmlns:d="DAV:"><d:prop>'
		+ '<d:resourcetype/><d:displayname/>'
		+ '</d:prop></d:propfind>'

	const response = await axios.request<string>({
		method: 'PROPFIND',
		url: toUrl(path),
		data: body,
		headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8' },
		// Als Text lesen und selbst parsen — WebDAV antwortet XML, nicht JSON.
		responseType: 'text',
	})

	const doc = new DOMParser().parseFromString(response.data, 'application/xml')
	// Über den **relativen Pfad** vergleichen, nicht über die URL: Die `href` aus
	// der Antwort trägt nur den Pfad, `toUrl` aber den vollen Ursprung — ein
	// Vergleich der beiden schlüge immer fehl, und das Verzeichnis erschiene als
	// sein eigener Unterordner.
	const selfPath = path.replace(/\/+$/, '').replace(/^\/+/, '')
	const entries: FolderEntry[] = []

	for (const node of Array.from(doc.getElementsByTagNameNS('DAV:', 'response'))) {
		const isFolder = node.getElementsByTagNameNS('DAV:', 'collection').length > 0
		if (!isFolder) {
			continue
		}
		const href = node.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent ?? ''
		if (href === '') {
			continue
		}
		const childPath = pathFromHref(href)
		// Das Verzeichnis selbst kommt als erster Eintrag mit — und darf nicht
		// als sein eigener Unterordner erscheinen.
		if (childPath === selfPath) {
			continue
		}
		const name = childPath.split('/').pop() ?? childPath
		entries.push({ name, path: childPath })
	}

	entries.sort((a, b) => a.name.localeCompare(b.name))
	return entries
}

/**
 * Einen Ordner anlegen (WebDAV MKCOL).
 *
 * @param path Pfad des neuen Ordners relativ zur Files-Wurzel.
 */
export async function createFolder(path: string): Promise<void> {
	await axios.request({ method: 'MKCOL', url: toUrl(path) })
}
