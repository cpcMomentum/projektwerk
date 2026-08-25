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
 * **Anhänge sperren den Wechsel nicht mehr** (#185): Die Datei zieht mit der
 * Sichtbarkeit um. Geht das nicht, kommt die Absage als **400** mit Meldung —
 * bewusst nicht als 409, das `reportWriteError` als Versionskonflikt läse und
 * die eigentliche Meldung verschluckte.
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
		dueDate: null,
		closedAt: null,
		closedOutcome: null,
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
	 * denselben Wert noch einmal — ein Netzaufruf ohne Wirkung.
	 */
	it('tut nichts, wenn die geltende Stufe angeklickt wird', async () => {
		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'public')

		expect(changeVisibility).not.toHaveBeenCalled()
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
	 * **Der Umzug ersetzt die Sperre** (#185). Ein Wechsel mit Anhang wird nicht
	 * mehr blockiert — die Datei zieht mit der Sichtbarkeit um. Geht das
	 * ausnahmsweise nicht (die Zielstufe hat keinen Ablageort), weist der Server
	 * mit **400** und einer Meldung ab, und die erscheint als Fehler.
	 *
	 * **400 und nicht 409**: Ein 409 läse `reportWriteError` als Versionskonflikt
	 * („bitte neu laden") und verschluckte die eigentliche Meldung. Der Test
	 * hält fest, dass die Servermeldung durchkommt — und dass es keinen eigenen
	 * Warnblock mehr gibt.
	 */
	it('zeigt die Servermeldung, wenn der Umzug nicht geht — ohne Warnblock', async () => {
		changeVisibility.mockRejectedValue({
			status: 400,
			message: 'Für dieses Projekt ist noch kein Ordner hinterlegt. Die Projektverwaltung trägt ihn in den Einstellungen ein.',
		})

		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		expect(showError).toHaveBeenCalledWith('Für dieses Projekt ist noch kein Ordner hinterlegt. Die Projektverwaltung trägt ihn in den Einstellungen ein.')
		expect(wrapper.find('.pw-viscontrol__warn').exists()).toBe(false)
	})

	/**
	 * **Kein „Rückgängig" nach einem Wechsel** (#181).
	 *
	 * Der Widerruf ist entfernt: Der Umschalter steht ohnehin offen und ist der
	 * Ein-Klick-Rückweg. Ein eigener Knopf war redundant und liess die Kopfzeile
	 * springen (`pw-viscontrol--offen`). Nach einem erfolgreichen Wechsel darf
	 * deshalb weder ein „Rückgängig" erscheinen noch die Zeile umbrechen.
	 */
	it('stellt nach einem Wechsel kein „Rückgängig" auf und bricht die Zeile nicht um', async () => {
		const wrapper = mountControl(ticketOf({ visibility: 'public' }), viewerOf())
		await choose(wrapper, 'internal')

		expect(changeVisibility).toHaveBeenCalledWith(7, 42, 5, 'internal')
		expect(wrapper.findAll('button').find((b) => b.text() === 'Rückgängig')).toBeUndefined()
		expect(wrapper.find('.pw-viscontrol--offen').exists()).toBe(false)
	})

	it('meldet einen Konflikt als solchen statt als allgemeinen Fehler', async () => {
		changeVisibility.mockRejectedValue({ status: 409, message: 'Zwischenzeitlich geändert' })

		const wrapper = mountControl(ticketOf(), viewerOf())
		await choose(wrapper, 'internal')

		expect(showError).toHaveBeenCalledWith('Der Vorgang wurde zwischenzeitlich geändert. Bitte neu laden.')
		expect(wrapper.find('.pw-viscontrol__warn').exists()).toBe(false)
	})
})
