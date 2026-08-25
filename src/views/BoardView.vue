<template>
	<div class="pw-view" :style="{ '--pw-columns': store.columns.length || 1 }">
		<div class="pw-view__head">
			<h2>{{ store.board?.title ?? t('projektwerk', 'Projekt') }}</h2>

			<NcButton variant="primary" :disabled="store.columns.length === 0" @click="creating = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Neuer Vorgang') }}
			</NcButton>

			<!--
				Der Weg in die Einstellungen steht nur internen Mitgliedern mit
				Verwaltungsrecht offen (§8) — wer ihn nicht hat, sieht keinen
				Knopf statt einer Absage.
			-->
			<NcButton
				v-if="store.viewer?.isManager"
				:aria-label="t('projektwerk', 'Projekteinstellungen')"
				@click="$router.push({ name: 'board-settings', params: { boardId: String(boardId) } })">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcButton>

			<!-- Ohne hinterlegte Adresse entfaellt der Knopf ersatzlos (§9). -->
			<NcButton
				v-if="store.board?.chatUrl"
				:href="store.board.chatUrl"
				target="_blank"
				rel="noopener">
				{{ t('projektwerk', 'Zum Projektchat') }}
			</NcButton>

			<!--
				Kein eigener Bereich fuer „wartend": Der Zustand liegt quer zu
				den Spalten, und eine eigene Ansicht risse ihn aus dem
				Zusammenhang, in dem er entsteht.
			-->
			<NcButton
				v-if="store.waitingCount > 0 || store.onlyWaiting"
				:variant="store.onlyWaiting ? 'primary' : 'secondary'"
				@click="store.onlyWaiting = !store.onlyWaiting">
				<template #icon>
					<ClockAlertIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Nur wartend') }} ({{ store.waitingCount }})
			</NcButton>

			<p v-if="orgLine" class="pw-view__sub">
				{{ orgLine }}
			</p>
		</div>

		<div v-if="store.loading" class="pw-board">
			<div v-for="n in 3" :key="n" class="pw-col">
				<div class="pw-col__head">
