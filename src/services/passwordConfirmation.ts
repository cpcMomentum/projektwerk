/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

interface PasswordConfirmation {
	requirePasswordConfirmation(done: () => void, options?: Record<string, unknown>, rejected?: () => void): void
}

/** Dialog der Nextcloud-Laufzeit statt eines Pakets (wie `toast.ts`); fehlt er, lehnt der Server ab. */
export async function confirmPassword(): Promise<void> {
	const dialog = (globalThis as { OC?: { PasswordConfirmation?: PasswordConfirmation } }).OC?.PasswordConfirmation
	if (dialog?.requirePasswordConfirmation === undefined) {
		return
	}

	await new Promise<void>((resolve, reject) => {
		dialog.requirePasswordConfirmation(resolve, {}, () => reject(new Error(t('projektwerk', 'Die Passwortbestätigung wurde abgebrochen.'))))
	})
}
