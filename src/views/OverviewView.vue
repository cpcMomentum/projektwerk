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
				**Die Ampel** (#224) — der einzige Zusatz gegenüber der reinen
				Listenseite. Sie fasst oben zusammen, was gerade „rot" ist, und
				macht den Blick-Zustand sichtbar, den vier gleich aussehende
				Listen nicht hergeben.

				Bewusst schmal gehalten: nur Kennzahlen, die zum Handeln rufen —
				keine Vanity-Zähler wie „12 offen". Eine Kachel mit Wert 0 fehlt
				(kein „0 überfällig" als Beruhigungspille); sind alle 0, fehlt die
				ganze Leiste. Jede Zahl ist ein Ausschnitt der Ziel-Liste (siehe
				`ampel`-Getter) — der Klick springt zur ganzen Liste, in der diese
				Zeilen stehen, nicht zu einer auf die Zahl gekürzten Ansicht.
			-->
			<div v-if="hasAmpel" class="pw-ampel">
				<button
					v-for="kachel in ampel"
					:key="kachel.key"
					type="button"
					class="pw-kpi"
					:class="'pw-kpi--' + kachel.tone"
					:aria-label="ampelAria(kachel)"
					@click="jumpTo(kachel.target)">
					<span class="pw-kpi__lab">{{ kachel.label }}</span>
					<span class="pw-kpi__num">{{ kachel.count }}</span>
					<span class="pw-kpi__sub">{{ kachel.sub }}</span>
				</button>
			</div>

			<!--
				**Zwei Abschnitte, eine Zeilenform.** Das Mockup stellte drei
				verschiedene Formen nebeneinander — Listenzeilen, Zaehler-Pillen
				und eine Kachelreihe; Axels Befund war „zu unstrukturiert"
				(2026-08-13). Beide Abschnitte tragen deshalb dieselbe Zeile:
				links eine Kennzeichnung, in der Mitte Titel und Herkunft,
				rechts eine Marke. Was sich unterscheidet, ist der Inhalt, nicht
				die Gestalt.
			-->
			<section
				v-if="store.waitingRows.length > 0"
				ref="secWaiting"
				class="pw-ov__block"
				:class="{ 'pw-ov__block--flash': flash === 'secWaiting' }">
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
			<section
				v-if="store.myTicketRows.length > 0"
				ref="secMine"
				class="pw-ov__block"
				:class="{ 'pw-ov__block--flash': flash === 'secMine' }">
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
					<!--
						**Überfällig ist rot, nicht gelb** (#224) — dieselbe Farbe
						wie die Ampel-Kachel oben. Eine verstrichene Fälligkeit ist
						eine echte Frist (#72), kein bloßes „lange her": Der gelbe
						Ton der Wartezeit würde beides gleichsetzen. Fällig, aber
						nicht überfällig, bleibt neutral.
					-->
					<span
						v-if="row.ticket.dueDate"
						class="pw-marke"
						:class="{ 'pw-marke--rot': isOverdue(row.ticket.dueDate) }">
						{{ dueLabel(row.ticket) }}
					</span>
				</button>
			</section>

			<!--
				**Liegt bei niemandem** (#119): kein Verantwortlicher, kein offener
				Schritt, wartet auch nicht — unbearbeitet. Der dritte Zustand.
			-->
			<section
				v-if="store.nobodyRows.length > 0"
				ref="secNobody"
				class="pw-ov__block"
				:class="{ 'pw-ov__block--flash': flash === 'secNobody' }">
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

			<!--
				**Projekte als Status-Tabelle** (#226) — löst die frühere Liste
				„Projekte mit Bewegung" ab. Sie zeigt je aktivem Projekt die
				Status-Zahlen, den Fortschritt und das abgeleitete Zustandssignal;
				die Rechnung liegt im Store, die Darstellung in `ProjectStatusTable`.
				Der Abschnitt behält `ref`/Flash, damit die Ampel-Kachel „Projekte
				still" weiterhin hierher springt.
			-->
			<section
				v-if="store.projectStatusRows.length > 0"
				ref="secProjects"
				class="pw-ov__block"
				:class="{ 'pw-ov__block--flash': flash === 'secProjects' }">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Projekte') }}
				</h3>
				<ProjectStatusTable />
			</section>
		</template>
	</div>
</template>

<script lang="ts">
import type { OverviewTicketRow, ProjectRow, WaitingRow } from '@/types/overview'
import type { Ticket } from '@/types/ticket'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ProjectStatusTable from '@/components/ProjectStatusTable.vue'
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
 * Eine Kachel der Dringlichkeits-Ampel (#224).
 *
 * `target` ist der Name der `ref` des Abschnitts, zu dem der Klick springt —
 * die Zahl ist genau das, was dort steht.
 */
interface AmpelItem {
	key: string
	count: number
	/** Farbrolle: `rot` nur bei echter Frist, `warn` für „lange/still", `neutral` sonst. */
	tone: 'rot' | 'warn' | 'neutral'
	target: 'secWaiting' | 'secMine' | 'secNobody' | 'secProjects'
	label: string
	sub: string
}

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

	components: { AlertIcon, NcEmptyContent, ProjectStatusTable, ViewDashboardIcon },

	setup() {
		return { store: useOverviewStore(), LANGE_WARTEZEIT }
	},

	data() {
		return {
			/**
			 * Der Abschnitt, der nach einem Ampel-Klick kurz aufleuchtet — als
			 * `ref`-Name, oder `null`. Nur eine Bestätigung „hier bist du
			 * gelandet", kein Zustand mit Bedeutung.
			 */
			flash: null as string | null,
		}
	},

	computed: {
		/**
		 * Die Dringlichkeits-Ampel (#224) — nur die Kacheln mit einem Wert > 0.
		 *
		 * **Jede Zahl ist ein Ausschnitt einer bestehenden Liste, keine neue
		 * Rechnung.** Sie stammt aus denselben Gettern wie die Abschnitte
		 * darunter, damit es keine zweite Wahrheit gibt und der Klick auf eine
		 * Kachel dort landet, wo genau diese Zeilen stehen.
		 *
		 * **„Überfällig" ist rot, der Rest gelb** — dieselbe Regel wie an der
		 * Marke der Zeile: Rot heißt „Frist verstrichen" (echte Fälligkeit, #72),
		 * eine lange Wartezeit oder ein stiller Stillstand ist auffällig, aber
		 * ohne vereinbarte Frist nicht „zu spät".
		 */
		ampel(): AmpelItem[] {
			const myRows = this.store.myTicketRows as OverviewTicketRow[]
			const waiting = this.store.waitingRows as WaitingRow[]
			const nobody = this.store.nobodyRows as OverviewTicketRow[]
			const projects = this.store.projectRows as ProjectRow[]

			const items: AmpelItem[] = [
				{
					key: 'overdue',
					count: myRows.filter((row) => isOverdue(row.ticket.dueDate)).length,
					tone: 'rot',
					target: 'secMine',
					label: t('projektwerk', 'Überfällig'),
					sub: t('projektwerk', 'Frist verstrichen'),
				},
				{
					key: 'waiting',
					count: waiting.filter((row) => row.days >= LANGE_WARTEZEIT).length,
					tone: 'warn',
					target: 'secWaiting',
					label: t('projektwerk', 'Wartet lange'),
					sub: t('projektwerk', 'seit über einer Woche bei der Kundenseite'),
				},
				{
					key: 'nobody',
					count: nobody.length,
					tone: 'neutral',
					target: 'secNobody',
					label: t('projektwerk', 'Liegt bei niemandem'),
					sub: t('projektwerk', 'kein Verantwortlicher'),
				},
				{
					key: 'stalled',
					count: projects.filter((row) => this.isStalled(row)).length,
					tone: 'warn',
					target: 'secProjects',
					label: t('projektwerk', 'Projekte still'),
					sub: t('projektwerk', 'keine Bewegung'),
				},
			]

			return items.filter((item) => item.count > 0)
		},

		/** Gibt es überhaupt etwas Rotes? — sonst bleibt die Leiste weg. */
		hasAmpel(): boolean {
			return (this.ampel as AmpelItem[]).length > 0
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
		 * Beschriftung der Ampel-Kachel für Hilfstechnik: Zahl, Bedeutung und was
		 * ein Klick tut. Die Farbe trägt nie allein — sie steht ohnehin schon als
		 * Text da, hier kommt nur die Handlung dazu.
		 *
		 * @param kachel Die Kachel.
		 */
		ampelAria(kachel: AmpelItem): string {
			return t('projektwerk', '{count} {label}, zur Liste springen', {
				count: String(kachel.count),
				label: kachel.label,
			})
		},

		/**
		 * Zum Abschnitt springen, den eine Ampel-Kachel meint, und ihn kurz
		 * aufleuchten lassen.
		 *
		 * Ein reiner Sprung innerhalb der Seite — kein neuer Datenpfad, kein
		 * Filter, der etwas verbergen könnte. Der Abschnitt ist da, weil seine
		 * Zahl > 0 ist; darum wird die `ref` gefunden.
		 *
		 * @param target Der `ref`-Name des Abschnitts.
		 */
		jumpTo(target: string): void {
			const bezug = this.$refs[target] as HTMLElement | HTMLElement[] | undefined
			const el = Array.isArray(bezug) ? bezug[0] : bezug
			if (el === undefined) {
				return
			}

			el.scrollIntoView({ behavior: 'smooth', block: 'start' })
			this.flash = target
			window.setTimeout(() => {
				if (this.flash === target) {
					this.flash = null
				}
			}, 1400)
		},
	},
})
</script>
