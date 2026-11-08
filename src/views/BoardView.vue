<template>
	<div class="pw-view" :style="{ '--pw-columns': store.columns.length || 1 }">
		<!--
			Brotkrume zurück zur Projektübersicht (#245) — dieselbe Form wie im
			Projekt-Dashboard, ein echtes Ziel statt eines Zurück-Rätsels.
		-->
		<router-link
			class="pw-crumb"
			:to="{ name: 'project-dashboard', params: { boardId: String(boardId) } }">
			<ChevronLeftIcon :size="16" />{{ t('projektwerk', 'Projektübersicht') }}
		</router-link>

		<div class="pw-view__head pw-view__head--split">
			<div class="pw-view__ident">
				<!-- #350: Der Kopf nennt das Projekt; welches Board offen ist, zeigen die Reiter. -->
				<h2>{{ store.projectTitle || store.board?.title || t('projektwerk', 'Projekt') }}</h2>
				<div v-if="orgLine" class="pw-view__org">
					{{ orgLine }}
				</div>
			</div>

			<div class="pw-view__actions">
				<NcButton variant="primary" :disabled="store.columns.length === 0" @click="neuerVorgang(null)">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('projektwerk', 'Neuer Vorgang') }}
				</NcButton>

				<!--
					Der Weg in die Einstellungen steht Managern offen — und seit
					#281 auch dem Ersteller eines Boards, der dort nur seinen
					board-scopeden Teil (Titel, Spalten, Archiv) sieht.
				-->
				<NcButton
					v-if="store.canConfigureBoard"
					:aria-label="t('projektwerk', 'Projekteinstellungen')"
					@click="$router.push({ name: 'board-settings', params: { boardId: String(boardId) } })">
					<template #icon>
						<CogIcon :size="20" />
					</template>
				</NcButton>

				<!--
					Projektordner (#351): öffnet die Dateien-App am Ordner, über die
					Datei-ID (`/f/<id>`), weil der Pfad je Person anders heißen kann.
					Ohne hinterlegten Ordner entfällt der Knopf.
				-->
				<NcButton
					v-if="store.projectFolder"
					:href="projectFolderUrl"
					target="_blank"
					rel="noopener">
					<template #icon>
						<FolderOpenIcon :size="20" />
					</template>
					{{ t('projektwerk', 'Projektordner') }}
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

				<!-- Suche im Board (#373): grenzt die Karten im Browser ein, die Spalten bleiben stehen. -->
				<NcTextField
					v-model="searchInput"
					class="pw-board-search"
					:labelOutside="true"
					:aria-label="t('projektwerk', 'Vorgänge durchsuchen')"
					:placeholder="t('projektwerk', 'Vorgänge durchsuchen')"
					:showTrailingButton="searchInput !== ''"
					trailingButtonIcon="close"
					:trailingButtonLabel="t('projektwerk', 'Suche zurücksetzen')"
					@update:modelValue="onSearchInput"
					@trailingButtonClick="clearSearch"
					@keydown.esc="clearSearch">
					<template #icon>
						<MagnifyIcon :size="20" />
					</template>
				</NcTextField>
			</div>
		</div>

		<!--
			Die Boards des Projekts als Reiter (#350, vorher ein Menü „Boards"):
			Zustand und Wechsel in einem Element. Derselbe Umschalter wie die
			Sichtbarkeit im Vorgang, kein zweites Muster. Erscheint ab zwei Boards;
			wer ein Board anlegen darf, sieht ihn auch bei einem, mit „+" dahinter.
		-->
		<div v-if="store.siblingBoards.length > 1 || store.canAddBoard" class="pw-boardtabs">
			<div class="pw-boardtabs__group">
				<NcRadioGroup
					:modelValue="String(boardId)"
					:label="t('projektwerk', 'Boards')"
					:hideLabel="true"
					@update:modelValue="switchBoard(Number($event))">
					<NcRadioGroupButton
						v-for="b in store.siblingBoards"
						:key="b.id"
						:value="String(b.id)"
						:label="b.title" />
				</NcRadioGroup>
			</div>
			<NcButton
				v-if="store.canAddBoard"
				class="pw-add-board"
				variant="tertiary"
				:aria-label="t('projektwerk', 'Board hinzufügen')"
				:title="t('projektwerk', 'Board hinzufügen')"
				@click="addingBoard = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
			</NcButton>
			<NcActions
				v-if="store.canConfigureBoard && store.siblingBoards.length > 1"
				:forceMenu="true"
				:ariaLabel="t('projektwerk', 'Aktionen für dieses Board')">
				<NcActionButton
					:disabled="!store.deletable"
					:description="store.deletable ? '' : t('projektwerk', 'Enthält Vorgänge. Verschieben oder Board archivieren.')"
					:closeAfterClick="true"
					@click="deletingBoard = true">
					<template #icon>
						<DeleteOutlineIcon :size="20" />
					</template>
					{{ t('projektwerk', 'Board „{title}“ löschen', { title: store.board?.title ?? '' }, undefined, { escape: false }) }}
				</NcActionButton>
			</NcActions>
		</div>

		<!-- Leerzustand der Suche (#373): einmal über dem Board, die Spalten bleiben darunter stehen. -->
		<!-- Die Live-Region steht immer da: Eine mit ihrem Text eingefügte sagen Screenreader oft nicht an. -->
		<div role="status">
			<div v-if="pendingCount > 0" class="pw-outbox">
				<span>{{ n('projektwerk', '%n Änderung noch nicht gespeichert', '%n Änderungen noch nicht gespeichert', pendingCount) }}</span>
				<NcButton variant="tertiary" @click="outbox.flush()">
					{{ t('projektwerk', 'Jetzt senden') }}
				</NcButton>
			</div>
			<div v-if="failed.length > 0" class="pw-outbox pw-outbox--failed">
				<span>{{ n('projektwerk', '%n Änderung abgelehnt, bitte prüfen', '%n Änderungen abgelehnt, bitte prüfen', failed.length) }}</span>
				<ul class="pw-outbox__liste">
					<li v-for="entry in failed" :key="entry.id">
						<span>{{ describe(entry) }}: {{ entry.error }}</span>
						<NcButton variant="tertiary" @click="outbox.retry(entry.id)">
							{{ t('projektwerk', 'Erneut versuchen') }}
						</NcButton>
						<NcButton variant="tertiary" @click="outbox.discard(entry.id)">
							{{ t('projektwerk', 'Verwerfen') }}
						</NcButton>
					</li>
				</ul>
			</div>
			<div v-if="noSearchHits" class="pw-search-empty">
				<span>{{ t('projektwerk', 'Keine Vorgänge zu „{suche}“', { suche: store.search.trim() }, undefined, { escape: false }) }}</span>
				<NcButton variant="tertiary" @click="clearSearch">
					{{ t('projektwerk', 'Suche zurücksetzen') }}
				</NcButton>
			</div>
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
					<!-- Endspalten bekommen keins: Dort landet ein Vorgang durch Abschließen, nicht durch Anlegen. -->
					<NcButton
						v-if="!view.column.finalOutcome"
						class="pw-col__neu"
						variant="tertiary"
						:ariaLabel="t('projektwerk', 'Neuer Vorgang in „{spalte}“', { spalte: view.column.title }, undefined, { escape: false })"
						:title="t('projektwerk', 'Neuer Vorgang in „{spalte}“', { spalte: view.column.title }, undefined, { escape: false })"
						@click="neuerVorgang(view.column.id)">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
					</NcButton>
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

					<PendingTicketCard
						v-for="entry in pendingIn(view.column.id)"
						:key="entry.id"
						:entry="entry"
						@open="openPending" />

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
					<div v-if="view.tickets.length === 0 && pendingIn(view.column.id).length === 0" class="pw-empty">
						{{ store.searchTerms.length > 0
							? t('projektwerk', 'Kein Treffer.')
							: store.onlyWaiting
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
			:customer="store.board?.customer ?? ''"
			:showVisibility="showVisibility"
			:fromClientSide="!store.isInternal"
			:githubEnabled="store.board?.githubEnabled ?? false"
			:githubRepo="store.board?.githubRepo ?? ''"
			:steps="openSteps"
			:comments="openComments"
			:attachments="openAttachments"
			:dependsOn="openLinks.dependsOn"
			:neededBy="openLinks.neededBy"
			:boardTickets="boardTicketList"
			:waiting="openTicketData ? (store.waiting[openTicketData.id] ?? null) : null"
			@close="openTicketData = null"
			@changed="applyChanged"
			@delete="deleteTicket"
			@stepsChanged="reloadOpenTicket"
			@commentsChanged="reloadOpenTicket"
			@attachmentsChanged="reloadOpenTicket"
			@openTicket="openById"
			@linksChanged="openLinks = $event" />

		<CreateTicketDialog
			:open="creating"
			:boardId="boardId"
			:columns="store.columns"
			:members="store.members"
			:orgInternal="store.board?.orgInternal ?? ''"
			:customer="store.board?.customer ?? ''"
			:initialColumnId="createColumnId"
			@update:open="creating = $event"
			@create="create" />

		<!--
			**„Auch abschließen?" beim Ziehen in eine Endspalte** (#172). Kein
			automatisches Schließen — Verschieben und Abschließen bleiben zwei
			Handlungen; die App fragt nur kurz. Fängt die Gastfalle ab: Wer die
			Karte nach „Erledigt" zieht, denkt, sie sei fertig, obwohl der Vorgang
			noch offen ist. Das Ergebnis kommt aus der Spalte (#171).
		-->
		<NcDialog
			v-if="finalPrompt"
			:open="true"
			:name="t('projektwerk', 'Auch abschließen?')"
			size="normal"
			@update:open="finalPrompt = null">
			<div class="app-projektwerk">
				<p class="pw-detail__empty">
					{{ finalPromptText }}
				</p>
			</div>
			<template #actions>
				<NcButton @click="finalPrompt = null">
					{{ t('projektwerk', 'Nur verschieben') }}
				</NcButton>
				<NcButton variant="primary" @click="confirmFinalClose">
					{{ t('projektwerk', 'Abschließen') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Ein weiteres Board im selben Projekt anlegen (#246). -->
		<NcDialog
			v-if="addingBoard"
			:open="true"
			:name="t('projektwerk', 'Board hinzufügen')"
			size="normal"
			@update:open="closeAddBoard">
			<div class="app-projektwerk">
				<p class="pw-detail__empty">
					{{ t('projektwerk', 'Das neue Board gehört zu diesem Projekt und teilt sich Mitglieder, Ordner und Vorgangsnummern.') }}
				</p>
				<div class="pw-feld">
					<label class="pw-feld__label" for="pw-new-board-title">{{ t('projektwerk', 'Titel des Boards') }}</label>
					<NcTextField
						id="pw-new-board-title"
						v-model="newBoardTitle"
						:labelOutside="true"
						:label="t('projektwerk', 'Titel des Boards')"
						@keydown.enter="confirmAddBoard" />
				</div>
			</div>
			<template #actions>
				<NcButton @click="closeAddBoard">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="newBoardTitle.trim() === '' || addBusy"
					@click="confirmAddBoard">
					{{ t('projektwerk', 'Anlegen') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			v-if="deletingBoard"
			:open="true"
			:name="t('projektwerk', 'Board „{title}“ löschen?', { title: store.board?.title ?? '' }, undefined, { escape: false })"
			size="normal"
			@update:open="deletingBoard = false">
			<div class="app-projektwerk">
				<p class="pw-detail__empty">
					{{ t('projektwerk', 'Das Board enthält keine Vorgänge. Es wird mit seinen Spalten endgültig gelöscht; Dateien bleiben unberührt.') }}
				</p>
			</div>
			<template #actions>
				<NcButton @click="deletingBoard = false">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="deleteBusy"
					@click="confirmDeleteBoard">
					{{ t('projektwerk', 'Löschen') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script lang="ts">
import type { OutboxEntry, SavedEvent, TicketEntry } from '@/stores/outboxStore'
import type { Column, Visibility } from '@/types/board'
import type { Attachment, Comment, Step, Ticket, TicketLinks } from '@/types/ticket'

import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineComponent } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ClockAlertIcon from 'vue-material-design-icons/ClockAlertOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpenOutline.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import BoardDragLayer from '@/components/board/BoardDragLayer.vue'
import CreateTicketDialog from '@/components/CreateTicketDialog.vue'
import PendingTicketCard from '@/components/PendingTicketCard.vue'
import TicketDetail from '@/components/TicketDetail.vue'
import { deleteTicket as apiDeleteTicket, restoreTicket as apiRestoreTicket, fetchTicket, updateTicket } from '@/services/tickets'
import { showError, showUndo } from '@/services/toast'
import { isConflict, reportWriteError } from '@/services/writeError'
import { useBoardStore } from '@/stores/boardStore'
import { useOutboxStore } from '@/stores/outboxStore'

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

	components: { BoardDragLayer, ChevronLeftIcon, ClockAlertIcon, CogIcon, CreateTicketDialog, DeleteOutlineIcon, FolderMultipleIcon, FolderOpenIcon, MagnifyIcon, NcActionButton, NcActions, NcButton, NcDialog, NcEmptyContent, NcRadioGroup, NcRadioGroupButton, NcTextField, PendingTicketCard, PlusIcon, TicketDetail },

	setup() {
		return { store: useBoardStore(), outbox: useOutboxStore() }
	},

	data() {
		return {
			creating: false,
			createColumnId: null as number | null,
			openTicketData: null as Ticket | null,
			openSteps: [] as Step[],
			openComments: [] as Comment[],
			openAttachments: [] as Attachment[],
			openLinks: { dependsOn: [], neededBy: [] } as TicketLinks,
			// Die frisch angelegte Karte, die gerade kurz hervorgehoben wird (#165),
			// und ihr Ablauf-Timer — beides rein lokal und vergänglich.
			highlightId: null as number | null,
			highlightTimer: null as number | null,
			// Der „Auch abschließen?"-Prompt (#172): steht, wenn eine Karte gerade
			// in eine Endspalte gezogen/verschoben wurde und noch offen ist.
			finalPrompt: null as { ticket: Ticket, outcome: 'done' | 'discarded' } | null,
			// „Board hinzufügen" (#246): der Anlege-Dialog und sein Titelfeld.
			addingBoard: false,
			newBoardTitle: '',
			addBusy: false,
			deletingBoard: false,
			deleteBusy: false,
			// Das Suchfeld (#373): sofort, was getippt wird; der Store bekommt es entprellt.
			searchInput: '',
			searchTimer: null as number | null,
			unsubscribeOutbox: null as (() => void) | null,
			unsubscribeExternal: null as (() => void) | null,
			boardReloadTimer: null as number | null,
			refreshTimers: {} as Record<number, number>,
		}
	},

	computed: {
		/** Die geladenen Vorgänge als Auswahl für Abhängigkeiten. */
		boardTicketList(): Ticket[] {
			return [...this.store.tickets.values()]
		},

		/** Link in die Dateien-App am Projektordner (#351), über die Datei-ID. */
		projectFolderUrl(): string {
			return this.store.projectFolder ? generateUrl('/f/{id}', { id: this.store.projectFolder.id }) : ''
		},

		boardId(): number {
			return Number(this.$route.params.boardId)
		},

		/** Der Text des „Auch abschließen?"-Prompts (#172) — nach dem Ergebnis der Endspalte. */
		finalPromptText(): string {
			return this.finalPrompt?.outcome === 'discarded'
				? t('projektwerk', 'Diesen Vorgang auch als verworfen abschließen?')
				: t('projektwerk', 'Diesen Vorgang auch als erledigt abschließen?')
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

		pendingCount(): number {
			return this.outbox.pendingCountFor(this.boardId)
		},

		failed(): OutboxEntry[] {
			return this.outbox.failedFor(this.boardId)
		},

		/** Ob die Suche (#373) auf dem ganzen Board nichts findet. */
		noSearchHits(): boolean {
			return !this.store.loading
				&& this.store.searchTerms.length > 0
				&& this.columnViews.every((view) => view.tickets.length === 0)
		},
	},

	watch: {
		'outbox.entries': function() {
			const offen = this.openTicketData
			if (offen === null || offen.id >= 0 || this.outbox.ticketEntry(offen.id) !== undefined) {
				return
			}
			const realId = this.outbox.realId(offen.id)
			if (realId < 0) {
				// Verworfen, hier oder in einem anderen Tab: Das Fenster zeigte sonst einen Vorgang, den es nicht mehr gibt.
				this.openTicketData = null
			}
		},

		boardId: {
			immediate: true,
			async handler(id: number) {
				// Die Suche gilt der Ansicht, in der sie getippt wurde (#373) —
				// auch beim Zurückkommen aus den Einstellungen auf dasselbe Board,
				// wo `open()` sie nicht leert. Feld und Store sonst auseinander.
				this.clearSearch()
				await this.store.open(id)
				if (this.store.viewer !== null) {
					this.outbox.init(this.store.viewer.userId)
				}
				await this.openFromQuery()
			},
		},
	},

	mounted() {
		this.unsubscribeOutbox = this.outbox.subscribe((event) => this.onSaved(event))
		this.unsubscribeExternal = this.outbox.onExternalChange(() => this.onExternalOutboxChange())
	},

	beforeUnmount() {
		this.unsubscribeOutbox?.()
		this.unsubscribeExternal?.()
		if (this.boardReloadTimer !== null) {
			window.clearTimeout(this.boardReloadTimer)
		}
		for (const timer of Object.values(this.refreshTimers)) {
			window.clearTimeout(timer)
		}
		// Den Hervorhebungs-Timer nicht über das Verlassen der Ansicht hinaus
		// weiterlaufen lassen (#165).
		if (this.highlightTimer !== null) {
			window.clearTimeout(this.highlightTimer)
		}
		this.cancelSearchTimer()
	},

	methods: {
		n,
		t,

		/**
		 * Suchtext entprellt an den Store geben (#373): Bei jedem Tastendruck
		 * neu zu filtern ließe ein großes Board beim Tippen ruckeln.
		 *
		 * @param value Der Inhalt des Suchfelds.
		 */
		onSearchInput(value: string | number): void {
			this.cancelSearchTimer()
			this.searchTimer = window.setTimeout(() => {
				this.searchTimer = null
				this.store.search = String(value)
			}, 200)
		},

		/** Suche sofort leeren — Esc, Kreuz im Feld, Knopf im Leerzustand. */
		clearSearch(): void {
			this.cancelSearchTimer()
			this.searchInput = ''
			this.store.search = ''
		},

		cancelSearchTimer(): void {
			if (this.searchTimer !== null) {
				window.clearTimeout(this.searchTimer)
				this.searchTimer = null
			}
		},

		/**
		 * Zu einem anderen Board desselben Projekts wechseln (#246).
		 *
		 * @param id Kennung des Ziel-Boards.
		 */
		switchBoard(id: number): void {
			if (id === this.boardId) {
				return
			}
			this.$router.push({ name: 'board', params: { boardId: String(id) } })
		},

		/** Den „Board hinzufügen"-Dialog schließen und das Feld leeren (#246). */
		closeAddBoard(): void {
			this.addingBoard = false
			this.newBoardTitle = ''
		},

		/**
		 * Ein weiteres Board im Projekt anlegen und dorthin wechseln (#246).
		 *
		 * Der Titel darf nicht leer sein; ein Doppelklick läuft dank `addBusy`
		 * ins Leere. Bei Erfolg springt die Ansicht ins frische Board.
		 */
		async confirmAddBoard(): Promise<void> {
			const title = this.newBoardTitle.trim()
			if (title === '' || this.addBusy) {
				return
			}
			this.addBusy = true
			try {
				const board = await this.store.addSiblingBoard(title)
				this.closeAddBoard()
				this.$router.push({ name: 'board', params: { boardId: String(board.id) } })
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Anlegen fehlgeschlagen'))
			} finally {
				this.addBusy = false
			}
		},

		/** Das geöffnete Board löschen und auf das verbleibende wechseln. */
		async confirmDeleteBoard(): Promise<void> {
			if (this.deleteBusy) {
				return
			}
			this.deleteBusy = true
			try {
				const next = await this.store.deleteCurrentBoard()
				this.deletingBoard = false
				this.$router.push({ name: 'board', params: { boardId: String(next) } })
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Löschen fehlgeschlagen'))
				this.deletingBoard = false
				// Der Server weiß es besser als der geladene Stand, etwa wenn inzwischen ein Vorgang dazukam.
				await this.store.open(this.boardId)
			} finally {
				this.deleteBusy = false
			}
		},

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
				this.promptCloseIfFinal(payload.ticket.id, payload.columnId)
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
				this.promptCloseIfFinal(payload.ticketId, payload.targetColumnId)
			} catch (e) {
				if (isConflict(e)) {
					await this.store.open(this.boardId)
				}
				reportWriteError(e, t('projektwerk', 'Verschieben fehlgeschlagen'), isConflict(e))
			}
		},

		async openTicket(ticket: Ticket) {
			this.openTicketData = ticket
			if (ticket.id < 0) {
				this.openSteps = []
				this.openComments = []
				this.openAttachments = []
				this.openLinks = { dependsOn: [], neededBy: [] }
				return
			}
			// Sofort leeren, nicht erst nach dem Laden: Sonst zeigt das Overlay
			// kurz die Arbeitsschritte und Kommentare des vorigen Tickets unter
			// dem neuen Titel.
			this.openSteps = []
			this.openComments = []
			this.openAttachments = []
			this.openLinks = { dependsOn: [], neededBy: [] }
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
				this.openLinks = { dependsOn: detail.dependsOn ?? [], neededBy: detail.neededBy ?? [] }
			} catch {
				this.openSteps = []
				this.openComments = []
				this.openAttachments = []
				this.openLinks = { dependsOn: [], neededBy: [] }
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
			if (offen === null || offen.id < 0) {
				return
			}
			await this.refreshTicket(offen.id)
		},

		/**
		 * Den Vorgang öffnen, den ein Deep-Link genannt hat.
		 *
		 * Läuft **nach** dem Laden des Boards. Steht der Vorgang in der geladenen
		 * Menge, öffnet ihn derselbe Weg wie ein Klick auf die Karte — samt
		 * Kindern und Lesevermerk.
		 *
		 * Steht er **nicht** darin, wird er per ID nachgeladen. Der häufige Fall
		 * ist ein **geschlossener** Vorgang: Die Board-Ladung blendet ihn aus
		 * (`TicketMapper` filtert `closed_at`), aber `ticket_closed` ist ein
		 * Mail-Anlass — der „Zum Vorgang"-Knopf zeigte deshalb ausgerechnet dort
		 * ins Leere (#248). `ticket#show` geht über `findVisible` und damit durch
		 * dieselbe Sichtbarkeitsprüfung wie jede Ticket-Abfrage; ist der Vorgang
		 * verborgen oder fort, wirft der Aufruf und es bleibt fail-closed beim
		 * offenen Board ohne Overlay. Hier zu unterscheiden hieße zu verraten,
		 * was die Sichtbarkeitsregel verbirgt — dieselbe Linie wie im
		 * `DeepLinkController`.
		 *
		 * Die Kennung bleibt danach in der Adresse stehen. Das ist gewollt: So
		 * trägt auch der kopierte Hash-Link innerhalb der App.
		 */
		async openFromQuery() {
			const wanted = Number(this.$route.query.ticket)
			if (!Number.isInteger(wanted) || wanted <= 0) {
				return
			}

			await this.openById(wanted)
		},

		/**
		 * Einen Vorgang über seine Kennung öffnen, auch wenn er nicht in der geladenen Menge steht.
		 *
		 * @param wanted Kennung des Vorgangs.
		 */
		async openById(wanted: number) {
			const bekannt = this.store.tickets.get(wanted)
			if (bekannt !== undefined) {
				await this.openTicket(bekannt)

				return
			}

			// Die Board-Id beim Eintritt festhalten: Zwischen Anfrage und Antwort
			// kann die Person das Board gewechselt haben. Dieselbe Ansicht wird
			// über Board-Grenzen wiederverwendet (kein `:key` an der Route),
			// deshalb liefe eine veraltete Antwort sonst ins offene Board des
			// falschen Vorgangs.
			const beimEintritt = this.boardId
			try {
				const detail = await fetchTicket(beimEintritt, wanted)
				if (this.boardId !== beimEintritt) {
					return
				}

				this.openTicketData = detail.ticket
				this.openSteps = detail.steps
				this.openComments = detail.comments
				this.openAttachments = detail.attachments
				this.openLinks = { dependsOn: detail.dependsOn ?? [], neededBy: detail.neededBy ?? [] }
				this.store.markRead(wanted)
			} catch {
				// Verborgen oder nicht mehr vorhanden — bewusst still.
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
			if (ticket.id > 0) {
				this.store.replaceTicket(ticket)
			}
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
		 * Nach dem Verschieben in eine Endspalte „Auch abschließen?" anbieten (#172).
		 *
		 * Nur wenn die Zielspalte ein Ergebnis trägt **und** der Vorgang noch
		 * offen ist — ein bereits geschlossener wird nicht erneut gefragt. Kein
		 * automatisches Schließen: Der Prompt ist ein Angebot, kein Vollzug.
		 *
		 * @param ticketId Der verschobene Vorgang.
		 * @param targetColumnId Die Zielspalte.
		 */
		promptCloseIfFinal(ticketId: number, targetColumnId: number) {
			const column = this.store.columns.find((c) => c.id === targetColumnId)
			if (!column?.finalOutcome) {
				return
			}

			const ticket = this.store.tickets.get(ticketId)
			if (ticket === undefined || ticket.closedAt !== null) {
				return
			}

			this.finalPrompt = { ticket, outcome: column.finalOutcome }
		},

		/**
		 * Den Vorgang aus dem Prompt heraus mit dem Ergebnis der Endspalte
		 * abschließen (#172). Den frischen Stand aus dem Speicher nehmen, damit
		 * die `version` nach dem Verschieben stimmt.
		 */
		async confirmFinalClose() {
			const prompt = this.finalPrompt
			this.finalPrompt = null
			if (prompt === null) {
				return
			}

			const ticket = this.store.tickets.get(prompt.ticket.id) ?? prompt.ticket
			await this.toggleClosed(ticket, prompt.outcome)
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
		 * Den Anlege-Dialog öffnen.
		 *
		 * @param columnId Voreingestellte Spalte, oder null für die erste.
		 */
		neuerVorgang(columnId: number | null): void {
			this.createColumnId = columnId
			this.creating = true
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

		create(data: { title: string, description: string | null, visibility: Visibility, columnId: number, dueDate: string | null, responsibleUserId: string | null, openAfter: boolean }): void {
			// Sofort zu und in die Warteschlange: Bei schlechtem Netz klickte man sonst ein zweites Mal.
			this.creating = false
			const { openAfter, ...payload } = data
			const localId = this.outbox.enqueueTicket(this.boardId, payload)
			const entry = this.outbox.ticketEntry(localId)
			if (openAfter && entry !== undefined) {
				this.openPending(entry)
			}
		},

		/**
		 * Die Einträge der Warteschlange, die in dieser Spalte auf ihr Speichern warten.
		 *
		 * @param columnId Die Spalte.
		 */
		pendingIn(columnId: number): TicketEntry[] {
			return this.outbox.pendingTickets(this.boardId).filter((entry) => entry.payload.columnId === columnId)
		},

		/**
		 * Einen noch nicht gespeicherten Vorgang öffnen; Schritte und Kommentare reihen sich hinter ihm ein.
		 *
		 * @param entry Der Eintrag der Warteschlange.
		 */
		openPending(entry: TicketEntry): void {
			this.openTicket({
				id: entry.localId,
				boardId: entry.boardId,
				columnId: entry.payload.columnId,
				number: 0,
				title: entry.payload.title,
				description: entry.payload.description,
				visibility: entry.payload.visibility,
				creatorUserId: this.store.viewer?.userId ?? '',
				creatorRole: this.store.viewer?.role ?? 'internal',
				responsibleUserId: entry.payload.responsibleUserId,
				dueDate: entry.payload.dueDate,
				closedAt: null,
				closedOutcome: null,
				version: 0,
			} as unknown as Ticket)
		},

		/**
		 * Nach einem gespeicherten Eintrag nur den betroffenen Vorgang nachziehen.
		 *
		 * @param event Was gespeichert wurde.
		 */
		onSaved(event: SavedEvent): void {
			if (event.entry.boardId !== this.boardId) {
				return
			}
			if (event.kind === 'ticket') {
				this.store.addTicket(event.result)
				if (this.openTicketData?.id === event.entry.localId) {
					this.openTicketData = event.result
				} else {
					this.highlightNew(event.result.id)
				}
				this.scheduleRefresh(event.result.id)
				return
			}
			if (this.openTicketData?.id === event.entry.ticketId) {
				// Gleich einsetzen: Bis zum Nachladen verschwände der Eintrag sonst kurz aus der Liste.
				if (event.kind === 'comment') {
					this.openComments = [...this.openComments.filter((c) => c.id !== event.result.id), event.result]
				} else {
					this.openSteps = [...this.openSteps.filter((s) => s.id !== event.result.id), event.result]
				}
			}
			this.scheduleRefresh(event.entry.ticketId)
		},

		/**
		 * Ein anderer Tab hat gesendet: offene Ansicht und Board nachziehen, die Ereignisse kamen dort an.
		 */
		onExternalOutboxChange(): void {
			const offen = this.openTicketData
			if (offen !== null) {
				const realId = offen.id < 0 ? this.outbox.realId(offen.id) : offen.id
				if (realId > 0) {
					this.scheduleRefresh(realId)
				}
			}
			if (this.boardReloadTimer !== null) {
				window.clearTimeout(this.boardReloadTimer)
			}
			this.boardReloadTimer = window.setTimeout(() => {
				this.boardReloadTimer = null
				this.store.open(this.boardId)
			}, 500)
		},

		/**
		 * Kurzbeschreibung eines abgelehnten Eintrags für die Leiste.
		 *
		 * @param entry Der Eintrag.
		 */
		describe(entry: OutboxEntry): string {
			switch (entry.kind) {
				case 'ticket':
					return t('projektwerk', 'Vorgang „{title}“', { title: entry.payload.title })
				case 'step':
					return t('projektwerk', 'Arbeitsschritt „{title}“', { title: entry.payload.title })
				case 'comment':
					return t('projektwerk', 'Kommentar „{text}“', { text: entry.payload.body.length > 40 ? entry.payload.body.slice(0, 40) + '…' : entry.payload.body })
				case 'stepDone':
					return entry.payload.done ? t('projektwerk', 'Arbeitsschritt abhaken') : t('projektwerk', 'Haken entfernen')
			}

			return ''
		},

		/**
		 * Mehrere gespeicherte Einträge desselben Vorgangs ergeben eine einzige Abfrage.
		 *
		 * @param ticketId Der Vorgang.
		 */
		scheduleRefresh(ticketId: number): void {
			window.clearTimeout(this.refreshTimers[ticketId])
			this.refreshTimers[ticketId] = window.setTimeout(() => {
				delete this.refreshTimers[ticketId]
				this.refreshTicket(ticketId)
			}, 300)
		},

		/**
		 * Einen Vorgang mit Schritten, Kommentaren und Zählern neu holen; eine Abfrage statt des ganzen Boards.
		 *
		 * @param ticketId Der Vorgang.
		 */
		async refreshTicket(ticketId: number): Promise<void> {
			try {
				const detail = await fetchTicket(this.boardId, ticketId)
				this.store.applyDetail(detail)
				const offen = this.openTicketData === null ? null : this.outbox.realId(this.openTicketData.id)
				if (offen === ticketId) {
					this.openTicketData = this.store.tickets.get(ticketId) ?? detail.ticket
					this.openSteps = detail.steps
					this.openComments = detail.comments
					this.openAttachments = detail.attachments
					this.openLinks = { dependsOn: detail.dependsOn ?? [], neededBy: detail.neededBy ?? [] }
				}
			} catch {
				// Kein Netz: Beim nächsten Speichern oder Öffnen kommt der Stand nach.
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
