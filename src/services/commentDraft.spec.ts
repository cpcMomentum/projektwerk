/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { loadCommentDraft, saveCommentDraft } from '@/services/commentDraft'

let speicher: Map<string, string>

beforeEach(() => {
	speicher = new Map()
	vi.stubGlobal('localStorage', {
		getItem: (k: string) => speicher.get(k) ?? null,
		setItem: (k: string, v: string) => speicher.set(k, v),
		removeItem: (k: string) => speicher.delete(k),
	})
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('commentDraft', () => {
	it('bewahrt den Entwurf je Konto und Vorgang auf', () => {
		saveCommentDraft('anna', 42, 'Halbfertig')

		expect(loadCommentDraft('anna', 42)).toBe('Halbfertig')
		expect(loadCommentDraft('anna', 43)).toBe('')
		expect(loadCommentDraft('carla', 42)).toBe('')
	})

	it('löscht den Entwurf, wenn das Feld geleert wird', () => {
		saveCommentDraft('anna', 42, 'Halbfertig')
		saveCommentDraft('anna', 42, '   ')

		expect(speicher.size).toBe(0)
	})

	it('übersteht einen gesperrten Speicher', () => {
		vi.stubGlobal('localStorage', {
			getItem: () => {
				throw new Error('SecurityError')
			},
			setItem: () => {
				throw new Error('SecurityError')
			},
		})

		expect(() => saveCommentDraft('anna', 42, 'x')).not.toThrow()
		expect(loadCommentDraft('anna', 42)).toBe('')
	})
})
