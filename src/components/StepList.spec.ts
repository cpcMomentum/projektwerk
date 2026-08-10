/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Eingabezeile der Arbeitsschritte.
 *
 * Geprüft wird hier vor allem **eine** Sache: dass ein gewähltes Datum als
 * derselbe Tag beim Server ankommt. Der Picker liefert ein `Date`, der Server
 * will `JJJJ-MM-TT`, und der naheliegende Weg dorthin (`toISOString()`) rechnet
 * über UTC — östlich von Greenwich wird aus dem 11. der 10., weil Mitternacht
 * Ortszeit noch der Vortag in UTC ist. Die Frist stünde dann einen Tag zu früh
 * im Kalender, und niemand sähe warum.
 *
 * Dazu die zweite Zusage aus #86: Der schnelle Weg bleibt unbelastet. Wer nur
 * tippt und Enter drückt, schickt keine Zuweisung und keine Frist mit.
 */

import type { Member } from '@/types/board'
import type { Step } from '@/types/ticket'

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const createStep = vi.fn()
const updateStep = vi.fn()
const fetchAssignable = vi.fn()
const showError = vi.fn()

vi.mock('@/services/steps', () => ({
	createStep: (...args: unknown[]) => createStep(...args),
	updateStep: (...args: unknown[]) => updateStep(...args),
	fetchAssignable: (...args: unknown[]) => fetchAssignable(...args),
}))
vi.mock('@/services/toast', () => ({
	showError: (...args: unknown[]) => showError(...args),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		name: 'NcTextField',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input type="text" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}))
vi.mock('@nextcloud/vue/components/NcAvatar', () => ({
	default: { name: 'NcAvatar', template: '<span />' },
}))
vi.mock('@nextcloud/vue/components/NcDateTimePickerNative', () => ({
	// Ein Platzhalter, der das Wesentliche der echten Komponente hat: Er gibt
	// ein `Date` auf **lokaler** Mitternacht heraus, so wie der native
	// Datumswähler auch. Genau daran hängt die Umrechnung, die hier geprüft
	// wird — ein Stub, der schon einen ISO-Tag lieferte, prüfte nichts.
	default: {
		name: 'NcDateTimePickerNative',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input type="date" class="pw-test-datum" @input="$emit(\'update:modelValue\', $event.target.value === \'\' ? null : new Date($event.target.value + \'T00:00\'))">',
	},
}))

const StepList = (await import('@/components/StepList.vue')).default

const MEMBERS: Member[] = [
	{ id: 1, boardId: 7, userId: 'anna', role: 'internal', isManager: true, displayName: 'Anna Ahrens', resolvedName: 'Anna Ahrens', addedBy: 'anna', addedAt: null },
	{ id: 2, boardId: 7, userId: 'carla', role: 'external', isManager: false, displayName: 'Carla Cordes', resolvedName: 'Carla Cordes', addedBy: 'anna', addedAt: null },
]

/**
 * Ein bestehender Schritt mit Frist.
 *
 * @param dueDate Die Frist als JJJJ-MM-TT.
 */
function mitFrist(dueDate: string): Step {
	return {
		id: 5,
		ticketId: 42,
		title: 'Mit Frist',
		assignedUserId: null,
		assignedRole: null,
		assignedAt: null,
		done: false,
		doneAt: null,
		dueDate,
		position: 0,
		createdAt: null,
	}
}

/**
 * @param steps Die vorhandenen Schritte.
 */
function mountList(steps: Step[] = []) {
	return mount(StepList, {
		props: { boardId: 7, ticketId: 42, steps, members: MEMBERS },
	})
}

/**
 * Die Eingabezeile ausfüllen — über das DOM, wie ein Mensch es täte.
 *
 * Nicht über `wrapper.vm`: Die Datenfelder einer Options-API-Komponente sind
 * am öffentlichen Instanztyp nicht sichtbar, und ein Test, der sie trotzdem
 * anfasst, prüft an der Vorlage vorbei — genau dort sitzt aber die Verdrahtung.
 *
 * @param wrapper Die montierte Komponente.
 * @param titel Was in das Titelfeld getippt wird.
 * @param person Kennung der zuständigen Person, leer für „Niemand".
 * @param datum Die Frist als JJJJ-MM-TT, leer für keine.
 */
async function fuelleZeile(
	wrapper: ReturnType<typeof mountList>,
	titel: string,
	person = '',
	datum = '',
) {
	const zeile = wrapper.find('.pw-step--new')
	await zeile.find('input[type="text"]').setValue(titel)

	if (person !== '') {
		await zeile.find('select').setValue(person)
	}
	if (datum !== '') {
		await zeile.find('.pw-test-datum').setValue(datum)
	}
}

