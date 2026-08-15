<template>
	<div class="pw-view">
		<div class="pw-view__head">
			<h2>{{ t('projektwerk', 'Überblick') }}</h2>
		</div>

		<div v-if="store.loading" class="pw-stack">
			<div v-for="n in 3" :key="n" class="pw-skel">
				<i /><i /><i />
			</div>
		</div>

		<!--
			**Der Fehlerfall vor dem Leerfall** — dieselbe Ordnung wie in
			`TasksView`. Ohne ihn behauptet ein gescheitertes Laden „nichts
			offen": Die Listen sind ja leer. Das ist die unangenehmste Sorte
			Falschaussage, und auf der Startseite die folgenreichste.
		-->
		<NcEmptyContent
			v-else-if="store.error !== null"
			:name="t('projektwerk', 'Der Überblick konnte nicht geladen werden')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="store.nothingOpen"
			:name="t('projektwerk', 'Zurzeit hakt nichts.')"
			:description="t('projektwerk', 'Hier steht, was bei der Kundenseite liegt und in welchen Projekten sich etwas bewegt. Solange alle Vorgänge erledigt sind, bleibt die Seite leer.')">
			<template #icon>
				<ViewDashboardIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!--
				**Zwei Abschnitte, eine Zeilenform.** Das Mockup stellte drei
				verschiedene Formen nebeneinander — Listenzeilen, Zaehler-Pillen
				und eine Kachelreihe; Axels Befund war „zu unstrukturiert"
				(2026-08-13). Beide Abschnitte tragen deshalb dieselbe Zeile:
				links eine Kennzeichnung, in der Mitte Titel und Herkunft,
				rechts eine Marke. Was sich unterscheidet, ist der Inhalt, nicht
				die Gestalt.
			-->
			<section v-if="store.waitingRows.length > 0" class="pw-ov__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Wartet auf die Kundenseite') }}
					<span class="pw-n">{{ store.waitingRows.length }}</span>
				</h3>

				<button
					v-for="row in store.waitingRows"
					:key="row.ticket.id"
					type="button"
					class="pw-ov__row"
					@click="openTicket(row.ticket)">
					<span class="pw-num">#{{ padded(row.ticket.number) }}</span>
					<span class="pw-ov__body">
						<span class="pw-ov__title">{{ row.ticket.title }}</span>
						<!--
							Die Herkunft gehoert an die Zeile: Auf einer
							projektuebergreifenden Seite ist der Ort die halbe
							Information. Danach, wer wartet — mit **Namen**, nicht
							mit Kennungen (#104).
						-->
						<span class="pw-ov__meta">
							{{ row.board ? row.board.title : '' }}
							<span v-if="row.names.length > 0" class="pw-ov__who">· {{ row.names.join(', ') }}</span>
						</span>
					</span>
					<span class="pw-marke" :class="{ 'pw-marke--lang': row.days >= LANGE_WARTEZEIT }">
						{{ standLabel(row) }}
					</span>
				</button>
			</section>

			<!--
				**Meine Vorgänge** (#120): was bei mir liegt und gerade nicht auf
				den Kunden wartet. Der zweite der drei Ballbesitz-Zustände aus #114.
			-->
			<section v-if="store.myTicketRows.length > 0" class="pw-ov__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Meine Vorgänge') }}
					<span class="pw-n">{{ store.myTicketRows.length }}</span>
				</h3>

				<button
					v-for="row in store.myTicketRows"
					:key="row.ticket.id"
					type="button"
					class="pw-ov__row"
					@click="openTicket(row.ticket)">
					<span class="pw-num">#{{ padded(row.ticket.number) }}</span>
					<span class="pw-ov__body">
						<span class="pw-ov__title">{{ row.ticket.title }}</span>
						<span v-if="row.board" class="pw-ov__meta">{{ row.board.title }}</span>
					</span>
					<span
						v-if="row.ticket.dueDate"
						class="pw-marke"
						:class="{ 'pw-marke--lang': isOverdue(row.ticket.dueDate) }">
						{{ dueLabel(row.ticket) }}
					</span>
				</button>
			</section>

			<!--
				**Liegt bei niemandem** (#119): kein Verantwortlicher, kein offener
				Schritt, wartet auch nicht — unbearbeitet. Der dritte Zustand.
			-->
			<section v-if="store.nobodyRows.length > 0" class="pw-ov__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Liegt bei niemandem') }}
					<span class="pw-n">{{ store.nobodyRows.length }}</span>
				</h3>

				<button
					v-for="row in store.nobodyRows"
					:key="row.ticket.id"
					type="button"
					class="pw-ov__row"
					@click="openTicket(row.ticket)">
					<span class="pw-num">#{{ padded(row.ticket.number) }}</span>
					<span class="pw-ov__body">
						<span class="pw-ov__title">{{ row.ticket.title }}</span>
						<span v-if="row.board" class="pw-ov__meta">{{ row.board.title }}</span>
					</span>
				</button>
			</section>

			<section v-if="store.projectRows.length > 0" class="pw-ov__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Projekte mit Bewegung') }}
					<span class="pw-n">{{ store.projectRows.length }}</span>
				</h3>

				<button
					v-for="row in sortedProjectRows"
					:key="row.boardId"
					type="button"
					class="pw-ov__row"
					@click="openBoard(row.boardId)">
					<!--
						An der Stelle der Vorgangsnummer steht der Stern der
						angepinnten Projekte (#116) — sonst nichts: Ein Projekt hat
						keine Nummer. Die Spalte bleibt stehen, damit die Zeilen
						beider Abschnitte auf derselben Kante beginnen.
					-->
					<StarIcon v-if="isPinned(row.boardId)" class="pw-ov__pin" :size="16" />
					<span v-else class="pw-num pw-num--leer" aria-hidden="true" />
					<span class="pw-ov__body">
						<span class="pw-ov__title">{{ row.title }}</span>
						<span v-if="row.org" class="pw-ov__meta">{{ row.org }}</span>
						<!--
							Der Stillstand-Hinweis (#116) steht nur, wo er etwas sagt:
							Das Projekt wartet auf niemanden und ruht trotzdem lange.
							Ein wartendes Projekt ruht mit gutem Grund und bekommt
							ihn nicht.
						-->
						<span v-if="isStalled(row)" class="pw-ov__still">{{ stallLabel(row) }}</span>
					</span>
					<span class="pw-marke">{{ offenLabel(row) }}</span>
				</button>
			</section>
		</template>
	</div>
</template>

<script lang="ts">
import type { ProjectRow, WaitingRow } from '@/types/overview'
import type { Ticket } from '@/types/ticket'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
import { useBoardStore } from '@/stores/boardStore'
import { useOverviewStore } from '@/stores/overviewStore'
import { germanDate, isOverdue } from '@/utils/date'

/**
 * Ab wann eine Wartezeit hervorgehoben wird.
 *
 * Eine Woche, und bewusst grob: Die Zahl soll sagen „das liegt lange", nicht
 * eine Frist behaupten, die niemand vereinbart hat. Faelligkeiten am Vorgang
 * kommen mit #72; dann ist „ueberfaellig" eine Tatsache statt einer Schaetzung,
 * und diese Grenze kann weg.
 */
const LANGE_WARTEZEIT = 7

/**
 * Ab wann ein Projekt als „steht still" markiert wird (#116).
 *
 * Vierzehn Tage ohne Bewegung, und **nur wenn nichts auf den Kunden wartet**:
 * Ein Projekt, das beim Kunden liegt, ruht mit gutem Grund — das ist Warten,
 * kein Stillstand. Gemeint ist die eigene Arbeit, die niemand angefasst hat.
 * Grob wie die Wartegrenze und aus demselben Grund: ein Hinweis, keine Frist.
 */
const STILLSTAND_TAGE = 14

/**
 * Der Überblick — der Einstieg in die App (#76, entschieden am 2026-08-13).
 *
 * **Die Frage ist eine andere als bei „Meine Aufgaben".** Jene Seite beantwortet
 * „was liegt bei mir", diese „wo hakt es" — über alle Projekte, auch dort, wo
 * gerade nichts bei mir liegt. Ohne diesen Unterschied wäre sie die Seite, die
 * niemand öffnet; er ist ihre ganze Rechtfertigung.
 *
 * **Der dritte Abschnitt aus dem Mockup fehlt bewusst.** „Seit deinem letzten
 * Blick" braucht einen Zeitstempel je Person und einen Begriff von Aktivität —
 * das ist #79 und wird dort entschieden, nicht hier nebenbei erledigt.
 *
 * Der Klick führt ins **Board**, nicht in ein eigenes Detail: Ein zweiter Ort,
 * an dem ein Vorgang lebt, wäre ein zweiter Ort, an dem die Sichtbarkeit
 * stimmen müsste — dieselbe Entscheidung wie in `TasksView`.
 */
export default defineComponent({
	name: 'OverviewView',

	components: { AlertIcon, NcEmptyContent, StarIcon, ViewDashboardIcon },

	setup() {
		return { store: useOverviewStore(), boardStore: useBoardStore(), LANGE_WARTEZEIT }
	},

	computed: {
		/**
		 * Die Kennungen der angepinnten Projekte (#115), als Menge für den
		 * schnellen Test je Zeile.
		 *
		 * Aus dem Board-Speicher, den der App-Rahmen ohnehin beim Start lädt —
		 * kein zweiter Abruf.
		 */
		pinnedIds(): Set<number> {
			return new Set(this.boardStore.pinnedBoards.map((board) => board.id))
		},

		/**
		 * „Projekte mit Bewegung", angepinnte zuerst (#116).
		 *
		 * Die Reihenfolge des Speichers bleibt innerhalb beider Gruppen erhalten
		 * (stabile Teilung): Wer ein Projekt anpinnt, will es oben sehen, ohne
		 * dass sich darunter die gewohnte Ordnung nach Wartendem verdreht.
		 */
		sortedProjectRows(): ProjectRow[] {
			const rows = this.store.projectRows as ProjectRow[]
			const pinned = this.pinnedIds

			return [
				...rows.filter((row) => pinned.has(row.boardId)),
				...rows.filter((row) => !pinned.has(row.boardId)),
			]
		},
	},

	created() {
		this.store.load()
	},

	methods: {
		t,
		isOverdue,

		/**
		 * „fällig {Datum}" oder „überfällig seit {Datum}" für einen Vorgang mit
		 * Ticket-Fälligkeit (#72) im Abschnitt „Meine Vorgänge".
		 *
		 * @param ticket Der Vorgang.
		 */
		dueLabel(ticket: Ticket): string {
			const date = germanDate(ticket.dueDate)

			return isOverdue(ticket.dueDate)
				? t('projektwerk', 'überfällig seit {date}', { date })
				: t('projektwerk', 'fällig {date}', { date })
		},

		/**
		 * @param boardId Kennung des Projekts.
		 */
		isPinned(boardId: number): boolean {
			return this.pinnedIds.has(boardId)
		},

		/**
		 * Steht dieses Projekt still? — nichts wartet auf den Kunden und seit
		 * mindestens `STILLSTAND_TAGE` Tagen keine Bewegung (#116).
		 *
		 * @param row Die Zeile.
		 */
		isStalled(row: ProjectRow): boolean {
			return row.waiting === 0
				&& row.lastMovementDays !== null
				&& row.lastMovementDays >= STILLSTAND_TAGE
		},

		/**
		 * Die „steht still"-Marke.
		 *
		 * @param row Die Zeile.
		 */
		stallLabel(row: ProjectRow): string {
			return n('projektwerk', 'steht still seit %n Tag', 'steht still seit %n Tagen', row.lastMovementDays ?? 0)
		},

		/**
		 * @param number Die Ticketnummer.
		 */
		padded(number: number): string {
			return String(number).padStart(4, '0')
		},

		/**
		 * Wie lange es schon dort liegt.
		 *
		 * **In Tagen und nicht als Datum.** „seit 12 Tagen" ist die Aussage;
		 * „seit 01.08." verlangt vom Leser, selbst zu rechnen — auf einer Seite,
		 * die genau diese Rechnung zum Inhalt hat.
		 *
		 * `n()` und kein Platzhalter in festem Text: Bei genau einem Tag stünde
		 * dort sonst „seit 1 Tagen".
		 *
		 * @param row Die Zeile.
		 */
		standLabel(row: WaitingRow): string {
			if (row.days === 0) {
				return t('projektwerk', 'seit heute')
			}

			return n('projektwerk', 'seit %n Tag', 'seit %n Tagen', row.days)
		},

		/**
		 * Was in diesem Projekt offen ist — und wie viel davon wartet.
		 *
		 * Zwei getrennte Zeichenketten statt einer mit Platzhaltern: `n()` beugt
		 * nach genau einer Zahl, und hier sind es zwei.
		 *
		 * @param row Die Zeile.
		 */
		offenLabel(row: ProjectRow): string {
			const offen = n('projektwerk', '%n offen', '%n offen', row.open)

			return row.waiting === 0
				? offen
				: `${offen} · ${n('projektwerk', '%n wartet', '%n warten', row.waiting)}`
		},

		/**
		 * Ins Board, mit dem Vorgang offen — derselbe Weg wie der Deep-Link.
		 *
		 * @param ticket Der Vorgang.
		 */
		openTicket(ticket: Ticket): void {
			this.$router.push({
				name: 'board',
				params: { boardId: String(ticket.boardId) },
				query: { ticket: String(ticket.id) },
			})
		},

		/**
		 * @param boardId Kennung des Projekts.
		 */
		openBoard(boardId: number): void {
			this.$router.push({ name: 'board', params: { boardId: String(boardId) } })
		},
	},
})
</script>
