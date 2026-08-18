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

/**
 * Ein Undo-Toast (#167): meldet eine erledigte Aktion und bietet an, sie
 * rückgängig zu machen. Ein Klick auf den Toast ruft `onUndo` und schließt ihn.
 *
 * **Bewusst über `OCP.Toast` und nicht über `showUndo` aus `@nextcloud/dialogs`.**
 * Dasselbe wie beim übrigen Toast-Weg: Das Paket zöge den FilePicker mit und
 * sprengte das IIFE-Bundle. `OCP.Toast.info` nimmt dieselben Optionen wie die
 * Nextcloud-Laufzeit — darunter `onClick` und `timeout`.
 *
 * @param message Der Text, der schon zum Rückgängigmachen einlädt.
 * @param onUndo Wird beim Klick auf den Toast gerufen.
 * @param timeout Wie lange der Toast steht, in Millisekunden.
 */
export function showUndo(message: string, onUndo: () => void, timeout = 8000): void {
	type Undoable = { info?(m: string, o?: Record<string, unknown>): { hideToast?(): void } | void }
	const toast = (globalThis as { OCP?: { Toast?: Undoable } }).OCP?.Toast
	if (toast?.info !== undefined) {
		// `onClick` läuft erst beim Klick, also lange nach der Zuweisung — die
		// Selbstreferenz auf `handle` ist zur Laufzeit gesetzt.
		const handle = toast.info(message, {
			timeout,
			onClick: () => {
				onUndo()
				handle?.hideToast?.()
			},
		})
		return
	}

	// Ohne OCP kein Toast — dann bleibt die Aktion eben ohne sichtbares Undo.
	// eslint-disable-next-line no-console
	console.info('[projektwerk]', message)
}