&nbsp;
				</div>
				<div class="pw-stack">
					<div class="pw-skel">
						<i /><i /><i />
					</div>
					<div class="pw-skel">
						<i /><i /><i />
					</div>
				</div>
			</div>
		</div>

		<NcEmptyContent
			v-else-if="store.columns.length === 0"
			:name="t('projektwerk', 'Noch keine Spalten')"
			:description="t('projektwerk', 'Legen Sie in den Projekteinstellungen die erste Spalte an.')">
			<template #icon>
				<FolderMultipleIcon :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="pw-board">
			<!--
					Eine Spalte je Eintrag aus `columnViews` statt aus
					`store.columns`: Die Vorlage rief `ticketsIn()` sonst
					fuenfmal je Spalte und Neuzeichnen (Kopfzahl, Karten,
					Knopf, Beschriftung, Leerzustand), und jeder Aufruf
					sortierte die Erledigten neu. Einmal rechnen, fuenfmal
					lesen.
				-->
			<div v-for="view in columnViews" :key="view.column.id" class="pw-col">
				<div class="pw-col__head">
					{{ view.column.title }}
					<!--
						Die Zahl nennt die Spalte, nicht den Ausschnitt: Sie
						folgt weder dem Filter noch dem Einklappen. Sonst bliebe
						sie bei zehn stehen, waehrend das Team dreissig Vorgaenge
						abschliesst — und widerspraeche der Zahl, die die
						Rueckfrage beim Entfernen derselben Spalte nennt.
					-->
					<span class="pw-n">{{ view.total }}</span>
				</div>
				<div class="pw-stack">
					<!--
						Der Zieh-Aufsatz (#11, 7a) umschließt nur die Karten, nicht
						den „Ältere anzeigen"-Knopf oder den Leerzustand. Er rendert
						die Karten selbst und zieht ihre Daten aus demselben Store.
						Das Ziehen (`dragmove`) ruft dieselbe `moveTicket`-Kette wie
						das Menü (`menumove`) — nur mit genauer Zielposition statt
						„ans Ende".
					-->
					<BoardDragLayer
						:tickets="view.tickets"
						:columnId="view.column.id"
						:showVisibility="showVisibility"
						:highlightId="highlightId"
						@open="openTicket"
						@menumove="move"
						@dragmove="moved"
						@toggleclosed="toggleClosed"
						@delete="deleteTicket" />

					<!--
						Ältere Erledigte (#59). Kein Archiv als Ablageort:
						`closed_at` bleibt die einzige Wahrheit, das Aufklappen
						ist ein Zustand DIESER Ansicht. Die Vorgaenge sind
						laengst geladen — es gibt keine zweite Abfrageform.

						**Ein einziger Knopf, kein v-if/v-else-if-Paar.** Zwei
						Zweige waeren zwei Elemente: Vue haengt das eine aus und
						das andere ein, und der Tastaturfokus faellt dabei auf
						den `body` zurueck — wer sich durch eine lange Spalte
						getabbt hat, faengt nach dem Klick von vorn an. Tastatur
						und Screenreader sind Abnahmekriterium, nicht
						Nachruestung.

						Gezeigt wird er an `collapsibleCount`, nicht an
						`hiddenClosedCount`: Die Frage ist „gibt es ueberhaupt
						etwas zu klappen", nicht „ist gerade etwas verborgen".
						Sonst stuende er auch ueber einer Spalte, in der nichts
						zu verbergen ist, und taete nichts.
					-->
					<NcButton
						v-if="view.collapsible > 0"
						variant="tertiary"
						class="pw-older"
						:aria-expanded="view.expanded"
						@click="store.toggleOlder(view.column.id)">
						{{ view.expanded ? t('projektwerk', 'Ältere wieder ausblenden') : olderLabel(view.hidden) }}
					</NcButton>

					<!-- Leerzustaende sprechen (§9) — auch der gefilterte. -->
					<div v-if="view.tickets.length === 0" class="pw-empty">
						{{ store.onlyWaiting
							? t('projektwerk', 'Hier wartet nichts.')
							: t('projektwerk', 'Hier liegt gerade nichts.') }}
					</div>
				</div>
			</div>
		</div>

		<TicketDetail
			:ticket="openTicketData"
			:columns="store.columns"
			:members="store.members"
			:viewer="store.viewer"
			:orgInternal="store.board?.orgInternal ?? ''"
			:orgExternal="store.board?.orgExternal ?? ''"
			:showVisibility="showVisibility"
			:fromClientSide="!store.isInternal"
			:githubEnabled="store.board?.githubEnabled ?? false"
			:githubRepo="store.board?.githubRepo ?? ''"
			:steps="openSteps"
			:comments="openComments"
			:attachments="openAttachments"
			:waiting="openTicketData ? (store.waiting[openTicketData.id] ?? null) : null"
			@close="openTicketData = null"
			@changed="applyChanged"
			@delete="deleteTicket"
			@stepsChanged="reloadOpenTicket"
			@commentsChanged="reloadOpenTicket"
			@attachmentsChanged="reloadOpenTicket" />

		<CreateTicketDialog
			:open="creating"
			:boardId="boardId"
			:columns="store.columns"
			:members="store.members"
			:orgInternal="store.board?.orgInternal ?? ''"
			:orgExternal="store.board?.orgExternal ?? ''"
			@update:open="creating = $event"
			@create="create" />
	</div>
</template>

<script lang="ts">
import type { Column, Visibility } from '@/types/board'
import type { Attachment, Comment, Step, Ticket } from '@/types/ticket'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ClockAlertIcon from 'vue-material-design-icons/ClockAlertOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import BoardDragLayer from '@/components/board/BoardDragLayer.vue'
import CreateTicketDialog from '@/components/CreateTicketDialog.vue'
import TicketDetail from '@/components/TicketDetail.vue'
import { deleteTicket as apiDeleteTicket, restoreTicket as apiRestoreTicket, createTicket, fetchTicket, updateTicket } from '@/services/tickets'
import { showError, showUndo } from '@/services/toast'
import { isConflict, reportWriteError } from '@/services/writeError'
import { useBoardStore } from '@/stores/boardStore'

/** Was die Vorlage über eine Spalte wissen muss — einmal je Neuzeichnen. */
interface ColumnView {
	column: Column
	/** Der sichtbare Ausschnitt: Filter und Einklappen bereits angewandt. */
	tickets: Ticket[]
	/** Die Größe der Spalte für diesen Betrachter — ohne beides. */
	total: number
	/** Wie viele gerade zurückgehalten werden. */
	hidden: number
	/** Wie viele das Einklappen zurückhielte — auch wenn gerade aufgeklappt. */
	collapsible: number
	expanded: boolean
}

export default defineComponent({
	name: 'BoardView',

	components: { BoardDragLayer, ClockAlertIcon, CogIcon, CreateTicketDialog, FolderMultipleIcon, NcButton, NcEmptyContent, PlusIcon, TicketDetail },

	setup() {
		return { store: useBoardStore() }
	},

	data() {
		return {
			creating: false,
			openTicketData: null as Ticket | null,
			openSteps: [] as Step[],
			openComments: [] as Comment[],
			openAttachments: [] as Attachment[],
			// Die frisch angelegte Karte, die gerade kurz hervorgehoben wird (#165),
			// und ihr Ablauf-Timer — beides rein lokal und vergänglich.
			highlightId: null as number | null,
			highlightTimer: null as number | null,
		}
	},

	computed: {
		boardId(): number {
			return Number(this.$route.params.boardId)
		},

		/**
		 * Die Kennzeichnung gibt es nur fuer die interne Seite — und nur, wenn
		 * das Board ueberhaupt externe Mitglieder hat. Sonst gibt es niemanden,
		 * vor dem etwas verborgen waere, und sie waere Rauschen (§9).
		 */
		showVisibility(): boolean {
			return this.store.isInternal && this.store.hasExternalMembers
		},

		orgLine(): string {
			const board = this.store.board
			return board === null ? '' : this.store.orgLine(board)
		},

		/**
		 * Je Spalte einmal alles, was die Vorlage über sie wissen muss.
		 *
		 * `total` ist die Größe der Spalte für diesen Betrachter und folgt
		 * **weder** dem Filter **noch** dem Einklappen — sie ist die Zahl in der
		 * Kopfzeile. `tickets` ist der Ausschnitt, der gerade zu sehen ist.
		 * Beide auseinanderzuhalten ist der Punkt: Sonst bliebe die Kopfzahl bei
		 * zehn stehen, während das Team dreißig Vorgänge abschließt.
		 */
		columnViews(): ColumnView[] {
			return this.store.columns.map((column) => ({
				column,
				tickets: this.store.ticketsIn(column.id),
				total: this.store.visibleIn(column.id).length,
				hidden: this.store.hiddenClosedCount(column.id),
				collapsible: this.store.collapsibleCount(column.id),
				expanded: this.store.expandedColumns.includes(column.id),
			}))
		},
	},

	watch: {
		boardId: {
			immediate: true,
			async handler(id: number) {
				await this.store.open(id)
				this.openFromQuery()
			},
		},
	},

	beforeUnmount() {
		// Den Hervorhebungs-Timer nicht über das Verlassen der Ansicht hinaus
		// weiterlaufen lassen (#165).
		if (this.highlightTimer !== null) {
			window.clearTimeout(this.highlightTimer)
		}
	},

	methods: {
		t,

		/**
		 * „N ältere anzeigen" — mit Zahl, weil eine Zahl die Frage beantwortet,
		 * die der Knopf sonst aufwirft.
		 *
		 * Die Zahl stammt aus derselben Rechnung wie das Ausblenden und kennt
		 * damit nur, was dieser Betrachter ohnehin sehen darf (§5.8).
		 *
		 * @param anzahl Wie viele Vorgänge die Spalte gerade zurückhält.
		 */
		olderLabel(anzahl: number): string {
			return n('projektwerk', '%n älteren Vorgang anzeigen', '%n ältere Vorgänge anzeigen', anzahl)
		},

		/**
		 * Verschieben über das Kartenmenü: ans Ende der Zielspalte.
		 *
		 * Der Aufrufer nennt keine Position, sondern den letzten Nachbarn dort
		 * — das ist derselbe Weg, den später auch Drag & Drop nimmt.
		 *
		 * **Der Nachbar kommt aus `visibleIn()`, nicht aus `ticketsIn()`.** Der
		 * Unterschied sind die Zustände der Ansicht: Bei „Nur wartend" oder mit
		 * eingeklappten älteren Erledigten ist das letzte *angezeigte* Ticket
		 * nicht das letzte der Spalte, und das Ticket landete mittendrin statt
		 * am Ende. Was die Ansicht gerade verbirgt, darf die Sortierung nicht
		 * verschieben.
		 *
		 * @param payload Ticket und Zielspalte.
		 * @param payload.ticket
		 * @param payload.columnId
		 */
		async move(payload: { ticket: Ticket, columnId: number }) {
			const inTarget = this.store.visibleIn(payload.columnId)
			const last = inTarget.length > 0 ? inTarget[inTarget.length - 1].id : null

			try {
				await this.store.moveTicket(payload.ticket.id, payload.columnId, last, null)
			} catch (e) {
				// Beim Konflikt wird nachgeladen statt zum Neuladen aufgefordert:
				// Hier liegt das ganze Board im Speicher, und ein veralteter
				// `version`-Wert liesse jeden weiteren Versuch scheitern — auch
				// den an einer ganz anderen Karte.
				if (isConflict(e)) {
					await this.store.open(this.boardId)
				}
				reportWriteError(e, t('projektwerk', 'Verschieben fehlgeschlagen'), isConflict(e))
			}
		},

		/**
		 * Verschieben per Drag & Drop (#11, 7a) — genaue Zielposition.
		 *
		 * Anders als das Menü (ans Ende) nennt das Ziehen die **Nachbarn** der
		 * Ablegestelle. Es ruft aber dieselbe `moveTicket`-Kette und behandelt den
		 * Konflikt gleich: Beim 409 wird nachgeladen, nicht zum Neuladen
		 * aufgefordert — auch damit der Spiegel im Zieh-Aufsatz wieder auf den
		 * Serverstand fällt.
		 *
		 * @param payload Ticket, Zielspalte und die beiden Nachbarn.
		 * @param payload.ticketId Kennung des gezogenen Vorgangs.
		 * @param payload.targetColumnId Zielspalte.
		 * @param payload.beforeId Nachbar darüber oder null.
		 * @param payload.afterId Nachbar darunter oder null.
		 */
		async moved(payload: { ticketId: number, targetColumnId: number, beforeId: number | null, afterId: number | null }) {
			try {
				await this.store.moveTicket(payload.ticketId, payload.targetColumnId, payload.beforeId, payload.afterId)
			} catch (e) {
				if (isConflict(e)) {
					await this.store.open(this.boardId)
				}
				reportWriteError(e, t('projektwerk', 'Verschieben fehlgeschlagen'), isConflict(e))
			}
		},

		async openTicket(ticket: Ticket) {
			this.openTicketData = ticket
			// Sofort leeren, nicht erst nach dem Laden: Sonst zeigt das Overlay
			// kurz die Arbeitsschritte und Kommentare des vorigen Tickets unter
			// dem neuen Titel.
			this.openSteps = []
			this.openComments = []
			this.openAttachments = []
			// Öffnen heisst gelesen (#79): Der Punkt geht sofort aus. Ohne await
			// — der Vermerk soll das Laden des Vorgangs nicht aufhalten.
			this.store.markRead(ticket.id)
			await this.loadDetail(ticket.id)
		},

		/**
		 * Die Kinder des geöffneten Vorgangs nachladen.
		 *
		 * Über `ticket#show`, weil Schritte und Kommentare dort aus der
		 * gefilterten Einermenge kommen — es gibt keinen Weg, „die Kommentare zu
		 * Ticket 42" zu laden, der nicht durch die Sichtbarkeit geht.
		 *
		 * @param ticketId Kennung des Vorgangs.
		 */
		async loadDetail(ticketId: number) {
			try {
				const detail = await fetchTicket(this.boardId, ticketId)
				this.openSteps = detail.steps
				this.openComments = detail.comments
				this.openAttachments = detail.attachments
			} catch {
				this.openSteps = []
				this.openComments = []
				this.openAttachments = []
			}
		},

		/**
		 * Nach einer Änderung im Overlay: Detail und Board neu laden.
		 *
		 * Beides, weil eine Zuweisung den Wartezustand ändert und ein Kommentar
		 * den Zähler — und die stehen auf der Karte, nicht nur im Overlay.
		 */
		async reloadOpenTicket() {
			const offen = this.openTicketData
			if (offen === null) {
				return
			}
			await this.store.open(this.boardId)
			await this.loadDetail(offen.id)
			this.openTicketData = this.store.tickets.get(offen.id) ?? offen
		},

		/**
		 * Den Vorgang öffnen, den ein Deep-Link genannt hat.
		 *
		 * Läuft **nach** dem Laden, weil das Overlay das Ticket aus dem Speicher
		 * nimmt. Fehlt es dort, passiert nichts weiter: Der Server hat die
		 * Sichtbarkeit bereits geprüft, ein Fehlschlag hier wäre eine
		 * zwischenzeitliche Änderung und keine Auskunft wert — das Board steht
		 * dann eben offen, ohne Overlay.
		 *
		 * Die Kennung bleibt danach in der Adresse stehen. Das ist gewollt: So
		 * trägt auch der kopierte Hash-Link innerhalb der App.
		 */
		openFromQuery() {
			const wanted = Number(this.$route.query.ticket)
			if (!Number.isInteger(wanted) || wanted <= 0) {
				return
			}

			const ticket = this.store.tickets.get(wanted)
			if (ticket !== undefined) {
				this.openTicketData = ticket
			}
		},

		/**
		 * Ein geändertes Ticket in Karte und Overlay übernehmen.
		 *
		 * Beide Stellen, weil beide denselben Stand zeigen: Das Overlay hält
		 * eine eigene Referenz, die sonst auf dem alten `version`-Wert stünde —
		 * und der nächste Schreibversuch aus dem offenen Overlay liefe damit in
		 * einen 409, obwohl niemand sonst etwas geändert hat.
		 *
		 * @param ticket Der neue Stand vom Server.
		 */
		applyChanged(ticket: Ticket) {
			this.store.replaceTicket(ticket)
			this.openTicketData = ticket
		},

		/**
		 * Einen Vorgang vom Karten-Menü aus abschließen oder wieder öffnen (#168) —
		 * derselbe offen↔geschlossen-Übergang wie der Knopf im Detail.
		 *
		 * **Das Overlay geht nur mit, wenn genau dieser Vorgang offen ist.** Sonst
		 * risse ein Klick im Karten-Menü das Detail auf — `applyChanged` setzt
		 * `openTicketData` bedingungslos und taugt hier deshalb nicht.
		 *
		 * @param ticket Der Vorgang, dessen Abschluss umgeschaltet wird.
		 * @param outcome Beim Abschließen das gewählte Ergebnis (#171); beim Wieder-öffnen weggelassen.
		 */
		async toggleClosed(ticket: Ticket, outcome?: 'done' | 'discarded') {
			const schliessen = ticket.closedAt === null

			try {
				const updated = await updateTicket(
					ticket.boardId,
					ticket.id,
					ticket.version,
					// Das Ergebnis begleitet nur das Abschließen (#171); beim
					// Wieder-öffnen löscht der Server es ohnehin.
					schliessen ? { closed: true, outcome } : { closed: false },
				)
				this.store.replaceTicket(updated)
				if (this.openTicketData?.id === updated.id) {
					this.openTicketData = updated
				}
			} catch (e) {
				// Beim Konflikt wird nachgeladen statt zum Neuladen aufgefordert,
				// aus demselben Grund wie bei move()/moved(): Das ganze Board
				// steht im Speicher, ein veralteter `version`-Wert liesse jeden
				// weiteren Versuch scheitern — auch an einer ganz anderen Karte.
				if (isConflict(e)) {
					await this.store.open(this.boardId)
				}
				reportWriteError(e, schliessen
					? t('projektwerk', 'Abschließen fehlgeschlagen')
					: t('projektwerk', 'Wieder öffnen fehlgeschlagen'), isConflict(e))
			}
		},

		/**
		 * Einen Vorgang weich löschen und einen Undo-Toast anbieten (#167).
		 *
		 * Sofort löschen (der Server ist die Wahrheit), Detail schließen falls
		 * offen, Board neu laden — der Vorgang fällt aus der Ansicht. Der Toast
		 * hält die Kennung; ein Klick holt ihn über `restore` zurück. Kommt aus
		 * dem Karten-Menü UND aus dem Detail an genau dieser Stelle zusammen,
		 * damit Toast und Neuladen nicht doppelt liegen.
		 *
		 * @param ticket Der zu löschende Vorgang.
		 */
		async deleteTicket(ticket: Ticket) {
			try {
				await apiDeleteTicket(ticket.boardId, ticket.id, ticket.version)

				if (this.openTicketData?.id === ticket.id) {
					this.openTicketData = null
				}
				await this.store.open(this.boardId)

				showUndo(
					t('projektwerk', 'Vorgang gelöscht'),
					() => { void this.restoreTicket(ticket) },
				)
			} catch (e) {
				// Konflikt: nachladen, aus demselben Grund wie oben.
				if (isConflict(e)) {
					await this.store.open(this.boardId)
				}
				reportWriteError(e, t('projektwerk', 'Löschen fehlgeschlagen'), isConflict(e))
			}
		},

		/**
		 * Ein zuvor gelöschtes Ticket zurückholen (#167, Undo-Klick). Idempotent
		 * und ohne Version; danach neu laden, damit die Karte wieder erscheint.
		 *
		 * @param ticket Der wiederherzustellende Vorgang.
		 */
		async restoreTicket(ticket: Ticket) {
			try {
				await apiRestoreTicket(ticket.boardId, ticket.id)
				await this.store.open(this.boardId)
			} catch (e) {
				reportWriteError(e, t('projektwerk', 'Wiederherstellen fehlgeschlagen'))
			}
		},

		async create(data: { title: string, description: string | null, visibility: Visibility, columnId: number, dueDate: string | null, responsibleUserId: string | null, openAfter: boolean }) {
			try {
				// `openAfter` ist rein lokale UI-Wahl (#165) und kein Feld der
				// Ticket-API — nicht mit ins Anfrage-Objekt nehmen.
				const { openAfter, ...ticketData } = data
				const angelegt = await createTicket(this.boardId, ticketData)
				this.creating = false
				await this.store.open(this.boardId)
				// Den frischen Stand aus dem Speicher nehmen, damit `version` stimmt
				// und der nächste Schreibweg nicht in einen 409 läuft.
				const frisch = this.store.tickets.get(angelegt.id) ?? angelegt

				if (openAfter) {
					// #146-Weg (Variante a), jetzt als bewusste Wahl (#165): direkt
					// ins Detail, wo Anhänge und Arbeitsschritte „wie im Detail"
					// möglich sind — ohne den schlanken Anlege-Dialog (#100) dafür
					// aufzublähen.
					await this.openTicket(frisch)
				} else {
					// „Anlegen" ohne Sprung (#165): auf dem Board bleiben und die neue
					// Karte kurz hervorheben, damit „wo ist mein Vorgang" ohne ein
					// zweites Modal beantwortet ist.
					this.highlightNew(frisch.id)
				}
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Anlegen fehlgeschlagen'))
			}
		},

		/**
		 * Die frisch angelegte Karte für kurze Zeit markieren (#165). Ein rein
		 * lokaler, vergänglicher Zustand — nichts am Vorgang, nichts am Server;
		 * der neutrale „seit deinem Blick geändert"-Punkt (#79) bleibt davon
		 * unberührt.
		 *
		 * @param ticketId Die Kennung der neuen Karte.
		 */
		highlightNew(ticketId: number): void {
			if (this.highlightTimer !== null) {
				window.clearTimeout(this.highlightTimer)
			}
			this.highlightId = ticketId
			this.highlightTimer = window.setTimeout(() => {
				this.highlightId = null
				this.highlightTimer = null
			}, 1500)
		},
	},
})
</script>
