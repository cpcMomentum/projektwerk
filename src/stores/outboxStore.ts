/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ApiError } from '@/services/api'
import type { Visibility } from '@/types/board'
import type { Comment, Step, Ticket } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { isTransient } from '@/services/api'
import { createComment } from '@/services/comments'
import { createStep, updateStep } from '@/services/steps'
import { createTicket } from '@/services/tickets'
import { newId } from '@/utils/id'

export interface TicketPayload {
	title: string
	columnId: number
	visibility: Visibility
	description: string | null
	responsibleUserId: string | null
	dueDate: string | null
}

export interface StepPayload {
	title: string
	assignedUserId?: string | null
}

interface EntryBase {
	/** Zugleich die Kennung der Anlage (`Idempotency-Key`). */
	id: string
	boardId: number
	state: 'queued' | 'sending' | 'failed'
	error: string | null
	attempts: number
	/** Einmal abgeschickt, darf sich der Inhalt nicht mehr ändern, sonst passt die Kennung nicht mehr. */
	sent: boolean
	nextAttemptAt: number
	createdAt: number
	/** Reihenfolge des Anlegens, auch innerhalb derselben Millisekunde. */
	seq: number
	/** Wer zuletzt geändert hat, gewinnt beim Zusammenführen der Tabs. */
	updatedAt: number
}

export interface TicketEntry extends EntryBase {
	kind: 'ticket'
	/** Vorläufige, negative Kennung, unter der die Oberfläche den Vorgang bis zum Speichern führt. */
	localId: number
	payload: TicketPayload
}

export interface StepEntry extends EntryBase {
	kind: 'step'
	ticketId: number
	payload: StepPayload
}

export interface CommentEntry extends EntryBase {
	kind: 'comment'
	ticketId: number
	payload: { body: string }
}

export interface StepDoneEntry extends EntryBase {
	kind: 'stepDone'
	ticketId: number
	stepId: number
	payload: { done: boolean }
}

export type OutboxEntry = TicketEntry | StepEntry | CommentEntry | StepDoneEntry

export type SavedEvent
	= | { kind: 'ticket', entry: TicketEntry, result: Ticket }
		| { kind: 'step', entry: StepEntry, result: Step }
		| { kind: 'comment', entry: CommentEntry, result: Comment }
		| { kind: 'stepDone', entry: StepDoneEntry, result: Step }

interface Persisted {
	entries: OutboxEntry[]
	/** Vorläufige Kennung → echte Kennung mit Zeitpunkt; bleibt eine Weile, damit andere Tabs nachziehen können. */
	resolved: Record<string, { id: number, at: number }>
	/** Gelöschte Einträge, damit kein anderer Tab sie zurückschreibt. */
	removed: Record<string, number>
}

const STORAGE_PREFIX = 'projektwerk:outbox'
const BACKOFF_SECONDS = [2, 5, 10, 20, 40, 60]
const KEEP_MS = 24 * 60 * 60 * 1000
// Ein Serverfehler, der bleibt, soll nicht endlos wiederholt werden.
const SERVER_ERROR_ATTEMPTS = 3

const listeners = new Set<(event: SavedEvent) => void>()
const externalListeners = new Set<() => void>()
let timer: ReturnType<typeof setTimeout> | null = null
let started = false
let lastSaveOk = true

let localCounter = 0
let seqCounter = 0

/**
 * Fortlaufend über die Zeit, damit Einträge aus mehreren Tabs in Anlegereihenfolge bleiben.
 */
function nextSeq(): number {
	seqCounter = (seqCounter + 1) % 1000

	return Date.now() * 1000 + seqCounter
}

/**
 * Negativ, damit sie nie mit einer Server-Kennung zusammenfällt.
 */
function newLocalId(): number {
	localCounter = (localCounter + 1) % 1000

	return -(Date.now() * 1000 + localCounter)
}

/**
 * @param attempts Bisherige Versuche.
 */
function backoff(attempts: number): number {
	return BACKOFF_SECONDS[Math.min(attempts, BACKOFF_SECONDS.length) - 1] * 1000
}

