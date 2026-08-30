/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Das Gäste-Gate (#234): Wohin ein Betrachter statt auf den Überblick gehört.
 *
 * Geprüft wird die **Regel**, nicht der Router: Der Überblick ist ein internes
 * Werkzeug. Wer irgendwo intern ist, sieht ihn; wer überall extern ist, wird
 * auf sein Board geleitet (bei mehreren auf die Projektliste); wer nirgends
 * Mitglied ist, sieht ihn leer. Die Fälle, die hier drohen, sperren entweder
 * den Dienstleister aus seinem Werkzeug oder lassen den Kunden auf einer Seite
 * landen, die nicht für ihn ist.
 */

import type { Board, MemberRole } from '@/types/board'

import { describe, expect, it } from 'vitest'
import { gateTarget } from '@/router'

/**
 * Ein Board mit genau dem Feld, das die Regel liest.
 *
 * @param id Kennung.
 * @param viewerRole Die eigene Rolle in diesem Projekt, oder undefined.
 */
function board(id: number, viewerRole?: MemberRole): Board {
	return { id, viewerRole } as unknown as Board
}

describe('gateTarget', () => {
	it('zeigt den Überblick, wer in mindestens einem Projekt intern ist', () => {
		expect(gateTarget([board(1, 'external'), board(2, 'internal')])).toBeNull()
	})

	it('zeigt den Überblick auch dem rein internen Betrachter', () => {
		expect(gateTarget([board(1, 'internal')])).toBeNull()
	})

	it('leitet den Betrachter mit genau einem externen Projekt auf sein Board', () => {
		expect(gateTarget([board(42, 'external')])).toEqual({
			name: 'board',
			params: { boardId: '42' },
		})
	})

	it('leitet den überall externen Betrachter mit mehreren Projekten auf die Projektliste', () => {
		expect(gateTarget([board(1, 'external'), board(2, 'external')])).toEqual({
			name: 'boards',
		})
	})

	it('zeigt den Überblick, wer in keinem Projekt Mitglied ist', () => {
		// Kein Board, auf das man umleiten könnte — und ein interner Erstnutzer
		// legt hier sein erstes Projekt an.
		expect(gateTarget([])).toBeNull()
	})

	it('behandelt ein fehlendes Rollenfeld nicht als intern', () => {
		// Ein Board ohne `viewerRole` (etwa aus einer alten Antwort) zählt nicht
		// als internes Projekt — sonst risse das Gate für den Kunden auf.
		expect(gateTarget([board(7)])).toEqual({
			name: 'board',
			params: { boardId: '7' },
		})
	})
})
