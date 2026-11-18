/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type * as L10n from '@nextcloud/l10n'
import type { LicenseStatus } from '@/services/license'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import WerkPlusSettings from '@/components/WerkPlusSettings.vue'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal<typeof L10n>()),
	t: (_app: string, text: string, vars?: Record<string, string>) => text.replace(/\{(\w+)\}/g, (_m, k: string) => vars?.[k] ?? ''),
	n: (_app: string, _one: string, other: string, count: number, vars?: Record<string, string>) => other.replace('%n', String(count)).replace(/\{(\w+)\}/g, (_m, k: string) => vars?.[k] ?? ''),
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
		props: ['variant', 'disabled', 'href', 'target'],
		emits: ['click'],
		template: '<button class="stub-button" :disabled="disabled" :data-href="href" :data-target="target" @click="$emit(\'click\')"><slot /></button>',
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
		trial: false,
		licenseValidUntil: null,
		trialEnded: false,
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
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-11-20T12:00:00Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	function kaufLink(wrapper: Awaited<ReturnType<typeof laden>>) {
		return wrapper.findAll('.stub-button').find((b) => b.attributes('data-href') !== undefined)
	}

	it('ohne Schlüssel: Eintragen, Testen und Kaufen, kein Prüfen oder Entfernen', async () => {
		const wrapper = await laden(status())

		expect(knoepfe(wrapper)).toEqual(['Schlüssel prüfen und speichern', '30 Tage testen', 'WerkPlus kaufen'])
		expect(kaufLink(wrapper)?.attributes('data-href')).toBe('https://werkwolke.de/preis?app=projektwerk')
		expect(kaufLink(wrapper)?.attributes('data-target')).toBe('_blank')
		expect(wrapper.find('.stub-button').attributes('disabled')).toBeDefined()
		expect(wrapper.find('dl').exists()).toBe(false)
		expect(wrapper.text()).toContain('3f2a1b64-9c8d-4e7f-a1b2-c3d4e5f60718')
	})

	it('mit Lizenz: Tarif, Schlüsselhinweis und alle Knöpfe', async () => {
		const wrapper = await laden(aktiv({ licensedEmployees: 12 }))

		expect(wrapper.find('.stub-note--success').text()).toBe('WerkPlus ist aktiv.')
		expect(wrapper.find('dl').text()).toContain('…69B6')
		expect(wrapper.find('dl').text()).toContain('Lizenzierte Mitarbeitende12')
		expect(knoepfe(wrapper)).toEqual(['Speichern und prüfen', 'Jetzt prüfen', 'Schlüssel entfernen'])
	})

	it('gekaufter Schlüssel: Laufzeit aus licenseValidUntil, nie das Token-Ende, kein Kauf-Link', async () => {
		const wrapper = await laden(aktiv({ licenseValidUntil: '2027-11-18T00:00:00Z' }))

		const facts = wrapper.find('dl').text()
		expect(facts).toContain('Laufzeit bis' + new Date('2027-11-18T00:00:00Z').toLocaleDateString())
		expect(facts).not.toContain(new Date('2026-12-05T12:00:00Z').toLocaleDateString())
		expect(facts).not.toContain('Gültig bis')
		expect(kaufLink(wrapper)).toBeUndefined()
	})

	it('ohne licenseValidUntil keine Laufzeit-Zeile, auch nicht ersatzweise exp', async () => {
		const wrapper = await laden(aktiv())

		expect(wrapper.find('dl').text()).not.toContain('Laufzeit')
		expect(wrapper.find('dl').text()).not.toContain('Gültig bis')
	})

	it('Laufzeit vorbei, Token noch in der Karenz: Karenz-Ende', async () => {
		const wrapper = await laden(aktiv({ state: 'grace', licenseValidUntil: '2026-11-18T00:00:00Z', graceUntil: '2026-12-02T00:00:00Z' }))

		expect(wrapper.find('dl').text()).toContain('Laufzeit abgelaufen, Karenz bis ' + new Date('2026-12-02T00:00:00Z').toLocaleDateString())
	})

	it('Testzeitraum läuft: Tarif, Ende mit Resttagen, Jetzt kaufen und Hinweis zum eigenen Schlüssel', async () => {
		const wrapper = await laden(aktiv({ trial: true, licenseValidUntil: '2026-11-27T12:00:00Z' }))

		const facts = wrapper.find('dl').text()
		expect(facts).toContain('WerkPlus – Testzeitraum')
		expect(facts).toContain('Gültig bis' + new Date('2026-11-27T12:00:00Z').toLocaleDateString() + ' (noch 7 Tage)')
		expect(kaufLink(wrapper)?.text()).toBe('Jetzt kaufen')
		expect(wrapper.text()).toContain('er ersetzt den Testschlüssel')
	})

	it('Testzeitraum beendet: ein Hinweis statt zwei und Jetzt kaufen', async () => {
		const wrapper = await laden(status({
			keySet: true,
			keyHint: '…69B6',
			trialEnded: true,
			lastError: { code: 'KEY_EXPIRED', message: 'Die Lizenz ist abgelaufen.', at: null },
		}))

		expect(wrapper.findAll('.stub-note--warning').map((n) => n.text())).toEqual(['Testzeitraum beendet. ProjektWerk arbeitet im freien Umfang weiter, deine Daten bleiben erhalten.'])
		expect(kaufLink(wrapper)?.text()).toBe('Jetzt kaufen')
	})

	it('gekaufter Schlüssel, Token fehlt nur gerade oder ist ungültig: kein Kauf-Link', async () => {
		const ohneToken = await laden(status({ keySet: true, keyHint: '…69B6', lastError: { code: 'SERVER_ERROR', message: 'Fehler', at: null } }))
		expect(kaufLink(ohneToken)).toBeUndefined()

		const ungueltig = await laden(status({ keySet: true, keyHint: '…69B6', state: 'invalid' }))
		expect(kaufLink(ungueltig)).toBeUndefined()
	})

	it('abgelaufene gekaufte Lizenz: Jetzt kaufen', async () => {
		const abgelaufen = await laden(aktiv({ state: 'expired' }))
		expect(kaufLink(abgelaufen)?.text()).toBe('Jetzt kaufen')

		const gesperrt = await laden(status({ keySet: true, keyHint: '…69B6', lastError: { code: 'KEY_EXPIRED', message: 'abgelaufen', at: null } }))
		expect(kaufLink(gesperrt)?.text()).toBe('Jetzt kaufen')
	})

	it('Funktionen-Link zeigt auf die Funktionen-Adresse und öffnet ohne Referer', async () => {
		const wrapper = await laden(status())

		const link = wrapper.find('a[href="https://werkwolke.de/funktionen#projektwerk"]')
		expect(link.attributes('target')).toBe('_blank')
		expect(link.attributes('rel')).toBe('noopener noreferrer')
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
