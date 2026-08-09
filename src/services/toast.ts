/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Meldungen an die Person vor dem Schirm.
 *
 * Dünne Hülle um `OCP.Toast` aus der Nextcloud-Laufzeit statt um
 * `@nextcloud/dialogs` — das Paket zöge den FilePicker mit und damit ein
 * aufgeteiltes Bundle, das sich mit dem IIFE-Format nicht verträgt (siehe
 * `src/types/globals.d.ts`).
 *
 * Als Hülle und nicht als direkter Aufruf, aus zwei Gründen: Die Tests können
 * sie ersetzen, ohne ein globales Objekt zu erfinden — und wenn `OCP` fehlt
 * (Testumgebung, kaputte Seite), fällt sie auf die Konsole zurück, statt eine
 * Ausnahme zu werfen und damit den Fehler zu verdecken, den sie melden sollte.
 */

/**
 * @param message Der Text für die Person vor dem Schirm.
 */
export function showError(message: string): void {
	const toast = (globalThis as { OCP?: { Toast?: { error?(m: string): void } } }).OCP?.Toast
	if (toast?.error !== undefined) {
		toast.error(message)
		return
	}

	// Letzter Ausweg: Ohne OCP gäbe es sonst gar keine Meldung, und der Fehler
	// verschwände spurlos — ausgerechnet der, den diese Funktion melden soll.
	// eslint-disable-next-line no-console
	console.error('[projektwerk]', message)
}
