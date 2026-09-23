/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Arbeitsschritte-Liste: Anlegen und Bearbeiten.
 *
 * Seit #308 legt die Eingabezeile **nur den Titel** an; Zuweisung, Frist,
 * Beschreibung und Ergebnis werden über den Stift des Schritts nachgetragen.
 *
 * Geprüft wird beim Bearbeiten vor allem, dass ein gewähltes Datum als
 * derselbe Tag beim Server ankommt. Der Picker liefert ein `Date`, der Server
 * will `JJJJ-MM-TT`, und der naheliegende Weg dorthin (`toISOString()`) rechnet
 * über UTC — östlich von Greenwich wird aus dem 11. der 10., weil Mitternacht
 * Ortszeit noch der Vortag in UTC ist. Die Frist stünde dann einen Tag zu früh
 * im Kalender, und niemand sähe warum.
 *
 * Dazu, dass der Titel im Bearbeiten-Modus änderbar ist (#308) — vorher ließ
 * sich ein Tippfehler nur durch Löschen und Neuanlegen beheben.
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
vi.mock('@nextcloud/vue/components/NcTextArea', () => ({
	default: {
		name: 'NcTextArea',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
	},
}))
vi.mock('@nextcloud/vue/components/NcAvatar', () => ({
	default: { name: 'NcAvatar', template: '<span />' },
}))
vi.mock('@nextcloud/vue/components/NcDateTimePicker', () => ({
	// Ein Platzhalter, der das Wesentliche der echten Komponente hat: Er gibt
	// ein `Date` auf **lokaler** Mitternacht heraus, so wie der native
	// Datumswähler auch. Genau daran hängt die Umrechnung, die hier geprüft
	// wird — ein Stub, der schon einen ISO-Tag lieferte, prüfte nichts.
	default: {
		name: 'NcDateTimePicker',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input type="date" class="pw-test-datum" @input="$emit(\'update:modelValue\', $event.target.value === \'\' ? null : new Date($event.target.value + \'T00:00\'))">',
	},
}))

vi.mock('@nextcloud/vue/components/NcSelectUsers', () => ({
	// Gibt ein Options-Objekt heraus wie die echte Komponente, nicht die blosse
	// Kennung: Genau an dieser Umformung haengt, ob `assignedUserId` ankommt.
	default: {
		name: 'NcSelectUsers',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<select class="pw-test-person" :value="modelValue?.id ?? \'\'" @change="$emit(\'update:modelValue\', $event.target.value === \'\' ? null : { id: $event.target.value, displayName: $event.target.value, user: $event.target.value })"><option value="" /><option v-for="o in options" :key="o.id" :value="o.id">{{ o.displayName }}</option></select>',
	},
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}))

const StepList = (await import('@/components/StepList.vue')).default

const MEMBERS: Member[] = [
	{ id: 1, boardId: 7, userId: 'anna', role: 'internal', isManager: true, displayName: 'Anna Ahrens', resolvedName: 'Anna Ahrens', company: 'cpcMomentum', addedBy: 'anna', addedAt: null },
	{ id: 2, boardId: 7, userId: 'carla', role: 'external', isManager: false, displayName: 'Carla Cordes', resolvedName: 'Carla Cordes', company: 'Kunde GmbH', addedBy: 'anna', addedAt: null },
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
		description: null,
		result: null,
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
 * Seit #308 hat die Zeile nur noch das Titelfeld.
 *
 * @param wrapper Die montierte Komponente.
 * @param titel Was in das Titelfeld getippt wird.
 */
async function fuelleZeile(
	wrapper: ReturnType<typeof mountList>,
	titel: string,
) {
	const zeile = wrapper.find('.pw-step--new')
	await zeile.find('input[type="text"]').setValue(titel)
}

/**
 * Die Bearbeitung einer bestehenden Schrittzeile oeffnen.
 *
 * Seit Variante C (#99) stehen Zuweisung und Frist dort als **Text**; die
 * Felder erscheinen erst auf Klick. Der Weg dorthin ist Teil dessen, was hier
 * geprueft wird — ein Test, der die Felder direkt anspraeche, liefe an der
 * Bedienung vorbei.
 *
 * @param wrapper Die montierte Komponente.
 */
async function oeffneZeile(wrapper: ReturnType<typeof mountList>) {
	const zeile = wrapper.find('.pw-step:not(.pw-step--new)')
	await zeile.find('.pw-step__rechts button').trigger('click')
	await wrapper.vm.$nextTick()
}

/**
 * @param wrapper Die montierte Komponente.
 */
async function klickeHinzufuegen(wrapper: ReturnType<typeof mountList>) {
	await wrapper.find('.pw-step__neu-plus').trigger('click')
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
	 * Seit #308 legt die Zeile nur den Titel an — kein Feld für Zuweisung,
	 * Frist oder Beschreibung mehr. Der Dienst bekommt genau `{ title }`; die
	 * übrigen Felder trägt der Server als `null`/Default nach, das Nachpflegen
	 * läuft über den Stift.
	 */
	it('legt einen Schritt mit nur einem Titel an', async () => {
		const wrapper = mountList()
		await fuelleZeile(wrapper, 'Nur ein Titel')
		await klickeHinzufuegen(wrapper)

		expect(createStep).toHaveBeenCalledWith(7, 42, { title: 'Nur ein Titel' })
	})

	/**
	 * Das Ergebnis (#247) wird beim Bearbeiten gepuffert und erst mit „Fertig"
	 * geschrieben — anders als Zuweisung und Frist, die sofort speichern.
	 */
	it('schreibt das Ergebnis beim Klick auf Fertig', async () => {
		const wrapper = mountList([mitFrist('2026-08-11')])
		await oeffneZeile(wrapper)

		await wrapper.find('.pw-step__felder-text textarea').setValue('Hetzner 12, IONOS 15, Empfehlung Hetzner')
		// Kein Schreiben, solange nur getippt wird.
		expect(updateStep).not.toHaveBeenCalled()

		// Während des Bearbeitens ist „Fertig" der einzige Knopf in der Zeile
		// (der Papierkorb ist ausgeblendet).
		await wrapper.find('.pw-step__rechts button').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(updateStep).toHaveBeenCalledWith(7, 5, { result: 'Hetzner 12, IONOS 15, Empfehlung Hetzner' })
	})

	/**
	 * Ein vorhandenes Ergebnis wird mehrzeilig angezeigt, außerhalb des
	 * Bearbeitens.
	 */
	it('zeigt ein vorhandenes Ergebnis an', async () => {
		const schritt = mitFrist('2026-08-11')
		schritt.result = 'Zeile eins\nZeile zwei'
		const wrapper = mountList([schritt])

		const text = wrapper.find('.pw-step__ergebnis-text')
		expect(text.exists()).toBe(true)
		expect(text.text()).toContain('Zeile eins')
	})

	it('leert die Zeile nach dem Anlegen', async () => {
		const wrapper = mountList()
		await wrapper.vm.$nextTick()
		await fuelleZeile(wrapper, 'Kurz')
		await klickeHinzufuegen(wrapper)

		const zeile = wrapper.find('.pw-step--new')
		expect((zeile.find('input[type="text"]').element as HTMLInputElement).value).toBe('')
	})

	/**
	 * **Der Titel ist im Bearbeiten-Modus änderbar** (#308).
	 *
	 * Vorher gab es den Titel nur als Anzeige-`<span>`; ein Tippfehler ließ sich
	 * nur durch Löschen und Neuanlegen beheben. Jetzt steht im Stift ein
	 * Titelfeld, das gepuffert über „Fertig" speichert — wie Beschreibung und
	 * Ergebnis. Das Titelfeld ist das erste Textfeld im `.pw-step__felder-text`.
	 */
	it('macht den Titel im Bearbeiten-Modus änderbar', async () => {
		const wrapper = mountList([mitFrist('2026-08-11')])
		await oeffneZeile(wrapper)

		const titel = wrapper.findAll('.pw-step__felder-text input[type="text"]')[0]
		await titel.setValue('Mit Frist korrigiert')
		await wrapper.find('.pw-step__rechts button').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(updateStep).toHaveBeenCalledWith(7, 5, { title: 'Mit Frist korrigiert' })
	})

	/**
	 * Ein leergeräumtes Titelfeld wird ignoriert — der Titel ist Pflicht (#308).
	 * Getippt und wieder gelöscht darf den Schritt nicht namenlos machen.
	 */
	it('ignoriert einen leergeräumten Titel', async () => {
		const wrapper = mountList([mitFrist('2026-08-11')])
		await oeffneZeile(wrapper)

		const titel = wrapper.findAll('.pw-step__felder-text input[type="text"]')[0]
		await titel.setValue('   ')
		await wrapper.find('.pw-step__rechts button').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(updateStep).not.toHaveBeenCalled()
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
		await oeffneZeile(wrapper)

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
		await oeffneZeile(wrapper)

		await wrapper.find('.pw-step .pw-test-datum').setValue('')
		await wrapper.vm.$nextTick()

		expect(updateStep).toHaveBeenCalledWith(7, 5, { dueDate: null })
	})
})
