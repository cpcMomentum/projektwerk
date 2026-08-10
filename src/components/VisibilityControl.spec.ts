/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Bedienung zum Ändern der Sichtbarkeit.
 *
 * Geprüft wird hier **eine** Frage, und zwar die, an der die Bauform hängt:
 * Fragt das Frontend zurück, weil der Server `losing` gefüllt hat — oder weil
 * es selbst entschieden hat, dass „internal" unter „public" liegt? Der zweite
 * Fall wäre die Sichtbarkeitsregel in zweiter Fassung, und die zweite Fassung
 * ist der Anfang jedes Lecks.
 *
 * Die Tests stellen deshalb Antworten des Servers, die der Richtung
 * widersprechen: Ein Wechsel public → internal ohne Verlierer geht **ohne**
 * Rückfrage durch, ein Wechsel internal → public mit Verlierern **mit**. Wer
 * die Rangfolge nachbaut, fällt bei beiden durch.
 */

import type { Member, ViewerInfo, Visibility } from '@/types/board'
import type { Ticket } from '@/types/ticket'

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchVisibilityImpact = vi.fn()
const changeVisibility = vi.fn()
const showError = vi.fn()

vi.mock('@/services/tickets', () => ({
	fetchVisibilityImpact: (...args: unknown[]) => fetchVisibilityImpact(...args),
	changeVisibility: (...args: unknown[]) => changeVisibility(...args),
}))
vi.mock('@/services/toast', () => ({
	showError: (...args: unknown[]) => showError(...args),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars?: Record<string, unknown>) => text.replace(/\{(\w+)\}/g, (match, key: string) => String(vars?.[key] ?? match)),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	// Ein echter Knopf statt der Nextcloud-Komponente: Der Test fragt nach
	// Beschriftung und Klick, nicht nach deren Innenleben.
	default: {
		name: 'NcButton',
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
}))

const VisibilityControl = (await import('@/components/VisibilityControl.vue')).default

// `dana` fuehrt kein Uebersteuern und hat auch in Nextcloud keinen Namen — der
// Server hat `resolvedName` deshalb auf die Kennung zurueckfallen lassen. Genau
// dieser Fall gehoert in die Rueckfrage: lieber ein Hash als eine Person
// weniger auf der Liste.
const MEMBERS: Member[] = [
	{ id: 1, boardId: 7, userId: 'anna', role: 'internal', isManager: true, displayName: 'Anna Ahrens', resolvedName: 'Anna Ahrens', addedBy: 'anna', addedAt: null },
	{ id: 2, boardId: 7, userId: 'carla', role: 'external', isManager: false, displayName: 'Carla Cordes', resolvedName: 'Carla Cordes', addedBy: 'anna', addedAt: null },
	{ id: 3, boardId: 7, userId: 'dana', role: 'external', isManager: false, displayName: null, resolvedName: 'dana', addedBy: 'anna', addedAt: null },
]

/**
 * @param overrides Was für den jeweiligen Fall abweicht.
 */
function ticketOf(overrides: Partial<Ticket> = {}): Ticket {
	return {
		id: 42,
		boardId: 7,
		columnId: 3,
		number: 17,
		title: 'Startseite abstimmen',
		description: null,
		visibility: 'public',
		creatorUserId: 'anna',
		creatorRole: 'internal',
		responsibleUserId: null,
		closedAt: null,
		version: 5,
		lastEditorUserId: null,
		githubIssueNumber: null,
		githubIssueUrl: null,
		createdAt: null,
		updatedAt: null,
		...overrides,
	}
}

/**
 * @param overrides Was für den jeweiligen Fall abweicht.
 */
function viewerOf(overrides: Partial<ViewerInfo> = {}): ViewerInfo {
	return { userId: 'anna', role: 'internal', isManager: true, ...overrides }
}

/**
 * @param ticket Das Ticket im Overlay.
 * @param viewer Wer davorsitzt.
 */
function mountControl(ticket: Ticket, viewer: ViewerInfo | null) {
	return mount(VisibilityControl, {
		props: { ticket, viewer, members: MEMBERS },
	})
}

const LABELS: Record<Visibility, string> = {
	public: 'Alle Beteiligten',
	internal: 'Intern',
	private: 'Nur ich',
}

/**
 * @param wrapper Die montierte Komponente.
 * @param target Die angestrebte Stufe.
 */
function optionFor(wrapper: ReturnType<typeof mountControl>, target: Visibility) {
	return wrapper.findAll('.pw-visopt').find((b) => b.text().includes(LABELS[target]))
}

