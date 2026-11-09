/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as settings from '@/services/settings'

const api = vi.hoisted(() => ({
	apiGet: vi.fn(() => Promise.resolve({ users: [{ userId: 'u' }] })),
	apiPost: vi.fn(() => Promise.resolve({})),
	apiPut: vi.fn(() => Promise.resolve({})),
	apiPatch: vi.fn(() => Promise.resolve({})),
	apiDelete: vi.fn(() => Promise.resolve({})),
}))
vi.mock('@/services/api', () => api)

describe('Schreibwege der Einstellungen', () => {
	beforeEach(() => vi.clearAllMocks())

	it.each([
		['createBoard', () => settings.createBoard({ title: 'P' }), 'apiPost', '/boards', { title: 'P' }],
		['createSiblingBoard', () => settings.createSiblingBoard(5, 'AG'), 'apiPost', '/boards/5/boards', { title: 'AG' }],
		['updateBoard', () => settings.updateBoard(5, { projectTitle: 'Neu' }), 'apiPatch', '/boards/5', { projectTitle: 'Neu' }],
		['setBoardArchived', () => settings.setBoardArchived(5, true), 'apiPut', '/boards/5/archived', { archived: true }],
		['setProjectArchived', () => settings.setProjectArchived(5, false), 'apiPut', '/boards/5/project-archived', { archived: false }],
		['deleteBoard', () => settings.deleteBoard(5), 'apiDelete', '/boards/5', undefined],
		['createColumn', () => settings.createColumn(5, 'S'), 'apiPost', '/boards/5/columns', { title: 'S', color: undefined }],
		['renameColumn', () => settings.renameColumn(5, 2, 'S'), 'apiPatch', '/boards/5/columns/2', { title: 'S' }],
		['setColumnFinalOutcome', () => settings.setColumnFinalOutcome(5, 2, 'done'), 'apiPatch', '/boards/5/columns/2/final-outcome', { finalOutcome: 'done' }],
		['reorderColumns', () => settings.reorderColumns(5, [2, 1]), 'apiPut', '/boards/5/columns/order', { columnIds: [2, 1] }],
		['deleteColumn', () => settings.deleteColumn(5, 2, 3), 'apiDelete', '/boards/5/columns/2', { targetColumnId: 3 }],
		['addMember', () => settings.addMember(5, { userId: 'a b', role: 'external' }), 'apiPost', '/boards/5/members', { userId: 'a b', role: 'external' }],
		['updateMember', () => settings.updateMember(5, 'a b', { role: 'internal' }), 'apiPatch', '/boards/5/members/a%20b', { role: 'internal' }],
		['memberRemovalImpact', () => settings.memberRemovalImpact(5, 'a b'), 'apiGet', '/boards/5/members/a%20b/removal-impact', undefined],
		['removeMember', () => settings.removeMember(5, 'a b'), 'apiDelete', '/boards/5/members/a%20b', undefined],
		['searchCandidates', () => settings.searchCandidates(5, 'ä b'), 'apiGet', '/boards/5/member-search?search=%C3%A4%20b', undefined],
	] as const)('%s ruft den richtigen Endpunkt', async (_name, call, method, path, body) => {
		await call()

		const calls = (api[method] as unknown as { mock: { calls: unknown[][] } }).mock.calls
		expect(calls).toHaveLength(1)
		expect(calls[0][0]).toBe(path)
		if (body !== undefined) {
			expect(calls[0][1]).toEqual(body)
		}
	})

	it('searchCandidates liefert die Konten aus der Antwort', async () => {
		expect(await settings.searchCandidates(5, 'x')).toEqual([{ userId: 'u' }])
	})
})
