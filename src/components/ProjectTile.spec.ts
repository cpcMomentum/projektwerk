/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die geteilte Projekt-Kachel (#276).
 *
 * Geprüft wird, was beide Nutzer der Kachel auseinanderhält: der Pin ist im
 * Verzeichnis ein anklickbarer Toggle (der `togglePin` meldet, ohne die Kachel
 * zu öffnen), im Überblick reine Anzeige. Und der Leerfall — ein Projekt ohne
 * Vorgänge — trägt statt eines leeren Balkens ein Wort.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ProjectTile from '@/components/ProjectTile.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars?: Record<string, string>) => vars ? text.replace(/\{(\w+)\}/g, (_m, k) => vars[k] ?? '') : text,
	n: (_app: string, _sing: string, plural: string, count: number) => plural.replace('%n', String(count)),
}))

const basis = {
	boardId: 7,
	title: 'Relaunch Website',
	org: 'cpcMomentum · Müller Elektrotechnik',
	neu: 2,
	offen: 5,
	wartet: 1,
	erledigt: 8,
	neuDieseWoche: 0,
	zustand: 'gruen' as const,
}

const stubs = {
	StarIcon: { name: 'StarIcon', template: '<span class="star" />' },
	StarOutlineIcon: { name: 'StarOutlineIcon', template: '<span class="star-outline" />' },
}

describe('ProjectTile', () => {
	it('zeigt Titel, Firmenzeile und je Segment mit Wert eine Zahl + Balkenteil', () => {
		const w = mount(ProjectTile, { props: basis, global: { stubs } })

		expect(w.text()).toContain('Relaunch Website')
		expect(w.text()).toContain('cpcMomentum · Müller Elektrotechnik')
		// neu/offen/wartet/erledigt sind alle > 0 → vier Segmente in Zahlen und Balken.
		expect(w.findAll('.pw-tile__num')).toHaveLength(4)
		expect(w.findAll('.pw-tile__seg')).toHaveLength(4)
		expect(w.find('.pw-tile__empty').exists()).toBe(false)
	})

	it('lässt Nullsegmente ganz weg', () => {
		const w = mount(ProjectTile, {
			props: { ...basis, neu: 0, wartet: 0 },
			global: { stubs },
		})

		// Nur offen und erledigt haben Werte.
		expect(w.findAll('.pw-tile__seg')).toHaveLength(2)
	})

	it('zeigt beim leeren Projekt einen Hinweis statt eines Balkens', () => {
		const w = mount(ProjectTile, {
			props: { ...basis, neu: 0, offen: 0, wartet: 0, erledigt: 0 },
			global: { stubs },
		})

		expect(w.find('.pw-tile__bar').exists()).toBe(false)
		expect(w.find('.pw-tile__empty').exists()).toBe(true)
		expect(w.find('.pw-tile__empty').text()).toBe('Noch keine Vorgänge')
	})

	it('öffnet die Kachel per Klick und per Tastatur', async () => {
		const w = mount(ProjectTile, { props: basis, global: { stubs } })

		await w.find('.pw-tile').trigger('click')
		await w.find('.pw-tile').trigger('keydown.enter')

		expect(w.emitted('open')).toEqual([[7], [7]])
	})

	describe('Pin', () => {
		it('zeigt im Verzeichnis einen Toggle, der togglePin meldet — ohne zu öffnen', async () => {
			const w = mount(ProjectTile, {
				props: { ...basis, pinnable: true, pinned: false },
				global: { stubs },
			})

			const toggle = w.find('.pw-tile__pinbtn')
			expect(toggle.exists()).toBe(true)
			// Nicht angepinnt → Umriss-Stern.
			expect(toggle.find('.star-outline').exists()).toBe(true)

			await toggle.trigger('click')
			expect(w.emitted('togglePin')).toEqual([[7]])
			// Der Klick auf den Toggle darf die Kachel nicht öffnen (@click.stop).
			expect(w.emitted('open')).toBeUndefined()
		})

		it('färbt den Toggle golden und füllt den Stern, wenn angepinnt', () => {
			const w = mount(ProjectTile, {
				props: { ...basis, pinnable: true, pinned: true },
				global: { stubs },
			})

			const toggle = w.find('.pw-tile__pinbtn')
			expect(toggle.classes()).toContain('pw-tile__pinbtn--on')
			expect(toggle.find('.star').exists()).toBe(true)
			expect(toggle.attributes('aria-pressed')).toBe('true')
		})

		it('zeigt im Überblick keinen Toggle, den Stern nur zur Anzeige und nur wenn angepinnt', () => {
			const aus = mount(ProjectTile, {
				props: { ...basis, pinnable: false, pinned: false },
				global: { stubs },
			})
			expect(aus.find('.pw-tile__pinbtn').exists()).toBe(false)
			expect(aus.find('.pw-tile__pin').exists()).toBe(false)

			const an = mount(ProjectTile, {
				props: { ...basis, pinnable: false, pinned: true },
				global: { stubs },
			})
			expect(an.find('.pw-tile__pinbtn').exists()).toBe(false)
			expect(an.find('.pw-tile__pin').exists()).toBe(true)
		})
	})
})
