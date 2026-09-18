/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Vorschlagslisten für Firma und Kunde (#309 Phase 2).
 *
 * Sie entstehen **client-seitig** aus dem bereits geladenen State — kein
 * eigener Lesepfad. Geprüft wird die Destillation: ohne Dubletten, getrimmt,
 * leere/`null`-Werte raus, alphabetisch.
 */

import type { Board, Member } from '@/types/board'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useBoardStore } from '@/stores/boardStore'

describe('boardStore: Firma-/Kunde-Vorschläge (#309)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('companySuggestions: distinct, getrimmt, leere raus, alphabetisch', () => {
		const store = useBoardStore()
		store.members = [
			{ company: 'Nect' },
			{ company: '  cpcMomentum ' },
			{ company: 'Nect' },
			{ company: null },
			{ company: '' },
			{ company: '   ' },
		] as unknown as Member[]

		expect(store.companySuggestions).toEqual(['cpcMomentum', 'Nect'])
	})

	it('customerSuggestions: distinct, getrimmt, leere raus, alphabetisch', () => {
		const store = useBoardStore()
		store.boards = [
			{ customer: 'MI' },
			{ customer: 'MI' },
			{ customer: ' BMW ' },
			{ customer: null },
		] as unknown as Board[]

		expect(store.customerSuggestions).toEqual(['BMW', 'MI'])
	})

	it('leere Listen ergeben leere Vorschläge', () => {
		const store = useBoardStore()
		expect(store.companySuggestions).toEqual([])
		expect(store.customerSuggestions).toEqual([])
	})
})
