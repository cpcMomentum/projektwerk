/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Nextcloud-Laufzeitobjekte, die wir nutzen.
 *
 * `@nextcloud/dialogs` wird bewusst NICHT gebündelt — dieselbe Entscheidung wie
 * in RechnungsWerk. Das Paket hat genau einen Einstiegspunkt und zieht darüber
 * den kompletten FilePicker samt Farb- und Datumswähler mit; die nutzen
 * `defineAsyncComponent`, und ein aufgeteiltes Bundle verträgt sich nicht mit
 * dem IIFE-Format, das Nextcloud über `addScript()` lädt. Gemessen: gut 500 kB
 * zusätzlich, verteilt auf ein Dutzend Teildateien, die niemand anfordert.
 *
 * Die Meldungsanzeige liefert der Server ohnehin als `OCP.Toast`.
 */

declare global {
	const OCP: {
		Toast: {
			error(message: string, options?: Record<string, unknown>): void
			success(message: string, options?: Record<string, unknown>): void
			info(message: string, options?: Record<string, unknown>): void
		}
	}
}

export {}