/**
 * @param entry Der Eintrag.
 */
function ticketOf(entry: OutboxEntry): number {
	return entry.kind === 'ticket' ? entry.localId : entry.ticketId
}

/**
 * @param map Zeitgestempelte Einträge.
 * @param now Jetzt.
 */
function pruned<T extends { at: number } | number>(map: Record<string, T>, now: number): Record<string, T> {
	return Object.fromEntries(Object.entries(map).filter(([, v]) => now - (typeof v === 'number' ? v : v.at) < KEEP_MS))
}

/**
 * Zwei Stände zusammenführen: je Eintrag gewinnt der zuletzt geänderte, Gelöschtes bleibt weg.
 *
 * @param local Der Stand dieses Tabs.
 * @param stored Der Stand im Speicher.
 */
function merge(local: Persisted, stored: Persisted): Persisted {
	const removed = { ...stored.removed, ...local.removed }
	const resolved = { ...stored.resolved, ...local.resolved }
	const byId = new Map<string, OutboxEntry>()
	for (const entry of [...stored.entries, ...local.entries]) {
		const known = byId.get(entry.id)
		if (removed[entry.id] === undefined && (known === undefined || entry.updatedAt >= known.updatedAt)) {
			byId.set(entry.id, entry)
		}
	}
	const entries = [...byId.values()]
		.map((entry) => {
			const ticketId = entry.kind === 'ticket' ? undefined : resolved[String(entry.ticketId)]?.id
			return ticketId === undefined || entry.kind === 'ticket' ? entry : { ...entry, ticketId }
		})
		.sort((a, b) => (a.seq ?? a.createdAt * 1000) - (b.seq ?? b.createdAt * 1000) || (a.id < b.id ? -1 : 1))

	return { entries, resolved, removed }
}