/**
 * @param wrapper Die montierte Komponente.
 */
async function klickeHinzufuegen(wrapper: ReturnType<typeof mountList>) {
	await wrapper.findAll('button').find((b) => b.text() === 'Hinzufügen')?.trigger('click')
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
}

beforeEach(() => {
	vi.clearAllMocks()
	fetchAssignable.mockResolvedValue(['anna', 'carla'])
	createStep.mockResolvedValue({})
	updateStep.mockResolvedValue({})
})

describe('StepList', () => {
	/**
	 * **Der Tag, der im Feld stand, muss beim Server ankommen.**
	 *
	 * `new Date(2026, 7, 11)` ist der 11. August, Ortszeit. `toISOString()`
	 * machte daraus in Mitteleuropa `2026-08-10T22:00:00Z` und damit den 10. —
	 * eine Frist einen Tag zu früh. Der Test läuft in der Zeitzone des Rechners
	 * und deckt den Fehler überall dort auf, wo der Versatz nicht null ist.
	 */
	it('schickt das gewählte Datum als denselben Tag, nicht als UTC-Vortag', async () => {
		const wrapper = mountList()
		await fuelleZeile(wrapper, 'Logo liefern', '', '2026-08-11')
		await klickeHinzufuegen(wrapper)

		expect(createStep).toHaveBeenCalledWith(7, 42, expect.objectContaining({
			title: 'Logo liefern',
			dueDate: '2026-08-11',
		}))
	})

	it('nimmt die Zuweisung gleich beim Anlegen mit', async () => {
		const wrapper = mountList()
		await wrapper.vm.$nextTick()
		await fuelleZeile(wrapper, 'Freigabe holen', 'carla')
		await klickeHinzufuegen(wrapper)

		expect(createStep).toHaveBeenCalledWith(7, 42, expect.objectContaining({
			assignedUserId: 'carla',
		}))
	})

	/**
	 * Der schnelle Weg bleibt unbelastet: tippen, absenden, fertig.
	 *
	 * `null` und nicht „weggelassen": Der Dienst unterscheidet beides, und
	 * gemeint ist „keine Zuweisung", nicht „nicht genannt".
	 */
	it('schickt ohne Auswahl weder Zuweisung noch Frist', async () => {
		const wrapper = mountList()
		await fuelleZeile(wrapper, 'Nur ein Titel')
		await klickeHinzufuegen(wrapper)

		expect(createStep).toHaveBeenCalledWith(7, 42, {
			title: 'Nur ein Titel',
			assignedUserId: null,
			dueDate: null,
		})
	})

	it('leert die Zeile nach dem Anlegen', async () => {
		const wrapper = mountList()
		await wrapper.vm.$nextTick()
		await fuelleZeile(wrapper, 'Kurz', 'anna', '2026-08-11')
		await klickeHinzufuegen(wrapper)

		const zeile = wrapper.find('.pw-step--new')
		expect((zeile.find('input[type="text"]').element as HTMLInputElement).value).toBe('')
		expect((zeile.find('select').element as HTMLSelectElement).value).toBe('')
	})

	/**
	 * Ein Datum vom Server geht unverändert wieder hinaus.
	 *
	 * Beide Richtungen der Umrechnung in einem Zug: Die Anzeige liest
	 * `2026-08-11` als lokalen Tag, das Schreiben macht denselben Tag daraus.
	 * Verschöbe eine der beiden, wäre das Ergebnis ein anderer Tag — und der
	 * Schritt schriebe bei jeder Berührung eine neue Frist.
	 */
	it('schreibt nicht, wenn dasselbe Datum noch einmal gesetzt wird', async () => {
		const wrapper = mountList([mitFrist('2026-08-11')])

		await wrapper.find('.pw-step .pw-test-datum').setValue('2026-08-11')
		await wrapper.vm.$nextTick()

		expect(updateStep).not.toHaveBeenCalled()
	})

	/**
	 * **Das Feld leeren muss die Frist löschen.**
	 *
	 * Genau hier war der Fehler, den #86 aufgedeckt hat — allerdings eine
	 * Schicht tiefer, im Controller. Diese Zeile hält die Browser-Seite fest:
	 * Ein geleertes Feld schickt ausdrücklich `null` und nicht gar nichts.
	 */
	it('löscht die Frist, wenn das Feld geleert wird', async () => {
		const wrapper = mountList([mitFrist('2026-08-11')])

		await wrapper.find('.pw-step .pw-test-datum').setValue('')
		await wrapper.vm.$nextTick()

		expect(updateStep).toHaveBeenCalledWith(7, 5, { dueDate: null })
	})
})
