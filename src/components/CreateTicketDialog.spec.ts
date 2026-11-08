/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Column } from '@/types/board'

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import CreateTicketDialog from '@/components/CreateTicketDialog.vue'

vi.mock(import('@nextcloud/l10n'), async (importOriginal) => ({
	...(await importOriginal()),
	t: (_app: string, text: string) => text,
}))

vi.mock('@/services/steps', () => ({
	fetchAssignableForNew: vi.fn(async () => []),
}))

const spalten = [
	{ id: 1, title: 'Eingegangen', finalOutcome: null },
	{ id: 2, title: 'Eingeplant', finalOutcome: null },
	{ id: 3, title: 'Erledigt', finalOutcome: 'done' },
] as unknown as Column[]

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { template: '<button><slot /></button>' },
	NcDateTimePicker: true,
	NcSelectUsers: true,
	VisibilityChoice: true,
}

async function oeffne(initialColumnId: number | null): Promise<number> {
	const w = mount(CreateTicketDialog, {
		props: { open: false, boardId: 7, columns: spalten, initialColumnId },
		global: { stubs },
	})
	await w.setProps({ open: true } as Record<string, unknown>)

	return Number((w.find('#pw-new-col').element as HTMLSelectElement).value)
}

describe('CreateTicketDialog', () => {
	it('stellt die Spalte vom Plus im Spaltenkopf ein', async () => {
		expect(await oeffne(2)).toBe(2)
	})

	it('nimmt ohne Vorgabe die erste Spalte', async () => {
		expect(await oeffne(null)).toBe(1)
	})

	it('fällt bei einer unbekannten Spalte auf die erste zurück', async () => {
		expect(await oeffne(99)).toBe(1)
	})
})
