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

import { t } from '@nextcloud/l10n'
import { WERKPLUS_LINKS } from '@/utils/werkplusLinks'

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

// An einer Grenze des freien Umfangs (werkplusLimit) mit Link zu WerkPlus, sonst wie showError.
export function showFailure(error: unknown, fallback: string): void {
	const e = error as { status?: number, message?: string, data?: { werkplusLimit?: unknown } } | undefined
	const message = e?.message ?? fallback
	// 402 steht nur für Grenzen des freien Umfangs; so trägt auch eine künftige Grenze ohne Flag den Link.
	if (e?.data?.werkplusLimit !== true && e?.status !== 402) {
		showError(message)
		return
	}
	const toast = (globalThis as { OCP?: { Toast?: { error?(m: string, o?: Record<string, unknown>): void } } }).OCP?.Toast
	if (toast?.error === undefined) {
		showError(message)
		return
	}
	const link = `<a href="${WERKPLUS_LINKS.buy}" target="_blank" rel="noopener noreferrer" style="margin-inline-start: 0.5em; text-decoration: underline; white-space: nowrap">${escapeHtml(t('projektwerk', 'Mit WerkPlus freischalten'))}</a>`
	toast.error(`<span>${escapeHtml(message)}</span>${link}`, { isHTML: true, timeout: 15000 })
}

/**
 * @param text Beliebiger Text, der in HTML landet.
 */
function escapeHtml(text: string): string {
	return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
}

/**
 * Eine Erfolgsmeldung — dieselbe Hülle wie `showError`, nur die grüne
 * Variante. Fällt ohne `OCP` auf die Konsole zurück.
 *
 * @param message Der Text für die Person vor dem Schirm.
 */
export function showSuccess(message: string): void {
	const toast = (globalThis as { OCP?: { Toast?: { success?(m: string): void } } }).OCP?.Toast
	if (toast?.success !== undefined) {
		toast.success(message)
		return
	}

	// eslint-disable-next-line no-console
	console.info('[projektwerk]', message)
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