/**
 * **Ein** Klick auf die Stufe — mehr ist es seit #75 nicht.
 *
 * Vorher waren es drei: „Ändern", die Stufe, „Übernehmen". Dass diese Hilfe
 * geschrumpft ist, ist die eigentliche Aussage der Änderung.
 *
 * @param wrapper Die montierte Komponente.
 * @param target Die angestrebte Stufe.
 */
async function choose(wrapper: ReturnType<typeof mountControl>, target: Visibility) {
	await optionFor(wrapper, target)?.trigger('click')
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
}

beforeEach(() => {
	vi.clearAllMocks()
	changeVisibility.mockImplementation(async (_b, _t, _v, visibility: Visibility) => ticketOf({ visibility, version: 6 }))
})

describe('VisibilityControl', () => {
	it('zeigt der anderen Seite gar keine Bedienung', () => {
		// §7: Ändern darf nur die Seite, der das Ticket gehört.
		const wrapper = mountControl(
			ticketOf({ creatorRole: 'external', creatorUserId: 'carla' }),
			viewerOf(),
		)

		expect(wrapper.find('.pw-viscontrol').exists()).toBe(false)
	})

	/**
	 * Die Reihenfolge geht von zu nach offen: „Nur ich", „Intern", „Alle
	 * Beteiligten". Von links nach rechts wird der Kreis größer, und genau so
	 * liest man eine Zeile.
	 *
	 * Sie ist **Anzeige, keine Rangfolge im Code** — deshalb steht sie hier und
	 * nicht als Vergleich in der Logik. Ein Test dafür, weil eine Reihenfolge
	 * beim nächsten Umbau lautlos kippt und niemandem auffällt, der sie nicht
	 * täglich sieht.
	 */
	it('reiht die Stufen von zu nach offen', () => {
		const wrapper = mountControl(ticketOf(), viewerOf())

		expect(wrapper.findAll('.pw-visopt').map((o) => o.text())).toEqual([
			'Nur ich',
			'Intern',
			'Alle Beteiligten',
		])
	})

	it('sperrt „Nur ich" an einem fremden Ticket der eigenen Seite', async () => {
		// Anna sieht Berts internes Ticket, darf es aber nicht zu dessen Entwurf machen.
		const wrapper = mountControl(
			ticketOf({ creatorUserId: 'bert' }),
			viewerOf({ userId: 'anna' }),
		)

		expect(optionFor(wrapper, 'private')?.attributes('disabled')).toBeDefined()
	})

	/**
	 * **Die Markierung zeigt, was gilt — nicht, was geklickt wurde.**
	 *
	 * Zwischen Klick und Antwort liegt `visibility-impact`. Spränge die
	 * Markierung sofort auf die angeklickte Stufe, sähe eine Änderung erledigt
	 * aus, die noch eine Rückfrage vor sich hat — und wer die Rückfrage abbricht,
	 * bliebe mit einer Markierung zurück, die lügt.
	 *
	 * Die Eigenschaft hängt an einer einzigen Zeile in der Vorlage
	 * (`:modelValue="ticket.visibility"` statt `v-model="chosen"`) und geht beim
	 * nächsten Umbau still verloren, wenn sie hier nicht steht.
	 */
	it('markiert während der Rückfrage weiter die geltende Stufe', async () => {
		fetchVisibilityImpact.mockResolvedValue({ losing: ['carla'], comments: 0, attachments: 0 })

		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		// Die Rückfrage steht — geschrieben wurde noch nichts.
		expect(wrapper.find('.pw-viscontrol__warn').exists()).toBe(true)
		expect(changeVisibility).not.toHaveBeenCalled()

		// …und sie nennt, wohin es ginge. Seit „Übernehmen" weg ist, ist ein
		// Fehlgriff nur noch hier abzufangen.
		expect(wrapper.find('.pw-viscontrol__target').text()).toContain('Intern')

		// Solange sie offen ist, nimmt die Auswahl keinen zweiten Klick an. Ohne
		// die Sperre liesse sich eine dritte Stufe anklicken, und deren Rückfrage
		// überschriebe die erste — bestätigt würde dann etwas anderes als das,
		// was auf dem Schirm stand.
		expect(wrapper.findAll('.pw-visopt').every((o) => o.attributes('disabled') !== undefined)).toBe(true)

		// Abbrechen führt zurück, ohne dass die Markierung je gewandert wäre.
		await wrapper.findAll('button').find((b) => b.text() === 'Abbrechen')?.trigger('click')
		await wrapper.vm.$nextTick()

		expect(optionFor(wrapper, 'public')?.attributes('aria-pressed')).toBe('true')
		expect(optionFor(wrapper, 'internal')?.attributes('aria-pressed')).toBe('false')
	})

	/**
	 * Ein Klick auf die geltende Stufe ist keine Änderung.
	 *
	 * Ohne diese Sperre löste jeder Klick auf die bereits markierte Karte einen
	 * `visibility-impact`-Aufruf aus — und im folgenlosen Fall gleich noch ein
	 * Schreiben auf denselben Wert hinterher.
	 */
	it('tut nichts, wenn die geltende Stufe angeklickt wird', async () => {
		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'public')

		expect(fetchVisibilityImpact).not.toHaveBeenCalled()
		expect(changeVisibility).not.toHaveBeenCalled()
	})

	it('fragt nicht zurück, wenn der Server niemanden nennt', async () => {
		// Richtung public -> internal, also dem Anschein nach ein Herunterstufen.
		// Der Server sagt: niemand verliert etwas. Das zählt, nicht der Anschein.
		fetchVisibilityImpact.mockResolvedValue({ losing: [], comments: 3, attachments: 1 })

		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		expect(wrapper.find('.pw-viscontrol__warn').exists()).toBe(false)
		expect(changeVisibility).toHaveBeenCalledWith(7, 42, 5, 'internal')
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('fragt zurück, sobald der Server jemanden nennt — auch beim Hochstufen', async () => {
		// Richtung internal -> public, also dem Anschein nach ein Hochstufen.
		// Der Server nennt trotzdem Verlierer; dann wird zurückgefragt.
		fetchVisibilityImpact.mockResolvedValue({ losing: ['carla'], comments: 0, attachments: 0 })

		const wrapper = mountControl(ticketOf({ visibility: 'internal' }), viewerOf())
		await choose(wrapper, 'public')

		expect(wrapper.find('.pw-viscontrol__warn').exists()).toBe(true)
		expect(changeVisibility).not.toHaveBeenCalled()
	})

	it('nennt in der Rückfrage Namen und Zahlen, nicht Kennungen', async () => {
		fetchVisibilityImpact.mockResolvedValue({ losing: ['carla', 'dana'], comments: 4, attachments: 2 })

		const wrapper = mountControl(ticketOf(), viewerOf())
		await choose(wrapper, 'internal')

		const warn = wrapper.find('.pw-viscontrol__warn')
		expect(warn.text()).toContain('4 Kommentare')
		expect(warn.text()).toContain('2 Anhänge')
		expect(warn.text()).toContain('Carla Cordes')
		// Ohne gepflegten Namen bleibt die Kennung — besser als eine leere Zeile.
		expect(warn.text()).toContain('dana')
		expect(warn.text()).toContain('Die Beteiligten werden nicht benachrichtigt.')
	})

	it('schreibt erst nach der Bestätigung', async () => {
		fetchVisibilityImpact.mockResolvedValue({ losing: ['carla'], comments: 0, attachments: 0 })

		const wrapper = mountControl(ticketOf(), viewerOf())
		await choose(wrapper, 'internal')

		await wrapper.findAll('button').find((b) => b.text() === 'Sichtbarkeit ändern')?.trigger('click')
		await wrapper.vm.$nextTick()

		expect(changeVisibility).toHaveBeenCalledWith(7, 42, 5, 'internal')
	})

	it('lässt einen folgenlosen Wechsel widerrufen, einen folgenreichen nicht', async () => {
		// §10: „Hochstufen ohne Rückfrage, aber kurz widerrufbar."
		fetchVisibilityImpact.mockResolvedValue({ losing: [], comments: 0, attachments: 0 })

		const wrapper = mountControl(ticketOf({ visibility: 'internal' }), viewerOf())
		await choose(wrapper, 'public')

		const undo = wrapper.findAll('button').find((b) => b.text() === 'Rückgängig')
		expect(undo).toBeDefined()

		await undo?.trigger('click')
		await wrapper.vm.$nextTick()

		// Zurück auf den Stand von eben — ohne zweite Rückfrage.
		expect(changeVisibility).toHaveBeenLastCalledWith(7, 42, 5, 'internal')
		expect(fetchVisibilityImpact).toHaveBeenCalledTimes(1)
	})

	it('meldet einen Konflikt als solchen statt als allgemeinen Fehler', async () => {
		fetchVisibilityImpact.mockResolvedValue({ losing: [], comments: 0, attachments: 0 })
		changeVisibility.mockRejectedValue({ status: 409, message: 'Zwischenzeitlich geändert' })

		const wrapper = mountControl(ticketOf(), viewerOf())
		await choose(wrapper, 'internal')

		expect(showError).toHaveBeenCalledWith('Der Vorgang wurde zwischenzeitlich geändert. Bitte neu laden.')
	})
})
