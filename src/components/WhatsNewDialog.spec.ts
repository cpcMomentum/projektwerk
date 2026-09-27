/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * „Was ist neu?"-Fenster (#315): Das Fenster darf nur erscheinen, wenn es
 * wirklich etwas zu berichten gibt, und es darf die App nie blockieren.
 */

import type { WhatsNewArchive, WhatsNewEntry, WhatsNewPayload } from '@/types/whatsnew'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const getWhatsNew = vi.fn<() => Promise<WhatsNewPayload>>()
const getWhatsNewArchive = vi.fn<() => Promise<WhatsNewArchive>>()
const markWhatsNewSeen = vi.fn<() => Promise<void>>()

vi.mock('@/services/whatsnew', () => ({
	getWhatsNew: () => getWhatsNew(),
	getWhatsNewArchive: () => getWhatsNewArchive(),
	markWhatsNewSeen: () => markWhatsNewSeen(),
}))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars?: Record<string, string>) => vars ? text.replace(/\{(\w+)\}/g, (_m, k) => vars[k] ?? '') : text,
}))

// Die echten Komponenten ziehen ihr CSS mit, das der Test-Runner nicht laedt.
vi.mock('@nextcloud/vue/components/NcModal', () => ({
	default: {
		name: 'NcModal',
		// Bewusst `labelId` statt `name`: NcModal baut aus `name` eine eigene
		// Kopfzeile, die am oberen Bildschirmrand schwebt und dort die
		// Nextcloud-Leiste ueberdeckt (#315).
		props: ['labelId', 'name'],
		emits: ['close'],
		template: '<div class="stub-modal"><slot /></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant'],
		emits: ['click'],
		template: '<button class="stub-button" @click="$emit(\'click\')"><slot /></button>',
	},
}))

import WhatsNewDialog from './WhatsNewDialog.vue'

function eintrag(teil: Partial<WhatsNewEntry>): WhatsNewEntry {
	return {
		title: 'Firma an der Person',
		text: 'Kunde am Projekt.',
		icon: 'account-group',
		where: '',
		adminOnly: false,
		plus: false,
		...teil,
	}
}

function payload(entries: WhatsNewPayload['entries']): WhatsNewPayload {
	return {
		version: '0.4.16',
		entries,
	}
}

