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
import type { Ticket, TicketList, WaitState } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { fetchBoard, fetchBoards, setBoardPin } from '@/services/boards'
import { createBoard as createBoardRequest } from '@/services/settings'
import { fetchTickets, markTicketRead, moveTicket as moveTicketRequest } from '@/services/tickets'
import { showError } from '@/services/toast'

/**
 * Wie viele geschlossene Vorgänge je Spalte stehen bleiben (#59).
 *
 * **Eine Konstante und keine Einstellung.** Eine Einstellung wäre die Art, die
 * Entscheidung nicht zu treffen: Wer ein Projekt anlegt, hat noch kein volles
 * Board und könnte die Zahl noch weniger begründen als wir — er ließe sie
 * stehen, und wir hätten Feld, Migration und Validierung dafür bezahlt.
 *
 * Zehn ist etwa ein Bildschirm Nachlauf unter den offenen Karten. Stellt sich
 * die Zahl als falsch heraus, ist sie an genau dieser Stelle zu ändern; eine
 * Einstellung lässt sich dann immer noch ergänzen. Umgekehrt — eine Einstellung
 * wieder wegzunehmen, die jemand gesetzt hat — ist ungleich teurer.
 */
export const CLOSED_TAIL = 10

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
	/** Gerechnet, nie gespeichert — nur die wartenden Tickets stehen drin. */
	waiting: Record<number, WaitState>
	/** „Seit deinem Blick geändert" (#79) — nur die geänderten Vorgänge. */
	changed: Record<number, boolean>
	/** Filterschalter „Nur wartend"; liegt quer zu den Spalten. */
	onlyWaiting: boolean
	/** Spalten, in denen „ältere anzeigen" gerade aufgeklappt ist (#59). */
	expandedColumns: number[]
	loading: boolean
	/**
	 * Ob die Boardliste seit dem Seitenaufruf **mindestens einmal** geladen
	 * wurde (#234). Nicht `boards.length > 0`: Ein interner Nutzer ohne Projekt
	 * hat eine leere, aber gültige Liste — und das Gäste-Gate muss „noch nicht
	 * geladen" von „geladen, aber leer" unterscheiden können.
	 */
	loaded: boolean
	/** Der laufende Ladevorgang, damit Rahmen und Gate ihn sich teilen (#234). */
	loadPromise: Promise<void> | null
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
		waiting: {},
		changed: {},
		onlyWaiting: false,
		expandedColumns: [],
		loading: false,
		loaded: false,
		loadPromise: null,
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
		 * Die Firmenzeile eines Boards — beide Seiten, nicht nur eine.
		 *
		 * Nimmt ein beliebiges Board entgegen statt nur das geöffnete: Die
		 * Boardliste braucht die Zeile für jede Karte, das geöffnete Board für
		 * genau eine.
		 */
		orgLine: () => (board: Pick<Board, 'orgInternal' | 'orgExternal'>): string => [board.orgInternal, board.orgExternal].filter(Boolean).join(' · '),

		/**
		 * Die angepinnten Projekte für die Seitenleiste (#115).
		 *
		 * Kein eigener Abruf: Es ist die Teilmenge der ohnehin geladenen Liste,
		 * und die ist bereits die Schnittmenge aus „gepinnt" und „sichtbar" — der
		 * Server liefert `pinned` nur an den Boards mit, die er auch ausliefert.
		 *
		 * @param state Der Speicher.
		 */
		pinnedBoards: (state): Board[] => state.boards.filter((board) => board.pinned === true),

		/**
		 * Ob der Betrachter in **mindestens einem** Projekt intern ist (#234).
		 *
		 * Das Unterscheidungsmerkmal zwischen Dienstleister und Kunde über alle
		 * Projekte hinweg: Der Überblick ist ein internes Steuerungswerkzeug,
		 * und der „Überblick"-Eintrag der Seitenleiste erscheint nur, wo er
		 * hinführt. Ein Betrachter ohne jedes Projekt gilt hier **nicht** als
		 * extern — er sieht den Überblick (leer), um sein erstes Projekt
		 * anzulegen.
		 *
		 * @param state Der Speicher.
		 */
		internalSomewhere: (state): boolean => state.boards.some((board) => board.viewerRole === 'internal'),

		/**
		 * Wie viele sichtbare Vorgänge gerade warten.
		 *
		 * Für die Zählanzeige am Filterschalter. Aus derselben Menge wie die
		 * Marken selbst — ein eigener Zähler wäre der zweite Ort, an dem die
		 * Regel stimmen müsste.
		 *
		 * @param state Der Speicher.
		 */
		waitingCount: (state): number => Object.keys(state.waiting).length,

		/**
		 * Alle Anzeigenamen des Boards auf einmal — Kennung => Name.
		 *
		 * Für Bauteile, die **mehrere** Personen zeigen (die Kugeln der
		 * Wartemarke). Ihnen `nameOf` durchzureichen hiesse, eine Funktion als
		 * Eigenschaft zu uebergeben; eine Zuordnung ist das ehrlichere Mittel
		 * und aus derselben Quelle.
		 *
		 * @param state Der Speicher.
		 */
		memberNames: (state): Record<string, string> => Object.fromEntries(state.members.map((m) => [m.userId, m.resolvedName])),

		/**
		 * Der anzuzeigende Name einer Person.
		 *
		 * Der Server hat ihn bereits aufgelöst: Name an der Mitgliedschaft, sonst
		 * der aus Nextcloud, sonst die Kennung. Nie die Kennung, wo ein Name da
		 * ist — sonst steht bei einem Gastkonto ein 64-stelliger Hash auf der
		 * Karte.
		 *
		 * @param state Der Speicher.
		 */
		nameOf: (state) => (userId: string | null): string => {
			if (userId === null) {
				return ''
			}
			const member = state.members.find((m) => m.userId === userId)
			return member?.resolvedName ?? userId
		},
	},

	actions: {
		async loadBoards(): Promise<void> {
			this.loading = true
			this.error = null
			try {
				this.boards = await fetchBoards()
				this.loaded = true
			} catch (e) {
				this.error = (e as { message?: string }).message ?? 'Unbekannter Fehler'
				showError(this.error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Die Boardliste einmal laden und den laufenden Ladevorgang teilen (#234).
		 *
		 * Der App-Rahmen (für den Pin-Abschnitt) und das Gäste-Gate (im Router)
		 * feuern beide beim Seitenaufruf — ohne Bündelung wäre das ein doppelter
		 * Abruf und ein Wettlauf. Ist die Liste geladen, kehrt die Methode sofort
		 * zurück; läuft gerade ein Abruf, hängt sie sich an ihn.
		 *
		 * **Bei Fehlschlag bleibt `loaded` falsch** — ein erneuter Aufruf
		 * versucht es wieder. Das Gate wertet das als „unbekannt" und zeigt den
		 * Überblick (fail-open): Die Zahlen dort sind ohnehin
		 * sichtbarkeits-sicher, und ein interner Nutzer soll bei einem Flackern
		 * nicht aus seinem Werkzeug ausgesperrt werden.
		 */
		async ensureBoards(): Promise<void> {
			if (this.loaded) {
				return
			}
			if (this.loadPromise !== null) {
				return this.loadPromise
			}
			this.loadPromise = this.loadBoards()
			try {
				await this.loadPromise
			} finally {
				this.loadPromise = null
			}
		},

		/**
		 * Ein neues Projekt anlegen (#135). Wer anlegt, wird Eigentümer und
		 * interner Verwalter — das entscheidet der Server.
		 *
		 * Der neue Stand wird sofort in die Liste aufgenommen, damit er nach dem
		 * Wechsel zurück auf „Projekte" ohne Nachladerunde dasteht. Fehler wirft
		 * die Action weiter, damit die Ansicht beim Dialog bleiben kann.
		 *
		 * @param data Titel und optional Beschreibung sowie die beiden Firmennamen.
		 * @param data.title Titel des Projekts.
		 * @param data.description Optionale Beschreibung.
		 * @param data.orgInternal Firmenname der eigenen Seite.
		 * @param data.orgExternal Firmenname der Kundenseite.
		 * @return Das angelegte Projekt.
		 */
		async createBoard(data: { title: string, description?: string | null, orgInternal?: string | null, orgExternal?: string | null }): Promise<Board> {
			const board = await createBoardRequest(data)
			this.boards = [...this.boards, board]
			return board
		},

		/**
		 * Ein Projekt an- oder abpinnen und den neuen Stand sofort zeigen (#115).
		 *
		 * Optimistisch mit Rückabwicklung: Der Stern kippt sofort, und nur wenn
		 * der Server ablehnt, springt er zurück — sonst hinge die Seitenleiste
		 * eine Netzrunde hinterher.
		 *
		 * @param boardId Kennung des Projekts.
		 */
		async togglePin(boardId: number): Promise<void> {
			const board = this.boards.find((b) => b.id === boardId)
			if (board === undefined) {
				return
			}

			const vorher = board.pinned === true
			board.pinned = !vorher
			try {
				await setBoardPin(boardId, !vorher)
			} catch (e) {
				board.pinned = vorher
				showError((e as { message?: string }).message ?? t('projektwerk', 'Anpinnen fehlgeschlagen'))
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
			// **Nur beim Wechsel des Boards**, nicht bei jedem Neuladen. `open()`
			// ist auch der allgemeine Nachladepfad: nach einem abgehakten
			// Arbeitsschritt, nach einem angelegten Vorgang, nach einem 409.
			// Bedingungslos geleert klappte die Spalte dem Nutzer unter der Hand
			// wieder zu — mitsamt seiner Scrollposition, und ohne dass irgendwas
			// erklärte, warum.
			if (this.board?.id !== boardId) {
				this.expandedColumns = []
			}
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
		 * Einen Vorgang als gelesen vermerken (#79) — der Punkt geht sofort aus.
		 *
		 * Optimistisch: Der Punkt verschwindet vor der Netzrunde; scheitert der
		 * Server, liefert das nächste `open()` den wahren Stand. Immer senden,
		 * auch ohne sichtbaren Punkt — sonst entstünde beim allerersten Öffnen
		 * nie ein Lesestand, und „seit deinem Blick geändert" bliebe für diesen
		 * Vorgang für immer aus (der Server erwartet ohnehin einen Upsert).
		 *
		 * @param ticketId Kennung des Vorgangs.
		 */
		async markRead(ticketId: number): Promise<void> {
			if (this.board === null) {
				return
			}

			if (this.changed[ticketId] === true) {
				// Neues Objekt für die Reaktivität, wie bei den Maps.
				const next = { ...this.changed }
				delete next[ticketId]
				this.changed = next
			}

			try {
				await markTicketRead(this.board.id, ticketId)
			} catch {
				// Kein Rückgängig: Beim nächsten Laden gewinnt der Server. Ein
				// Fehler beim „gelesen" ist kein Grund, den Nutzer zu stören.
			}
		},

		/**
		 * Ein einzelnes Ticket durch den Stand vom Server ersetzen.
		 *
		 * Kein Neuladen der ganzen Liste, weil sich weder Spalte noch
		 * Reihenfolge ändern können: Der Aufrufer ist die Sichtbarkeitsänderung,
		 * und die lässt `columnId` unberührt.
		 *
		 * Das Ticket bleibt danach sichtbar, auch wenn es gerade verborgener
		 * wurde — wer die Sichtbarkeit ändern darf, gehört nach §7 zur
		 * besitzenden Seite und behält den Zugriff in jeder der drei Stufen.
		 *
		 * @param ticket Der neue Stand.
		 */
		replaceTicket(ticket: Ticket): void {
			// Neue Map statt Mutation: Pinia verfolgt bei einer Map die Identität,
			// nicht die Einträge — ein `set()` allein löste kein Neuzeichnen aus.
			const next = new Map(this.tickets)
			next.set(ticket.id, ticket)
			this.tickets = next
		},

		/**
		 * @param list Tickets und Zähler vom Server.
		 */
		applyTickets(list: TicketList): void {
			this.tickets = new Map(list.tickets.map((ticket) => [ticket.id, ticket]))
			this.counts = list.counts
			// Gerechnet, nie gespeichert — kommt mit derselben Antwort wie die
			// Tickets, aus denselben Schritten wie die Zähler.
			this.waiting = list.waiting ?? {}
			// „Seit deinem Blick geändert" (#79) — kommt mit derselben Antwort.
			this.changed = list.changed ?? {}

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
		 * Der Filter „Nur wartend" greift hier und nicht in einer eigenen
		 * Abfrage: Der Zustand liegt **quer** zu den Spalten, und eine zweite
		 * Abfrage wäre ein zweiter Ort, an dem die Sichtbarkeit stimmen müsste.
		 * Dasselbe gilt für das Ausblenden älterer Erledigter (#59).
		 *
		 * **Die beiden schließen einander aus, und das ist kein Zufall:** Ein
		 * geschlossener Vorgang wartet nie (E8). Ist „Nur wartend" an, ist
		 * ohnehin nichts Geschlossenes übrig, das man ausblenden könnte.
		 *
		 * @param columnId Kennung der Spalte.
		 */
		ticketsIn(columnId: number): Ticket[] {
			const inColumn = this.visibleIn(columnId)

			if (this.onlyWaiting) {
				return inColumn.filter((ticket) => this.waiting[ticket.id] !== undefined)
			}

			return this.expandedColumns.includes(columnId)
				? inColumn
				: this.withoutOlderClosed(inColumn)
		},

		/**
		 * Alles, was dieser Betrachter in der Spalte sehen darf — ungefiltert
		 * durch die Zustände der Ansicht.
		 *
		 * Grundlage sowohl für `ticketsIn()` als auch für
		 * `hiddenClosedCount()`. „Ungefiltert" heißt hier ausdrücklich
		 * **nicht** „an der Sichtbarkeitsregel vorbei": Die Menge kommt aus
		 * `columnOrder`, und die stammt aus der bereits gefilterten Antwort des
		 * Servers.
		 *
		 * @param columnId Kennung der Spalte.
		 */
		visibleIn(columnId: number): Ticket[] {
			return (this.columnOrder.get(columnId) ?? [])
				.map((id) => this.tickets.get(id))
				.filter((t): t is Ticket => t !== undefined)
		},

		/**
		 * Von den geschlossenen Vorgängen bleiben die zuletzt geschlossenen
		 * `CLOSED_TAIL` stehen — offene sind nie betroffen.
		 *
		 * **Anzahl statt Alter.** Unbedienbar macht ein Board die Menge der
		 * Erledigten, nicht ihr Alter; eine Zeitgrenze verhielte sich
		 * ausgerechnet an den beiden Enden falsch, die sie beheben soll. Auf
		 * einem vielbeschäftigten Board wären zweihundert Vorgänge aus drei
		 * Wochen alle jünger als die Grenze und die Spalte liefe trotzdem über;
		 * auf einem ruhigen verschwänden die drei Erledigten vom Frühjahr,
		 * obwohl sie niemanden stören. Die Anzahl regelt sich von selbst.
		 *
		 * **Gerechnet wird je Betrachter auf der gefilterten Menge** — „die
		 * letzten zehn, *die du siehst*". Das ist bewusst die **umgekehrte**
		 * Entscheidung zu §3.8, wo Positionen aus der ungefilterten Liste
		 * stammen: Positionen müssen für alle gleich sein, sonst sähen zwei
		 * Personen dieselbe Spalte verschieden sortiert. Hier geht es darum, was
		 * **dieser** Betrachter überblickt, und ein Zähler über die ungefilterte
		 * Menge wäre genau das Leck aus §5.8.
		 *
		 * **Ohne den Aufklappzustand.** Die Frage „was würde eingeklappt
		 * wegfallen" muss sich auch dann beantworten lassen, wenn die Spalte
		 * gerade offen steht — sonst weiß niemand, ob der Umschalter überhaupt
		 * etwas zu tun hätte.
		 *
		 * @param inColumn Die sichtbaren Vorgänge der Spalte, in Serverreihenfolge.
		 */
		withoutOlderClosed(inColumn: Ticket[]): Ticket[] {
			const closed = inColumn.filter((ticket) => ticket.closedAt !== null)
			if (closed.length <= CLOSED_TAIL) {
				return inColumn
			}

			// Nach Schliessdatum, das jüngste zuerst; bei gleichem Datum
			// entscheidet die höhere Kennung. Ohne diesen zweiten Schlüssel
			// wäre die Auswahl bei gleichzeitig geschlossenen Vorgängen von der
			// Sortierstabilität abhängig — und damit vom Browser.
			//
			// **Der Zeitpunkt wird einmal je Vorgang ausgerechnet, nicht bei
			// jedem Vergleich.** Ein Zeichenvergleich auf `closedAt` wäre
			// schneller, aber nur dann chronologisch, wenn alle Werte denselben
			// Zeitzonenversatz tragen: ATOM schreibt ihn mit, und
			// `…T02:30:00+02:00` steht als Zeichenkette vor `…T02:30:00+01:00`,
			// obwohl es später liegt. Nextcloud läuft zwar auf UTC
			// (`base.php`: `date_default_timezone_set('UTC')`), aber eine
			// Sortierung, die an dieser Einstellung hängt, ist eine Kopplung,
			// die niemand sieht. Ein `Date.parse()` je Vorgang ist billiger als
			// ein Vergleich je Paar und braucht die Annahme nicht.
			const geschlossenAm = new Map(closed.map((ticket) => [ticket.id, Date.parse(ticket.closedAt ?? '')]))

			const keep = new Set(closed
				.sort((a, b) => (geschlossenAm.get(b.id) ?? 0) - (geschlossenAm.get(a.id) ?? 0) || b.id - a.id)
				.slice(0, CLOSED_TAIL)
				.map((ticket) => ticket.id))

			// Die Reihenfolge bleibt die der Spalte, nicht die des Schliessens:
			// Ausgewählt wird nach Datum, angezeigt wird nach Position.
			return inColumn.filter((ticket) => ticket.closedAt === null || keep.has(ticket.id))
		},

		/**
		 * Wie viele Vorgänge das Einklappen zurückhielte — **unabhängig davon,
		 * ob die Spalte gerade offen steht**.
		 *
		 * Das ist die Frage, an der der Umschalter hängt: Gibt es überhaupt
		 * etwas zu klappen? Ohne sie stünde über einer leeren Spalte ein Knopf
		 * „Ältere wieder ausblenden", der nichts tut.
		 *
		 * Als **Differenz** derselben Rechnung, nicht als eigene Bedingung: Ein
		 * Zähler mit eigener Regel wäre der zweite Ort, an dem sie stimmen
		 * müsste — und §5.8 nennt Zähler ausdrücklich.
		 *
		 * @param columnId Kennung der Spalte.
		 */
		collapsibleCount(columnId: number): number {
			// Bei „Nur wartend" ist nichts Geschlossenes in der Menge; ein
			// Angebot „12 ältere anzeigen" führte dann ins Leere.
			if (this.onlyWaiting) {
				return 0
			}

			const inColumn = this.visibleIn(columnId)

			return inColumn.length - this.withoutOlderClosed(inColumn).length
		},

		/**
		 * Wie viele Vorgänge die Spalte **gerade** zurückhält — null, solange
		 * sie aufgeklappt ist.
		 *
		 * @param columnId Kennung der Spalte.
		 */
		hiddenClosedCount(columnId: number): number {
			return this.expandedColumns.includes(columnId) ? 0 : this.collapsibleCount(columnId)
		},

		/**
		 * Ältere Erledigte einer Spalte aufklappen oder wieder einklappen.
		 *
		 * Ein Zustand der **Ansicht**, keine zweite Abfrageform: Die Vorgänge
		 * sind längst da, sie werden nur nicht gezeigt.
		 *
		 * @param columnId Kennung der Spalte.
		 */
		toggleOlder(columnId: number): void {
			// Neues Array statt `push`/`splice`: Dieselbe Vorsicht wie bei den
			// Maps oben — sonst bleibt das Neuzeichnen aus.
			this.expandedColumns = this.expandedColumns.includes(columnId)
				? this.expandedColumns.filter((id) => id !== columnId)
				: [...this.expandedColumns, columnId]
		},
	},
})
