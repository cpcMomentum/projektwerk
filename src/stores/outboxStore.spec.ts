/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const createTicket = vi.fn()
const createStep = vi.fn()
const updateStep = vi.fn()
const createComment = vi.fn()

vi.mock('@/services/tickets', () => ({ createTicket: (...args: unknown[]) => createTicket(...args) }))
vi.mock('@/services/steps', () => ({
	createStep: (...args: unknown[]) => createStep(...args),
	updateStep: (...args: unknown[]) => updateStep(...args),
}))
vi.mock('@/services/comments', () => ({ createComment: (...args: unknown[]) => createComment(...args) }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const { useOutboxStore } = await import('@/stores/outboxStore')

const TICKET = { title: 'Unterlagen', columnId: 3, visibility: 'public' as const, description: null, responsibleUserId: null, dueDate: null }

/** Ein Speicher, den sich zwei „Tabs“ teilen können. */
function memoryStorage(): Storage {
	const data = new Map<string, string>()

	return {
		get length() {
			return data.size
		},
		clear: () => data.clear(),
		getItem: (key: string) => data.get(key) ?? null,
		key: (index: number) => [...data.keys()][index] ?? null,
		removeItem: (key: string) => {
			data.delete(key)
		},
		setItem: (key: string, value: string) => {
			data.set(key, value)
		},
	}
}

/** Bis die Warteschlange alle anstehenden Aufrufe abgearbeitet hat. */
async function settle(): Promise<void> {
	for (let i = 0; i < 10; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0))
	}
}

/**
 * @param status HTTP-Status, 0 = keine Antwort.
 * @param message Meldung des Servers.
 */
function apiError(status: number, message = 'Fehler') {
	return { status, message, notJson: false }
}

let storage: Storage

