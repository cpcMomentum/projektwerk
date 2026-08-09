/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Was ein fehlgeschlagener Schreibvorgang der Person vor dem Schirm sagt.
 *
 * Eine Stelle, weil der Konfliktfall überall dieselbe Bedeutung hat und
 * dieselbe Handlung nach sich zieht. Zwei Formulierungen desselben Falls wären
 * zwei Gelegenheiten, ihn beim nächsten Endpunkt zu vergessen — und der
 * Konflikt ist der eine Fehler, bei dem ein zweiter Versuch mit denselben Daten
 * garantiert wieder scheitert.
 */

import { t } from '@nextcloud/l10n'
import { showError } from '@/services/toast'

/**
 * Was diese Stelle von einem fehlgeschlagenen Schreibvorgang braucht.
 *
 * Beim Konflikt legt der Server zusätzlich den **aktuellen Stand** bei
 * (`current`), damit das Frontend nicht raten muss, was sich geändert hat. Er
 * steht hier bewusst nicht: Wer ihn auswertet, tut das an der Stelle, die den
 * Stand auch halten kann — hier wird nur gemeldet.
 */
interface WriteError {
	status?: number
	message?: string
}

/**
 * Ist das ein Versionskonflikt?
 *
 * @param error Was der Aufruf geworfen hat, ungeprüft.
 */
export function isConflict(error: unknown): boolean {
	return (error as WriteError)?.status === 409
}

/**
 * Den Fehlschlag melden und sagen, ob es ein Konflikt war.
 *
 * Der Rückgabewert erspart den Aufrufern eine zweite Fallunterscheidung: Wer
 * den Stand nachladen kann, tut es; wer nur ein Formular offen hat, lässt es.
 *
 * @param error Was der Aufruf geworfen hat, ungeprüft.
 * @param fallback Meldung, wenn der Server keine eigene mitgibt.
 * @param reloaded Ob der Aufrufer den Stand bereits selbst nachlädt.
 */
export function reportWriteError(error: unknown, fallback: string, reloaded = false): boolean {
	if (isConflict(error)) {
		showError(reloaded
			// Zwei Sätze, kein zusammengesetzter String: Die
			// Übersetzungswerkzeuge lesen die Aufrufe statisch aus.
			? t('projektwerk', 'Der Vorgang wurde zwischenzeitlich geändert. Der Stand wurde neu geladen.')
			: t('projektwerk', 'Der Vorgang wurde zwischenzeitlich geändert. Bitte neu laden.'))

		return true
	}

	showError((error as WriteError)?.message ?? fallback)

	return false
}
