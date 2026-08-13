/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Bedienung zum Ändern der Sichtbarkeit.
 *
 * **Ein Klick ist der Wechsel** (#103) — in beide Richtungen, ohne Rückfrage.
 * Bis dahin fragte das Frontend erst `visibility-impact` und richtete sich nach
 * `losing`; der Lesepfad ist mit der Rückfrage aufgegeben.
 *
 * Geprüft wird deshalb eine andere Frage als vorher, aber dieselbe Bauform: Das
 * Frontand kennt die Rangfolge der drei Stufen nicht und darf sie nicht
 * nachbauen. Es wechselt, und was nicht geht, weist der Server ab. Die Tests
 * fahren beide Richtungen — public → internal und internal → public — und
 * erwarten in beiden dasselbe: sofort, ohne Zwischenschritt.
 *
 * **Und sie trennen die beiden 409er.** Versionskonflikt und Anhänge-Sperre
 * kommen mit demselben Status; das einzige Merkmal ist das Feld `attachments`
 * im Rumpf. Wer es überliest, meldet der Person mit Anhängen „bitte neu laden".
 */

import type { ViewerInfo, Visibility } from '@/types/board'
import type { Ticket } from '@/types/ticket'

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const changeVisibility = vi.fn()
const showError = vi.fn()

vi.mock('@/services/tickets', () => ({
	changeVisibility: (...args: unknown[]) => changeVisibility(...args),
}))
vi.mock('@/services/toast', () => ({
	showError: (...args: unknown[]) => showError(...args),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars?: Record<string, unknown>) => text.replace(/\{(\w+)\}/g, (match, key: string) => String(vars?.[key] ?? match)),
	// Dieselbe Regel wie die deutsche Mehrzahlform: eins ist Einzahl, alles
	// andere Mehrzahl. Ein Platzhalter, der immer die Mehrzahl liefert, liesse
	// den Fehler durch, um den es hier geht.
	n: (_app: string, singular: string, plural: string, count: number) => (count === 1 ? singular : plural).replace('%n', String(count)),
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
		props: { ticket, viewer },
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
	// Seit #99 rendert `NcRadioGroupButton` echte Radio-Knoepfe. Gesucht wird
	// ueber `value` und nicht ueber eine Klasse: Die Klassen der Komponente sind
	// CSS-Module mit Hash im Namen und aendern sich mit jeder Fassung.
	return wrapper.findAll('input[type="radio"]').find((i) => i.attributes('value') === target)
}

/**
 * **Ein** Klick auf die Stufe — mehr ist es seit #75 nicht, und seit #103 folgt
 * auch nichts mehr darauf.
 *
 * Vorher waren es drei: „Ändern", die Stufe, „Übernehmen"; danach zwei, wenn
 * jemand Zugriff verlor. Dass diese Hilfe zweimal geschrumpft ist, ist die
 * eigentliche Aussage beider Änderungen.
 *
 * @param wrapper Die montierte Komponente.
 * @param target Die angestrebte Stufe.
 */
async function choose(wrapper: ReturnType<typeof mountControl>, target: Visibility) {
	await optionFor(wrapper, target)?.setValue(true)
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
}

beforeEach(() => {
	vi.clearAllMocks()
	changeVisibility.mockImplementation(async (_b, _t, _v, visibility: Visibility) => ticketOf({ visibility, version: 6 }))
})

describe('VisibilityControl', () => {
	it('zeigt der anderen Seite gar keine Bedienung', () => {
		const wrapper = mountControl(
			ticketOf({ creatorRole: 'internal' }),
			viewerOf({ userId: 'carla', role: 'external', isManager: false }),
		)

		expect(wrapper.findAll('input[type="radio"]')).toHaveLength(0)
	})

	it('reiht die Stufen von zu nach offen', () => {
		const wrapper = mountControl(ticketOf(), viewerOf())

		expect(wrapper.findAll('input[type="radio"]').map((o) => o.attributes('value')))
			.toEqual(['private', 'internal', 'public'])
	})

	/**
	 * §7: Auf „Nur ich" herunterstufen kann allein die anlegende Person.
	 *
	 * Gesperrt und nicht versteckt: Wer die Stufe sucht und gar nicht fände,
	 * hielte es für einen Fehler.
	 */
	it('sperrt „Nur ich" an einem fremden Ticket der eigenen Seite', async () => {
		const wrapper = mountControl(
			ticketOf({ creatorUserId: 'bert' }),
			viewerOf({ userId: 'anna' }),
		)

		expect(optionFor(wrapper, 'private')?.attributes('disabled')).toBeDefined()
		expect(optionFor(wrapper, 'internal')?.attributes('disabled')).toBeUndefined()
		expect(wrapper.text()).toContain('Auf „Nur ich" herunterstufen kann nur die anlegende Person')
		expect(wrapper.text()).toContain(LABELS.private)
	})

	/**
	 * **Herunterstufen ohne Rückfrage** (#103) — der eigentliche Punkt.
	 *
	 * Bis hierher stand an dieser Stelle eine Warnung mit Namen und Zahlen. Sie
	 * fiel mit dem Argument, das sie zugleich entkräftet: Die Beschriftung sagt
	 * es schon. Ein Klick auf „Intern" ist der Wechsel.
	 */
	it('stuft ohne Rückfrage herunter', async () => {
		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		expect(wrapper.find('.pw-viscontrol__warn').exists()).toBe(false)
		expect(changeVisibility).toHaveBeenCalledWith(7, 42, 5, 'internal')
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	/**
	 * **Und hochstufen genauso** — dieselbe Handlung, andere Richtung.
	 *
	 * Beide Richtungen stehen einzeln da, weil eine wiederkehrende Versuchung
	 * genau hier ansetzt: die Rangfolge der Stufen im Browser nachzubauen und
	 * „nur beim Herunterstufen" etwas zu tun. Das wäre die Sichtbarkeitsregel in
	 * zweiter Fassung.
	 */
	it('stuft ohne Rückfrage hoch', async () => {
		const wrapper = mountControl(ticketOf({ visibility: 'internal' }), viewerOf())
		await choose(wrapper, 'public')

		expect(wrapper.find('.pw-viscontrol__warn').exists()).toBe(false)
		expect(changeVisibility).toHaveBeenCalledWith(7, 42, 5, 'public')
	})

	/**
	 * Ein Klick auf die geltende Stufe ist keine Änderung.
	 *
	 * Ohne diese Sperre schriebe jeder Klick auf die bereits markierte Stufe
	 * denselben Wert noch einmal — und stellte danach einen Widerruf auf einen
	 * Wechsel, der keiner war.
	 */
	it('tut nichts, wenn die geltende Stufe angeklickt wird', async () => {
		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'public')

		expect(changeVisibility).not.toHaveBeenCalled()
		expect(wrapper.findAll('button').find((b) => b.text() === 'Rückgängig')).toBeUndefined()
	})

	/**
	 * **Die Markierung zeigt, was gilt — nie, was geklickt wurde.**
	 *
	 * Zwischen Klick und Antwort liegt ein Netzaufruf. Spränge die Markierung
	 * sofort, sähe eine Änderung erledigt aus, die noch unterwegs ist — und
	 * scheitert sie, bliebe eine Markierung zurück, die lügt.
	 *
	 * Die Eigenschaft hängt an einer einzigen Zeile in der Vorlage
	 * (`:modelValue="ticket.visibility"` statt `v-model`) und geht beim nächsten
	 * Umbau still verloren, wenn sie hier nicht steht.
	 */
	it('markiert während des Wechsels weiter die geltende Stufe', async () => {
		let antworten: (t: Ticket) => void = () => {}
		changeVisibility.mockImplementation(async () => new Promise<Ticket>((resolve) => {
			antworten = resolve
		}))

		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		// Der Aufruf ist unterwegs — und die Markierung steht noch auf „public".
		expect(changeVisibility).toHaveBeenCalledTimes(1)
		expect((optionFor(wrapper, 'public')?.element as HTMLInputElement).checked).toBe(true)
		expect((optionFor(wrapper, 'internal')?.element as HTMLInputElement).checked).toBe(false)

		// Solange er läuft, nimmt die Auswahl keinen zweiten Klick an. Ohne die
		// Sperre liefen zwei Wechsel mit derselben `version` los, und der zweite
		// scheiterte am Konflikt.
		expect(wrapper.findAll('input[type="radio"]').every((o) => o.attributes('disabled') !== undefined)).toBe(true)

		antworten(ticketOf({ visibility: 'internal', version: 6 }))
	})

	/**
	 * **Anhänge sperren den Wechsel** (§3.10 Stufe 1) — und die Absage kommt
	 * seit #103 vom Server, nicht mehr aus einer Vorabprüfung.
	 *
	 * Kein „Trotzdem": Es gibt nichts, was die App an dieser Stelle tun könnte,
	 * solange der Dateiumzug nicht transaktional zur Datenbank ist.
	 */
	it('spricht die Anhänge-Absage des Servers', async () => {
		changeVisibility.mockRejectedValue({
			status: 409,
			message: 'Bitte die 2 Anhänge zuerst vom Vorgang lösen.',
			data: { attachments: 2 },
		})

		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		const warn = wrapper.find('.pw-viscontrol__warn')
		expect(warn.text()).toContain('2 Anhänge')
		expect(warn.text()).toContain('lösen')

		// **Nicht die Konfliktmeldung.** Beide Fälle sind 409; wer sie nicht
		// trennt, schickt hier „bitte neu laden" los, und Neuladen hilft nicht.
		expect(showError).not.toHaveBeenCalled()
	})

	/**
	 * Bei genau einem Anhang steht dort kein „1 Anhänge".
	 *
	 * Gebeugt wird im Browser und nicht vom Server: Dessen Satz in
	 * `AttachmentsPresentException` läuft ohne `t()` und käme auf Englisch
	 * gedachtem Deutsch heraus, sobald jemand die Oberfläche umstellt.
	 */
	it('beugt die Zahl in der Sperre', async () => {
		changeVisibility.mockRejectedValue({ status: 409, message: 'egal', data: { attachments: 1 } })

		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		const text = wrapper.find('.pw-viscontrol__warn').text()
		expect(text).toContain('1 Anhang')
		expect(text).not.toContain('1 Anhänge')
	})

	/**
	 * **Der Widerruf steht nach JEDEM Wechsel** (#103).
	 *
	 * Vorher nur nach dem folgenlosen — also genau dort, wo ohnehin nichts
	 * passieren konnte. Seit die Rückfrage weg ist, ist er das einzige Netz und
	 * wird gerade für den folgenreichen Fall gebraucht. Der Test stuft deshalb
	 * **herunter**: der Fall, der früher keinen Widerruf bekam.
	 */
	it('lässt auch ein Herunterstufen widerrufen', async () => {
		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		const undo = wrapper.findAll('button').find((b) => b.text() === 'Rückgängig')
		expect(undo).toBeDefined()

		await undo?.trigger('click')
		await wrapper.vm.$nextTick()

		// Zurück auf den Stand von eben.
		expect(changeVisibility).toHaveBeenLastCalledWith(7, 42, 5, 'public')
	})

	it('meldet einen Konflikt als solchen statt als allgemeinen Fehler', async () => {
		changeVisibility.mockRejectedValue({ status: 409, message: 'Zwischenzeitlich geändert' })

		const wrapper = mountControl(ticketOf(), viewerOf())
		await choose(wrapper, 'internal')

		expect(showError).toHaveBeenCalledWith('Der Vorgang wurde zwischenzeitlich geändert. Bitte neu laden.')
		expect(wrapper.find('.pw-viscontrol__warn').exists()).toBe(false)
	})
})