describe('WhatsNewDialog', () => {
	beforeEach(() => {
		getWhatsNew.mockReset()
		getWhatsNewArchive.mockReset()
		markWhatsNewSeen.mockReset()
		markWhatsNewSeen.mockResolvedValue(undefined)
	})

	it('zeigt kein Fenster, wenn es nichts zu berichten gibt', async () => {
		getWhatsNew.mockResolvedValue(payload([]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		expect(wrapper.find('.stub-modal').exists()).toBe(false)
	})

	it('zeigt Titel und Text der Eintraege', async () => {
		getWhatsNew.mockResolvedValue(payload([
			eintrag({ title: 'Firma an der Person', text: 'Kunde am Projekt.', plus: false }),
			eintrag({ title: 'Personensuche', text: 'Zeigt die E-Mail.', plus: false }),
		]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		expect(wrapper.find('.stub-modal').exists()).toBe(true)
		expect(wrapper.text()).toContain('Firma an der Person')
		expect(wrapper.text()).toContain('Zeigt die E-Mail.')
		expect(wrapper.findAll('.whatsnew__entry')).toHaveLength(2)
	})

	it('quittiert beim Schliessen und schliesst das Fenster', async () => {
		getWhatsNew.mockResolvedValue(payload([
			eintrag({ title: 'Firma an der Person', text: 'Kunde am Projekt.', plus: false }),
		]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		await wrapper.find('.stub-button').trigger('click')
		await flushPromises()

		expect(markWhatsNewSeen).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.stub-modal').exists()).toBe(false)
	})

	it('zeigt Badge und Link nur bei WerkPlus-Eintraegen', async () => {
		getWhatsNew.mockResolvedValue(payload([
			eintrag({ title: 'WerkPlus ist da', text: 'Alles zusammen.', plus: true }),
			eintrag({ title: 'Firma an der Person', text: 'Kunde am Projekt.', plus: false }),
		]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		const badges = wrapper.findAll('.whatsnew__badge')
		expect(badges).toHaveLength(1)
		expect(badges[0].text()).toBe('WerkPlus')

		const links = wrapper.findAll('.whatsnew__link')
		expect(links).toHaveLength(1)
		expect(links[0].attributes('href')).toBe('https://werkwolke.de')
		expect(links[0].attributes('rel')).toContain('noopener')
	})

	it('zeigt die Fundort-Zeile nur, wenn ein Ort angegeben ist', async () => {
		getWhatsNew.mockResolvedValue(payload([
			eintrag({ title: 'Mit Ort', where: 'Projekte' }),
			eintrag({ title: 'Ohne Ort', where: '' }),
		]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		const zeilen = wrapper.findAll('.whatsnew__where')
		expect(zeilen).toHaveLength(1)
		expect(zeilen[0].text()).toContain('Projekte')
	})

	it('schreibt bei adminpflichtigen Stellen den Hinweis dazu', async () => {
		getWhatsNew.mockResolvedValue(payload([
			eintrag({ where: 'Einstellungen', adminOnly: true }),
		]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		expect(wrapper.find('.whatsnew__where').text()).toContain('Administratoren')
	})

	it('zeigt zu jedem Eintrag ein Symbol, auch bei unbekanntem Namen', async () => {
		getWhatsNew.mockResolvedValue(payload([
			eintrag({ title: 'Bekannt', icon: 'folder' }),
			eintrag({ title: 'Unbekannt', icon: 'gibt-es-nicht' }),
			eintrag({ title: 'Leer', icon: '' }),
		]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		const symbole = wrapper.findAll('.whatsnew__icon')
		expect(symbole).toHaveLength(3)
		for (const symbol of symbole) {
			expect(symbol.find('svg').exists()).toBe(true)
		}
	})

	it('beschriftet NcModal ueber die eigene Ueberschrift, nicht ueber name', async () => {
		getWhatsNew.mockResolvedValue(payload([eintrag({})]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		const modal = wrapper.findComponent({ name: 'NcModal' })
		expect(modal.props('name')).toBeUndefined()
		expect(modal.props('labelId')).toBe('whatsnew-title')
		expect(wrapper.find('h2').attributes('id')).toBe('whatsnew-title')
	})

	it('quittiert auch beim Schliessen ueber X oder Escape', async () => {
		getWhatsNew.mockResolvedValue(payload([eintrag({})]))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		// NcModal meldet X, Escape und den Klick daneben ueber dasselbe Ereignis.
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		await flushPromises()

		expect(markWhatsNewSeen).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.stub-modal').exists()).toBe(false)
	})

	it('bleibt still, wenn der Abruf scheitert', async () => {
		getWhatsNew.mockRejectedValue(new Error('offline'))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		expect(wrapper.find('.stub-modal').exists()).toBe(false)
	})

	it('schliesst auch dann, wenn die Quittung scheitert', async () => {
		getWhatsNew.mockResolvedValue(payload([
			eintrag({ title: 'Firma an der Person', text: 'Kunde am Projekt.', plus: false }),
		]))
		markWhatsNewSeen.mockRejectedValue(new Error('offline'))

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()

		await wrapper.find('.stub-button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.stub-modal').exists()).toBe(false)
	})

	/**
	 * Archiv-Modus (#329): über `openArchive` geöffnet, zeigt ALLE Versionen,
	 * nach Version gruppiert. Das Auto-Popup bleibt davon unberührt.
	 */
	it('öffnet im Archiv-Modus alle Versionen, ohne das Auto-Popup', async () => {
		getWhatsNew.mockResolvedValue(payload([])) // Popup zeigt nichts
		getWhatsNewArchive.mockResolvedValue({
			versions: [
				{ version: '0.4.18', entries: [eintrag({ title: 'Neuer Punkt' })] },
				{ version: '0.4.16', entries: [eintrag({ title: 'Alter Punkt' }), eintrag({ title: 'Noch älter' })] },
			],
		})

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()
		// Ohne Aufruf bleibt es zu (Popup hatte nichts).
		expect(wrapper.find('.stub-modal').exists()).toBe(false)

		await (wrapper.vm as unknown as { openArchive: () => Promise<void> }).openArchive()
		await flushPromises()

		expect(wrapper.find('.stub-modal').exists()).toBe(true)
		expect(wrapper.findAll('.whatsnew__group')).toHaveLength(2)
		expect(wrapper.findAll('.whatsnew__entry')).toHaveLength(3)
	})

	/**
	 * Nachlesen ist kein Quittieren: Der Archiv-Modus darf die Marke NICHT
	 * setzen, sonst verschluckte das Menü ein noch offenes Popup.
	 */
	it('quittiert im Archiv-Modus nicht beim Schließen', async () => {
		getWhatsNew.mockResolvedValue(payload([]))
		getWhatsNewArchive.mockResolvedValue({
			versions: [{ version: '0.4.16', entries: [eintrag({})] }],
		})

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()
		await (wrapper.vm as unknown as { openArchive: () => Promise<void> }).openArchive()
		await flushPromises()

		await wrapper.find('.stub-button').trigger('click')
		await flushPromises()

		expect(markWhatsNewSeen).not.toHaveBeenCalled()
		expect(wrapper.find('.stub-modal').exists()).toBe(false)
	})

	it('zeigt einen Leer-Hinweis, wenn das Archiv nichts enthält (z. B. Gast)', async () => {
		getWhatsNew.mockResolvedValue(payload([]))
		getWhatsNewArchive.mockResolvedValue({ versions: [] })

		const wrapper = mount(WhatsNewDialog)
		await flushPromises()
		await (wrapper.vm as unknown as { openArchive: () => Promise<void> }).openArchive()
		await flushPromises()

		expect(wrapper.find('.stub-modal').exists()).toBe(true)
		expect(wrapper.find('.whatsnew__empty').exists()).toBe(true)
		expect(wrapper.findAll('.whatsnew__entry')).toHaveLength(0)
	})
})
