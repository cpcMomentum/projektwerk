/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Der Zustand eines geöffneten Boards.
 *
 * **Normalisiert: `Map<id, Ticket>` plus `columnOrder`**, nicht verschachtelte
 * Arrays. Das kostet heute nichts und ist Voraussetzung für den späteren
 * Delta-Poll und den `notify_push`-Pfad — eine verschachtelte Struktur müsste
 * dafür umgebaut werden, wenn niemand mehr weiß, wer sie liest.
 */

import type { Board, BoardDetail, Column, Member, ViewerInfo } from '@/types/board'
import type { Ticket, TicketList } from '@/types/ticket'

import { defineStore } from 'pinia'
import { fetchBoard, fetchBoards } from '@/services/boards'
import { fetchTickets, moveTicket as moveTicketRequest } from '@/services/tickets'
import { showError } from '@/services/toast'

interface State {
	boards: Board[]
	board: Board | null
	members: Member[]
	columns: Column[]
	viewer: ViewerInfo | null
	tickets: Map<number, Ticket>
	/** Ticket-IDs je Spalte, in Serverreihenfolge. */
	columnOrder: Map<number, number[]>
	counts: TicketList['counts'] | null
	loading: boolean
	error: string | null
}

export const useBoardStore = defineStore('board', {
	state: (): State => ({
		boards: [],
		board: null,
		members: [],
		columns: [],
		viewer: null,
		tickets: new Map(),
		columnOrder: new Map(),
		counts: null,
		loading: false,
		error: null,
	}),

	getters: {
		/**
		 * Ob der Betrachter zur internen Seite gehört.
		 *
		 * Entscheidet, ob die Sichtbarkeitskennzeichnung überhaupt erscheint: In
		 * der Kundenansicht entfällt sie vollständig, dort ist jedes sichtbare
		 * Ticket öffentlich und die Markierung wäre Rauschen (§9).
		 *
		 * @param state Der Speicher.
		 */
		isInternal: (state): boolean => state.viewer?.role === 'internal',

		/**
		 * Hat das Board überhaupt eine Gegenseite?
		 *
		 * Ohne externe Mitglieder entfällt die Kennzeichnung ganz — es gibt
		 * niemanden, vor dem etwas verborgen wäre (§9).
		 *
		 * @param state Der Speicher.
		 */
		hasExternalMembers: (state): boolean => state.members.some((m) => m.role === 'external'),

		/**
		 * Der Name der Firma zu einer Rolle.
		 *
		 * @param state Der Speicher.
		 */
		orgFor: (state) => (role: string): string | null => role === 'internal' ? (state.board?.orgInternal ?? null) : (state.board?.orgExternal ?? null),

		/**
		 * Der anzuzeigende Name einer Person.
		 *
		 * Der Name an der Mitgliedschaft geht vor; fehlt er, bleibt die
		 * Benutzerkennung. Nie die Kennung, wo ein Name da ist — sonst steht bei
		 * einem Gastkonto ein 64-stelliger Hash auf der Karte.
		 *
		 * @param state Der Speicher.
		 */
		nameOf: (state) => (userId: string | null): string => {
			if (userId === null) {
				return ''
			}
			const member = state.members.find((m) => m.userId === userId)
			return member?.displayName ?? userId
		},
	},

	actions: {
		async loadBoards(): Promise<void> {
			this.loading = true
			this.error = null
			try {
				this.boards = await fetchBoards()
			} catch (e) {
				this.error = (e as { message?: string }).message ?? 'Unbekannter Fehler'
				showError(this.error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Board und Tickets laden.
		 *
		 * @param boardId Kennung des Projekts.
		 */
		async open(boardId: number): Promise<void> {
			this.loading = true
			this.error = null
			try {
				const detail: BoardDetail = await fetchBoard(boardId)
				this.board = detail.board
				this.members = detail.members
				this.columns = detail.columns
				this.viewer = detail.viewer

				this.applyTickets(await fetchTickets(boardId))
			} catch (e) {
				this.error = (e as { message?: string }).message ?? 'Unbekannter Fehler'
				showError(this.error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Ein Ticket verschieben — mit Nachbar-IDs, nie mit einer Position.
		 *
		 * @param ticketId Kennung des Tickets.
		 * @param targetColumnId Zielspalte.
		 * @param beforeId Nachbar darüber oder null.
		 * @param afterId Nachbar darunter oder null.
		 */
		async moveTicket(
			ticketId: number,
			targetColumnId: number,
			beforeId: number | null,
			afterId: number | null,
		): Promise<void> {
			const ticket = this.tickets.get(ticketId)
			if (ticket === undefined || this.board === null) {
				return
			}

			await moveTicketRequest(this.board.id, ticketId, ticket.version, {
				targetColumnId,
				beforeId,
				afterId,
			})

			// Nach dem Verschieben neu laden statt lokal umzusortieren: Die
			// Reihenfolge entsteht serverseitig aus der ungefilterten Liste, und
			// eine lokale Vorwegnahme könnte davon abweichen.
			this.applyTickets(await fetchTickets(this.board.id))
		},

		/**
		 * @param list Tickets und Zähler vom Server.
		 */
		applyTickets(list: TicketList): void {
			this.tickets = new Map(list.tickets.map((ticket) => [ticket.id, ticket]))
			this.counts = list.counts

			const order = new Map<number, number[]>()
			for (const column of this.columns) {
				order.set(column.id, [])
			}
			for (const ticket of list.tickets) {
				order.get(ticket.columnId)?.push(ticket.id)
			}
			this.columnOrder = order
		},

		/**
		 * Die Tickets einer Spalte, in Serverreihenfolge.
		 *
		 * @param columnId Kennung der Spalte.
		 */
		ticketsIn(columnId: number): Ticket[] {
			return (this.columnOrder.get(columnId) ?? [])
				.map((id) => this.tickets.get(id))
				.filter((t): t is Ticket => t !== undefined)
		},
	},
})