beforeEach(() => {
	vi.clearAllMocks()
	storage = memoryStorage()
	vi.stubGlobal('localStorage', storage)
	Object.defineProperty(window, 'localStorage', { value: storage, configurable: true })
	setActivePinia(createPinia())
	createTicket.mockImplementation(async (_board: number, payload: { title: string }) => ({ id: 181, number: 8, ...payload }))
	createStep.mockImplementation(async (_board: number, ticketId: number, payload: { title: string }) => ({ id: 900, ticketId, ...payload }))
	createComment.mockImplementation(async (_board: number, ticketId: number, body: string) => ({ id: 700, ticketId, body }))
	updateStep.mockImplementation(async (_board: number, stepId: number, changes: { done: boolean }) => ({ id: stepId, ...changes }))
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

describe('outboxStore', () => {
	it('sendet einen Vorgang mit seinen Schritten und Kommentaren in Reihenfolge, die Schritte erst mit der echten Kennung', async () => {
		const outbox = useOutboxStore()
		outbox.init('axel')
		const localId = outbox.enqueueTicket(6, TICKET)
		outbox.enqueueStep(6, localId, { title: 'Unterlagen aktualisieren' })
		outbox.enqueueComment(6, localId, 'Erste Notiz')
		outbox.enqueueStep(6, localId, { title: 'Mit Jana klären' })
		await settle()

		expect(createTicket).toHaveBeenCalledTimes(1)
		expect(createStep.mock.calls.map((call) => [call[1], call[2].title])).toEqual([[181, 'Unterlagen aktualisieren'], [181, 'Mit Jana klären']])
		expect(createComment).toHaveBeenCalledWith(6, 181, 'Erste Notiz', expect.anything())
		expect(createTicket.mock.invocationCallOrder[0]).toBeLessThan(createStep.mock.invocationCallOrder[0])
		expect(createStep.mock.invocationCallOrder[0]).toBeLessThan(createComment.mock.invocationCallOrder[0])
		expect(createComment.mock.invocationCallOrder[0]).toBeLessThan(createStep.mock.invocationCallOrder[1])
		expect(outbox.entries).toEqual([])
	})

	it('schickt bei jeder Wiederholung dieselbe Kennung mit', async () => {
		vi.useFakeTimers()
		createTicket.mockRejectedValueOnce(apiError(0)).mockRejectedValueOnce(apiError(504))
		const outbox = useOutboxStore()
		outbox.init('axel')
		outbox.enqueueTicket(6, TICKET)

		await vi.runAllTimersAsync()

		expect(createTicket).toHaveBeenCalledTimes(3)
		const keys = createTicket.mock.calls.map((call) => call[2].idempotencyKey)
		expect(new Set(keys).size).toBe(1)
		expect(outbox.entries).toEqual([])
	})

	it('behandelt 409 beim Anlegen als „läuft noch“ und versucht es erneut', async () => {
		vi.useFakeTimers()
		createComment.mockRejectedValueOnce(apiError(409))
		const outbox = useOutboxStore()
		outbox.init('axel')
		outbox.enqueueComment(6, 42, 'Hallo')

		await vi.runAllTimersAsync()

		expect(createComment).toHaveBeenCalledTimes(2)
		expect(outbox.entries).toEqual([])
	})

	it('lässt einen abgelehnten Eintrag mit Grund stehen und sendet nichts davon Abhängiges', async () => {
		createTicket.mockRejectedValueOnce(apiError(400, 'Spalte gibt es nicht'))
		const outbox = useOutboxStore()
		outbox.init('axel')
		const localId = outbox.enqueueTicket(6, TICKET)
		outbox.enqueueStep(6, localId, { title: 'Hängt dran' })
		await settle()

		expect(outbox.entries[0]).toMatchObject({ kind: 'ticket', state: 'failed', error: 'Spalte gibt es nicht' })
		expect(createStep).not.toHaveBeenCalled()
		expect(outbox.failedFor(6)).toHaveLength(1)
	})

	it('verwirft einen Vorgang mitsamt seinen Schritten', async () => {
		createTicket.mockRejectedValueOnce(apiError(403))
		const outbox = useOutboxStore()
		outbox.init('axel')
		const localId = outbox.enqueueTicket(6, TICKET)
		outbox.enqueueStep(6, localId, { title: 'Hängt dran' })
		await settle()

		outbox.discard(outbox.entries[0].id)

		expect(outbox.entries).toEqual([])
	})

	it('übersteht ein Neuladen der Seite: offline Angelegtes geht beim nächsten Öffnen raus', async () => {
		const offline = useOutboxStore()
		offline.online = false
		offline.init('axel')
		const localId = offline.enqueueTicket(6, TICKET)
		offline.enqueueStep(6, localId, { title: 'Offline angelegt' })
		await settle()
		expect(createTicket).not.toHaveBeenCalled()

		setActivePinia(createPinia())
		const nachNeuladen = useOutboxStore()
		nachNeuladen.init('axel')
		await settle()

		expect(createTicket).toHaveBeenCalledTimes(1)
		expect(createStep).toHaveBeenCalledWith(6, 181, { title: 'Offline angelegt' }, expect.anything())
		expect(nachNeuladen.entries).toEqual([])
	})

	it('hält die Warteschlange je Konto getrennt', async () => {
		const anna = useOutboxStore()
		anna.online = false
		anna.init('anna')
		anna.enqueueComment(6, 42, 'Von Anna')

		setActivePinia(createPinia())
		const bert = useOutboxStore()
		bert.online = false
		bert.init('bert')

		expect(bert.entries).toEqual([])
	})

	it('ersetzt einen noch nicht gesendeten Haken für denselben Schritt, statt zwei zu senden', async () => {
		const outbox = useOutboxStore()
		outbox.online = false
		outbox.init('axel')
		outbox.enqueueStepDone(6, 42, 5, true)
		outbox.enqueueStepDone(6, 42, 5, false)

		expect(outbox.entries).toHaveLength(1)
		expect(outbox.doneOverrides(42)).toEqual({ 5: false })

		outbox.online = true
		await outbox.flush()
		await settle()
		expect(updateStep).toHaveBeenCalledTimes(1)
		expect(updateStep).toHaveBeenCalledWith(6, 5, { done: false })
	})

	it('nimmt Änderungen am Vorgang nur an, solange er noch nicht abgeschickt wurde', async () => {
		vi.useFakeTimers()
		const outbox = useOutboxStore()
		outbox.online = false
		outbox.init('axel')
		const localId = outbox.enqueueTicket(6, TICKET)

		expect(await outbox.updatePendingTicket(localId, { title: 'Neuer Titel' })).toBe(true)

		createTicket.mockRejectedValueOnce(apiError(0))
		outbox.online = true
		await outbox.flush()

		expect(createTicket.mock.calls[0][1].title).toBe('Neuer Titel')
		expect(await outbox.updatePendingTicket(localId, { title: 'Zu spät' })).toBe(false)
	})

	it('sendet nicht doppelt, wenn ein zweiter Tab dieselbe Warteschlange sieht', async () => {
		let release: () => void = () => {}
		createComment.mockImplementationOnce(() => new Promise((resolve) => {
			release = () => resolve({ id: 700, ticketId: 42, body: 'Einmal' })
		}))
		const ersterTab = useOutboxStore()
		ersterTab.init('axel')
		ersterTab.enqueueComment(6, 42, 'Einmal')

		setActivePinia(createPinia())
		const zweiterTab = useOutboxStore()
		zweiterTab.init('axel')
		release()
		await settle()

		const keys = createComment.mock.calls.map((call) => call[3].idempotencyKey)
		expect(new Set(keys).size).toBe(1)
	})

	it('verliert keinen Eintrag, den ein zweiter Tab während des Sendens anlegt', async () => {
		let release: () => void = () => {}
		createComment.mockImplementationOnce(() => new Promise((resolve) => {
			release = () => resolve({ id: 700, ticketId: 42, body: 'Aus Tab A' })
		}))
		const tabA = useOutboxStore()
		tabA.init('axel')
		tabA.enqueueComment(6, 42, 'Aus Tab A')
		await settle()

		setActivePinia(createPinia())
		const tabB = useOutboxStore()
		tabB.online = false
		tabB.init('axel')
		tabB.enqueueComment(6, 42, 'Aus Tab B')

		release()
		await settle()
		await tabA.flush()
		await settle()

		const bodies = createComment.mock.calls.map((call) => call[2])
		expect(bodies).toContain('Aus Tab B')
		expect(JSON.parse(storage.getItem('projektwerk:outbox:axel') ?? '{"entries":[]}').entries).toEqual([])
	})

	it('holt einen verworfenen Eintrag nicht aus einem älteren Stand zurück', async () => {
		const tabA = useOutboxStore()
		tabA.online = false
		tabA.init('axel')
		tabA.enqueueComment(6, 42, 'Wird verworfen')
		const veraltet = storage.getItem('projektwerk:outbox:axel') as string
		tabA.discard(tabA.entries[0].id)

		const mitTombstone = JSON.parse(storage.getItem('projektwerk:outbox:axel') as string)
		storage.setItem('projektwerk:outbox:axel', JSON.stringify({ ...JSON.parse(veraltet), removed: mitTombstone.removed }))
		tabA.load()

		expect(tabA.entries).toEqual([])
	})

	it('gibt einen Serverfehler nach drei Versuchen auf, statt endlos zu wiederholen', async () => {
		vi.useFakeTimers()
		createComment.mockRejectedValue({ status: 500, message: 'Antwort war kein JSON', notJson: true })
		const outbox = useOutboxStore()
		outbox.init('axel')
		outbox.enqueueComment(6, 42, 'Server kaputt')

		await vi.runAllTimersAsync()

		expect(createComment).toHaveBeenCalledTimes(3)
		expect(outbox.failedFor(6)).toHaveLength(1)
	})

	it('zeigt bei einem abgelehnten Haken wieder den Stand des Servers', async () => {
		updateStep.mockRejectedValueOnce(apiError(404))
		const outbox = useOutboxStore()
		outbox.init('axel')
		outbox.enqueueStepDone(6, 42, 5, true)
		await settle()

		expect(outbox.failedFor(6)).toHaveLength(1)
		expect(outbox.doneOverrides(42)).toEqual({})
	})
})
