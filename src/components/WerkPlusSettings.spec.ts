/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type * as L10n from '@nextcloud/l10n'
import type { LicenseStatus } from '@/services/license'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import WerkPlusSettings from '@/components/WerkPlusSettings.vue'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal<typeof L10n>()),
	t: (_app: string, text: string, vars?: Record<string, string>) => text.replace(/\{(\w+)\}/g, (_m, k: string) => vars?.[k] ?? ''),
	n: (_app: string, _one: string, other: string, count: number) => other.replace('%n', String(count)),
}))

const getLicense = vi.fn<() => Promise<LicenseStatus>>()
const activateLicense = vi.fn<(key: string) => Promise<LicenseStatus>>()
const startTrial = vi.fn<() => Promise<{ key: string, status: LicenseStatus }>>()

vi.mock('@/services/license', () => ({
	getLicense: () => getLicense(),
	activateLicense: (key: string) => activateLicense(key),
	refreshLicense: vi.fn(),
	removeLicense: vi.fn(),
	startTrial: () => startTrial(),
}))

const stubs = {
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button class="stub-button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type', 'text'],
		template: '<div :class="[\'stub-note\', \'stub-note--\' + type]"><slot>{{ text }}</slot></div>',
	},
	NcTextField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input class="stub-key" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcDialog: {
		props: ['open', 'name'],
		template: '<div v-if="open" class="stub-dialog"><slot /><slot name="actions" /></div>',
	},
}

function status(teil: Partial<LicenseStatus> = {}): LicenseStatus {
	return {
		instanceUuid: '3f2a1b64-9c8d-4e7f-a1b2-c3d4e5f60718',
		keySet: false,
		keyHint: null,
		licensedEmployees: null,
		state: 'none',
		tier: 'free',
		features: [],
		expiresAt: null,
		graceUntil: null,
		lastCheckAt: null,
		lastError: null,
		storeOutdated: false,
		...teil,
	}
}

function aktiv(teil: Partial<LicenseStatus> = {}): LicenseStatus {
	return status({
		keySet: true,
		keyHint: '…69B6',
		state: 'valid',
		tier: 'werkplus',
		features: ['pw_projects'],
		expiresAt: '2026-12-05T12:00:00Z',
		graceUntil: '2026-12-19T12:00:00Z',
		lastCheckAt: '2026-11-06T12:00:00Z',
		...teil,
	})
}

async function laden(s: LicenseStatus) {
	getLicense.mockResolvedValue(s)
	const wrapper = mount(WerkPlusSettings, { global: { stubs } })
	await flushPromises()
	return wrapper
}

function knoepfe(wrapper: Awaited<ReturnType<typeof laden>>): string[] {
	return wrapper.findAll('.stub-button').filter((b) => !b.element.closest('.stub-dialog')).map((b) => b.text())
}

describe('WerkPlusSettings', () => {
	beforeEach(() => {
		getLicense.mockReset()
		activateLicense.mockReset()
		startTrial.mockReset()
	})

	it('ohne Schlüssel: Eintragen und Testen, kein Prüfen oder Entfernen', async () => {
		const wrapper = await laden(status())

		expect(knoepfe(wrapper)).toEqual(['Schlüssel prüfen und speichern', '30 Tage testen'])
		expect(wrapper.find('.stub-button').attributes('disabled')).toBeDefined()
		expect(wrapper.find('dl').exists()).toBe(false)
		expect(wrapper.text()).toContain('3f2a1b64-9c8d-4e7f-a1b2-c3d4e5f60718')
	})

	it('mit Lizenz: Tarif, Schlüsselhinweis und alle Knöpfe', async () => {
		const wrapper = await laden(aktiv({ licensedEmployees: 12 }))

		expect(wrapper.find('.stub-note--success').text()).toBe('WerkPlus ist aktiv.')
		expect(wrapper.find('dl').text()).toContain('…69B6')
		expect(wrapper.find('dl').text()).toContain('12 Mitarbeitende')
		expect(knoepfe(wrapper)).toEqual(['Speichern und prüfen', 'Jetzt prüfen', 'Schlüssel entfernen'])
	})

	it('eine neuere Lizenzablage: Hinweis auf ein Update, nichts lässt sich ändern', async () => {
		const wrapper = await laden(aktiv({ storeOutdated: true }))

		expect(wrapper.find('.stub-note--warning').text()).toContain('Bitte aktualisiere ProjektWerk.')
		expect(knoepfe(wrapper)).toEqual([])
		expect(wrapper.find('.stub-key').exists()).toBe(false)
		expect(wrapper.find('dl').text()).toContain('…69B6')
	})

	it('in der Nachfrist: Warnung statt Fehler', async () => {
		const wrapper = await laden(aktiv({ state: 'grace' }))

		expect(wrapper.find('.stub-note--warning').text()).toContain('Die Funktionen bleiben bis')
	})

	it('ein schon genutzter Testzeitraum ist ein Hinweis, kein Fehler', async () => {
		const wrapper = await laden(status())
		startTrial.mockRejectedValue({ message: 'Schon genutzt.', data: { code: 'TRIAL_ALREADY_USED' } })

		await wrapper.findAll('.stub-button')[1].trigger('click')
		await wrapper.find('.stub-dialog .stub-button:last-child').trigger('click')
		await flushPromises()

		expect(wrapper.find('.stub-note--info').text()).toBe('Schon genutzt.')
		expect(wrapper.find('.stub-note--error').exists()).toBe(false)
	})

	it('der Testschlüssel steht einmal da und meldet die Änderung', async () => {
		const wrapper = await laden(status())
		startTrial.mockResolvedValue({ key: 'wp_NEU', status: aktiv() })

		await wrapper.findAll('.stub-button')[1].trigger('click')
		await wrapper.find('.stub-dialog .stub-button:last-child').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('wp_NEU')
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('ein abgelehnter Schlüssel zeigt die Servermeldung und bleibt im Feld', async () => {
		const wrapper = await laden(status())
		activateLicense.mockRejectedValue({ message: 'Dieser Schlüssel ist nicht bekannt.', data: { code: 'KEY_UNKNOWN' } })

		await wrapper.find('.stub-key').setValue('wp_FALSCH')
		await wrapper.find('.stub-button').trigger('click')
		await flushPromises()

		expect(activateLicense).toHaveBeenCalledWith('wp_FALSCH')
		expect(wrapper.find('.stub-note--error').text()).toBe('Dieser Schlüssel ist nicht bekannt.')
		expect((wrapper.find('.stub-key').element as HTMLInputElement).value).toBe('wp_FALSCH')
	})
})