export const useOutboxStore = defineStore('projektwerk-outbox', {
	state: () => ({
		userId: null as string | null,
		entries: [] as OutboxEntry[],
		resolved: {} as Persisted['resolved'],
		removed: {} as Persisted['removed'],
		running: false,
		online: typeof navigator === 'undefined' ? true : navigator.onLine,
	}),

	getters: {
		pendingCountFor: (state) => (boardId: number): number => state.entries.filter((e) => e.boardId === boardId && e.state !== 'failed').length,
		failedFor: (state) => (boardId: number): OutboxEntry[] => state.entries.filter((e) => e.boardId === boardId && e.state === 'failed'),
		pendingTickets: (state) => (boardId: number): TicketEntry[] => state.entries
			.filter((e): e is TicketEntry => e.kind === 'ticket' && e.boardId === boardId),
		stepsFor: (state) => (ticketId: number): StepEntry[] => state.entries
			.filter((e): e is StepEntry => e.kind === 'step' && e.ticketId === ticketId),
		commentsFor: (state) => (ticketId: number): CommentEntry[] => state.entries
			.filter((e): e is CommentEntry => e.kind === 'comment' && e.ticketId === ticketId),
		// Gewünschter Haken je Schritt, solange er unterwegs ist; ein abgelehnter zeigt wieder den Stand des Servers.
		doneOverrides: (state) => (ticketId: number): Record<number, boolean> => Object.fromEntries(state.entries
			.filter((e): e is StepDoneEntry => e.kind === 'stepDone' && e.ticketId === ticketId && e.state !== 'failed')
			.map((e) => [e.stepId, e.payload.done])),
		ticketEntry: (state) => (localId: number): TicketEntry | undefined => state.entries
			.find((e): e is TicketEntry => e.kind === 'ticket' && e.localId === localId),
	},

	actions: {
		/**
		 * Die Warteschlange dieses Kontos laden und das Senden anstoßen.
		 *
		 * @param userId Das angemeldete Konto.
		 */
		init(userId: string): void {
			if (this.userId !== userId) {
				this.userId = userId
				this.entries = []
				this.resolved = {}
				this.removed = {}
				this.load()
			}
			if (!started && typeof window !== 'undefined') {
				started = true
				window.addEventListener('online', () => {
					this.online = true
					this.flush()
				})
				window.addEventListener('offline', () => {
					this.online = false
				})
				window.addEventListener('storage', (event) => {
					if (event.key === this.storageKey()) {
						this.load()
						for (const listener of externalListeners) {
							listener()
						}
					}
				})
				document.addEventListener('visibilitychange', () => {
					if (document.visibilityState === 'visible') {
						this.flush()
					}
				})
			}
			this.flush()
		},

		/**
		 * @param listener Wird nach jedem in diesem Tab gespeicherten Eintrag aufgerufen.
		 * @return Abmelden.
		 */
		subscribe(listener: (event: SavedEvent) => void): () => void {
			listeners.add(listener)

			return () => listeners.delete(listener)
		},

		/**
		 * @param listener Wird aufgerufen, wenn ein anderer Tab die Warteschlange geändert hat.
		 * @return Abmelden.
		 */
		onExternalChange(listener: () => void): () => void {
			externalListeners.add(listener)

			return () => externalListeners.delete(listener)
		},

		storageKey(): string {
			return `${STORAGE_PREFIX}:${this.userId ?? ''}`
		},

		readStored(): Persisted | null {
			try {
				const raw = window.localStorage.getItem(this.storageKey())
				const data = raw === null ? null : JSON.parse(raw) as Partial<Persisted>

				return {
					entries: Array.isArray(data?.entries) ? data.entries : [],
					resolved: data?.resolved ?? {},
					removed: data?.removed ?? {},
				}
			} catch {
				return null
			}
		},

		local(): Persisted {
			return { entries: this.entries, resolved: this.resolved, removed: this.removed }
		},

		apply(state: Persisted): void {
			this.entries = state.entries
			this.resolved = state.resolved
			this.removed = state.removed
		},

		/** Den Speicher mit dem eigenen Stand zusammenführen; ohne Speicher gilt der eigene Stand. */
		load(): void {
			const stored = this.readStored()
			if (stored !== null) {
				this.apply(merge(this.local(), stored))
			}
		},

		save(): void {
			const now = Date.now()
			const stored = this.readStored() ?? { entries: [], resolved: {}, removed: {} }
			const merged = merge(this.local(), stored)
			merged.resolved = pruned(merged.resolved, now)
			merged.removed = pruned(merged.removed, now)
			this.apply(merged)
			try {
				if (merged.entries.length === 0 && Object.keys(merged.resolved).length === 0 && Object.keys(merged.removed).length === 0) {
					window.localStorage.removeItem(this.storageKey())
				} else {
					window.localStorage.setItem(this.storageKey(), JSON.stringify(merged))
				}
				lastSaveOk = true
			} catch {
				// Privates Fenster oder voller Speicher: Die Warteschlange gilt dann nur, solange die Seite offen ist.
				lastSaveOk = false
			}
		},

		base(boardId: number): EntryBase {
			const now = Date.now()

			return { id: newId(), boardId, state: 'queued', error: null, attempts: 0, sent: false, nextAttemptAt: 0, createdAt: now, seq: nextSeq(), updatedAt: now }
		},

		/**
		 * @param id Der Eintrag.
		 */
		find(id: string): OutboxEntry | undefined {
			return this.entries.find((e) => e.id === id)
		},

		/**
		 * Einen Eintrag ändern, über seine Kennung statt über ein vielleicht veraltetes Objekt.
		 *
		 * @param id Der Eintrag.
		 * @param change Die Änderung.
		 */
		patch(id: string, change: (entry: OutboxEntry) => void): void {
			const entry = this.find(id)
			if (entry !== undefined) {
				change(entry)
				entry.updatedAt = Date.now()
			}
		},

		/**
		 * @param ids Die Einträge, die wegfallen.
		 */
		remove(ids: string[]): void {
			const now = Date.now()
			this.entries = this.entries.filter((e) => !ids.includes(e.id))
			this.removed = { ...this.removed, ...Object.fromEntries(ids.map((id) => [id, now])) }
		},

		/**
		 * @param boardId Das Board.
		 * @param payload Was angelegt wird.
		 * @return Die vorläufige Kennung des Vorgangs.
		 */
		enqueueTicket(boardId: number, payload: TicketPayload): number {
			const entry: TicketEntry = { ...this.base(boardId), kind: 'ticket', localId: newLocalId(), payload }
			this.push(entry)

			return entry.localId
		},

		/**
		 * @param boardId Das Board.
		 * @param ticketId Echte oder vorläufige Kennung des Vorgangs.
		 * @param payload Was angelegt wird.
		 */
		enqueueStep(boardId: number, ticketId: number, payload: StepPayload): void {
			this.push({ ...this.base(boardId), kind: 'step', ticketId: this.realId(ticketId), payload })
		},

		/**
		 * @param boardId Das Board.
		 * @param ticketId Echte oder vorläufige Kennung des Vorgangs.
		 * @param body Der Text.
		 */
		enqueueComment(boardId: number, ticketId: number, body: string): void {
			this.push({ ...this.base(boardId), kind: 'comment', ticketId: this.realId(ticketId), payload: { body } })
		},

		/**
		 * Ein noch nicht gesendeter Haken für denselben Schritt wird ersetzt statt verdoppelt.
		 *
		 * @param boardId Das Board.
		 * @param ticketId Der Vorgang.
		 * @param stepId Der Schritt.
		 * @param done Erledigt ja/nein.
		 */
		enqueueStepDone(boardId: number, ticketId: number, stepId: number, done: boolean): void {
			const waiting = this.entries.find((e): e is StepDoneEntry => e.kind === 'stepDone' && e.stepId === stepId && e.state !== 'sending')
			if (waiting !== undefined) {
				this.patch(waiting.id, (entry) => {
					entry.payload = { done }
					entry.state = 'queued'
					entry.error = null
					entry.attempts = 0
					entry.nextAttemptAt = 0
				})
				this.save()
				this.flush()
				return
			}
			this.push({ ...this.base(boardId), kind: 'stepDone', ticketId, stepId, payload: { done } })
		},

		/**
		 * Felder eines noch nicht gesendeten Vorgangs ändern; nur wenn gerade kein Tab sendet.
		 *
		 * @param localId Vorläufige Kennung.
		 * @param changes Die neuen Werte.
		 * @return Ob es ging; nach dem ersten Senden ist der Inhalt festgelegt.
		 */
		async updatePendingTicket(localId: number, changes: Partial<TicketPayload>): Promise<boolean> {
			const apply = (): boolean => {
				this.load()
				const entry = this.ticketEntry(localId)
				if (entry === undefined || entry.sent || entry.state === 'sending') {
					return false
				}
				this.patch(entry.id, (e) => {
					(e as TicketEntry).payload = { ...(e as TicketEntry).payload, ...changes }
				})
				this.save()

				return true
			}
			const locks = typeof navigator !== 'undefined' ? navigator.locks : null
			if (!locks) {
				return !this.running && apply()
			}

			return await locks.request(this.storageKey(), { ifAvailable: true }, async (lock) => lock !== null && apply())
		},

		/**
		 * @param ticketId Eine vielleicht schon aufgelöste vorläufige Kennung.
		 */
		realId(ticketId: number): number {
			return this.resolved[String(ticketId)]?.id ?? ticketId
		},

		push(entry: OutboxEntry): void {
			this.entries = [...this.entries, entry]
			this.save()
			this.flush()
		},

		/**
		 * @param id Der Eintrag.
		 */
		retry(id: string): void {
			this.patch(id, (entry) => {
				entry.state = 'queued'
				entry.error = null
				entry.attempts = 0
				entry.nextAttemptAt = 0
			})
			this.save()
			this.flush()
		},

		/**
		 * Ein verworfener Vorgang nimmt seine Schritte und Kommentare mit.
		 *
		 * @param id Der Eintrag.
		 */
		discard(id: string): void {
			const entry = this.find(id)
			if (entry === undefined || entry.state === 'sending') {
				return
			}
			const local = entry.kind === 'ticket' ? entry.localId : null
			this.remove(this.entries.filter((e) => e.id === id || (local !== null && ticketOf(e) === local)).map((e) => e.id))
			this.save()
		},

		schedule(delay: number): void {
			if (timer !== null) {
				clearTimeout(timer)
			}
			timer = setTimeout(() => {
				timer = null
				this.flush()
			}, Math.max(delay, 0))
		},

		/**
		 * Der Reihe nach senden. Nur ein Tab sendet; die Kennung fängt den Rest ab.
		 */
		async flush(): Promise<void> {
			if (this.running || this.userId === null) {
				return
			}
			const locks = typeof navigator !== 'undefined' ? navigator.locks : null
			if (!locks) {
				await this.drain()
				return
			}
			await locks.request(this.storageKey(), { ifAvailable: true }, async (lock) => {
				if (lock !== null) {
					await this.drain()
				}
			})
		},

		async drain(): Promise<void> {
			this.running = true
			try {
				// Ging das letzte Speichern schief, ist der Speicher älter als der eigene Stand.
				if (lastSaveOk) {
					this.load()
				}
				// Wer die Sperre hält, sendet als Einziger; ein „sending“ stammt aus einem geschlossenen Tab.
				for (const entry of this.entries) {
					if (entry.state === 'sending') {
						entry.state = 'queued'
					}
				}
				for (;;) {
					const next = this.entries.find((e) => e.state !== 'failed' && !(e.kind !== 'ticket' && e.ticketId < 0))
					if (next === undefined) {
						return
					}
					if (!this.online) {
						return
					}
					const wait = next.nextAttemptAt - Date.now()
					if (wait > 0) {
						this.schedule(wait)
						return
					}
					await this.send(next.id)
				}
			} finally {
				this.running = false
				this.save()
			}
		},

		async send(id: string): Promise<void> {
			this.patch(id, (entry) => {
				entry.state = 'sending'
				entry.sent = true
				entry.attempts++
			})
			this.save()
			const entry = this.find(id)
			if (entry === undefined) {
				return
			}

			const options = { idempotencyKey: entry.id }
			try {
				let event: SavedEvent
				switch (entry.kind) {
					case 'ticket':
						event = { kind: 'ticket', entry, result: await createTicket(entry.boardId, entry.payload, options) }
						break
					case 'step':
						event = { kind: 'step', entry, result: await createStep(entry.boardId, entry.ticketId, entry.payload, options) }
						break
					case 'comment':
						event = { kind: 'comment', entry, result: await createComment(entry.boardId, entry.ticketId, entry.payload.body, options) }
						break
					case 'stepDone':
						event = { kind: 'stepDone', entry, result: await updateStep(entry.boardId, entry.stepId, { done: entry.payload.done }) }
						break
				}
				this.succeeded(event)
			} catch (e) {
				const error = e as ApiError
				const serverError = error.status >= 500
				this.patch(id, (live) => {
					if (isTransient(error, live.kind !== 'stepDone') && !(serverError && live.attempts >= SERVER_ERROR_ATTEMPTS)) {
						live.state = 'queued'
						live.error = t('projektwerk', 'Keine Verbindung, neuer Versuch folgt.')
						live.nextAttemptAt = Date.now() + backoff(live.attempts)
					} else {
						live.state = 'failed'
						live.error = error.status === 404
							? t('projektwerk', 'Der Vorgang ist nicht mehr da oder nicht mehr sichtbar.')
							: (error.message || t('projektwerk', 'Nicht gespeichert.'))
					}
				})
				this.save()
			}
		},

		succeeded(event: SavedEvent): void {
			const entry = event.entry
			this.remove([entry.id])
			if (event.kind === 'ticket') {
				const realId = event.result.id
				this.resolved = { ...this.resolved, [String(event.entry.localId)]: { id: realId, at: Date.now() } }
				for (const other of this.entries) {
					if (other.kind !== 'ticket' && other.ticketId === event.entry.localId) {
						this.patch(other.id, (e) => {
							(e as StepEntry).ticketId = realId
						})
					}
				}
			}
			this.save()
			for (const listener of listeners) {
				listener(event)
			}
		},
	},
})
