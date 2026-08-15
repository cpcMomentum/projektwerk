<template>
	<!--
		Sitzt seit #99 **in der Kopfzeile**, nicht mehr in einem eigenen
		Abschnitt: Ueberschrift, Trennlinie und die beiden Erklaerzeilen kosteten
		zusammen 110 px fuer eine Angabe, die §9 als Chip in der Kopfzeile fuehrt.

		`--offen` bricht die Zeile auf: Solange nur der Schalter dasteht, ist er
		ein rechtsbuendiges Element neben Nummer und Spaltenname. Sobald der
		Widerruf oder die Anhaenge-Absage dazukommt, nimmt der Block die volle
		Breite — ein ganzer Satz passt nicht neben `#0001`.

		Seit #103 ist das **nur noch** diesen beiden vorbehalten: Die Rueckfrage
		beim Herunterstufen, fuer die der Zustand urspruenglich gebaut wurde, ist
		weg (Axel, 2026-08-13).

		**Der Nur-Lese-Chip haengt mit hier drin** und nicht im Elternteil: Wer
		aendern darf, folgt aus `mayChange`, und diese Frage soll an genau einer
		Stelle beantwortet werden. Im Elternteil waere sie ein zweites Mal
		formuliert — mit der Gefahr, dass beide auseinanderlaufen und die
		Kundenseite entweder zwei Anzeigen oder gar keine bekommt.
	-->
	<div
		v-if="mayChange"
		class="pw-viscontrol"
		:class="{ 'pw-viscontrol--offen': stage !== 'idle' || undoTo !== null }">
		<!--
			Die Auswahl steht **offen und immer da**, ein Klick ist die ganze
			Entscheidung: kein vorgeschaltetes „Ändern", kein „Übernehmen" (#75)
			und seit #103 auch keine Rueckfrage mehr.

			**`:modelValue` haengt am Ticket, nicht an einer eigenen Merkgroesse.**
			Die Markierung zeigt damit, was gilt — nie, was geklickt wurde.
			Zwischen Klick und Antwort liegt ein Netzaufruf; spraenge die
			Markierung sofort, saehe eine Aenderung erledigt aus, die noch
			unterwegs ist, und scheiterte sie, bliebe eine Markierung zurueck, die
			luegt.

			**Waehrend des Aufrufs ist die Auswahl gesperrt** (`busy`). Ohne die
			Sperre liefen zwei Wechsel mit derselben `version` los, und der zweite
			scheiterte am Versionskonflikt — an einem Konflikt mit sich selbst.
		-->
		<div class="pw-viscontrol__edit">
			<VisibilityChoice
				:modelValue="ticket.visibility"
				:unavailable="unavailable"
				:blockedHint="blockedHint"
				:busy="busy || stage !== 'idle'"
				:hideHints="true"
				@update:modelValue="choose" />

			<!--
				Der Widerruf steht hier und nicht in einer Meldung: §10 verlangt
				„kurz widerrufbar", und `OCP.Toast` kennt kein `showUndo` — es
				hat nur success/warning/error/info/message. Eine Meldung mit
				verstecktem Klickziel wäre ein Widerruf, den niemand findet.

				**Seit #103 nach JEDEM Wechsel, nicht nur nach dem folgenlosen.**
				Vorher stand er genau dort, wo ohnehin nichts passieren konnte,
				und fehlte beim Herunterstufen — dort fing die Rückfrage den
				Fehlgriff auf. Die ist weg; damit ist der Widerruf das einzige
				Netz, und er wird gerade für den folgenreichen Fall gebraucht.
			-->
			<div v-if="undoTo !== null" class="pw-viscontrol__actions">
				<NcButton variant="tertiary" @click="undo">
					<template #icon>
						<UndoIcon :size="20" />
					</template>
					{{ t('projektwerk', 'Rückgängig') }}
				</NcButton>
			</div>
		</div>

		<!--
			**Anhänge sperren den Wechsel** (§3.10 Stufe 1).

			Das ist **keine Rückfrage**, sondern eine Unmöglichkeit — deshalb hat
			es den Wegfall der Rückfrage (#103) überlebt. Kein „Trotzdem"-Knopf:
			Es gibt nichts, was die App an dieser Stelle tun könnte. Der
			Ablageort IST die Sichtbarkeit, und die Dateien umzuziehen ist nicht
			transaktional zur Datenbank — ein halb gelungener Umzug wäre ein
			Leck, das keine spätere Codekorrektur heilt. Der Satz sagt deshalb,
			was zu tun ist, statt eine Wahl vorzutäuschen.

			**Der Fall kommt jetzt vom Server**, nicht mehr aus einer
			Vorabprüfung: `visibility-impact` ist mit #103 aufgegeben. Der
			Wechsel wird versucht, der Server weist ihn mit 409 ab und legt die
			Zahl bei — dieselbe Ordnung wie beim Anhängen selbst („Knopf zeigen,
			Servermeldung sprechen lassen").

			Gesprochen wird trotzdem der Text von hier und nicht der des Servers:
			`AttachmentsPresentException` baut seinen Satz ohne `t()`, und die
			Zahl beugt `n()` hier ohnehin richtig.
		-->
		<div v-if="stage === 'blocked'" class="pw-viscontrol__warn">
			<p class="pw-viscontrol__lead">
				<AlertIcon :size="20" />
				<span class="pw-viscontrol__target">
					{{ blockedByAttachments }}
				</span>
			</p>

			<div class="pw-viscontrol__actions">
				<NcButton @click="reset">
					{{ t('projektwerk', 'Verstanden') }}
				</NcButton>
			</div>
		</div>
	</div>

	<!--
		Wer nicht aendern darf, sieht die Stufe als Chip — aber nur, wenn die
		Kennzeichnung ueberhaupt fuer ihn gilt. §9: In der Kundenansicht entfaellt
		sie ganz, dort ist jeder sichtbare Vorgang oeffentlich und die Markierung
		waere Rauschen.

		Farbe **plus** Symbol **plus** Text, nie Farbe allein (§9 Querschnitt).
	-->
	<span
		v-else-if="showChip"
		class="pw-vis"
		:class="'pw-vis--' + ticket.visibility">
		<AccountMultipleIcon v-if="ticket.visibility === 'public'" :size="16" />
		<OfficeBuildingIcon v-else-if="ticket.visibility === 'internal'" :size="16" />
		<PencilIcon v-else :size="16" />
		{{ labelFor(ticket.visibility) }}
	</span>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { ApiError } from '@/services/api'
import type { ViewerInfo, Visibility } from '@/types/board'
import type { Ticket } from '@/types/ticket'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import UndoIcon from 'vue-material-design-icons/UndoVariant.vue'
import VisibilityChoice from '@/components/VisibilityChoice.vue'
import { changeVisibility } from '@/services/tickets'
import { reportWriteError } from '@/services/writeError'

/**
 * Wie lange der Widerruf nach einem Wechsel stehen bleibt.
 *
 * **30 s statt 15** (#103). Fünfzehn Sekunden reichten, um einen Fehlklick zu
 * bemerken, den man schon beim Klicken bemerkt — nicht aber, um zu merken, dass
 * man die falsche von drei Stufen erwischt hat. Dafür liest man erst die Karte
 * zu Ende. Solange die Rückfrage das Netz war, fiel das nicht ins Gewicht.
 */
const UNDO_WINDOW_MS = 30000

/**
 * Die Sichtbarkeit eines bestehenden Vorgangs ändern.
 *
 * **Ein Klick auf eine Stufe ist die Entscheidung** (#75, #103). Die Auswahl
 * steht offen, es gibt kein vorgeschaltetes „Ändern", kein „Übernehmen" und —
 * seit #103 — keine Rückfrage beim Herunterstufen mehr.
 *
 * Die Rückfrage fiel mit dem Argument, das sie zugleich entkräftet: **Die
 * Beschriftung sagt es schon.** „Alle Beteiligten", „Intern" und „Nur ich" sind
 * nach dem Publikum benannt und nicht nach der Technik (§7). Wer „Intern"
 * wählt, hat gelesen, dass die Kundenseite es dann nicht mehr sieht; eine
 * Rückfrage, die dieselbe Auskunft ein zweites Mal gibt, erzieht dazu, sie
 * wegzuklicken (Axel, 2026-08-13).
 *
 * **Damit ist der Widerruf das einzige Netz** — und steht deshalb nach jedem
 * Wechsel, nicht mehr nur nach dem folgenlosen.
 *
 * Das Frontend kennt die Rangfolge der drei Stufen weiterhin nicht und braucht
 * sie nicht: Es wechselt, und der Server weist ab, was nicht geht. Die
 * Sichtbarkeitsregel steht damit an genau einer Stelle — mit dem Wegfall von
 * `visibility-impact` sogar an einer weniger als zuvor.
 */
export default defineComponent({
	name: 'VisibilityControl',

	components: { AccountMultipleIcon, AlertIcon, NcButton, OfficeBuildingIcon, PencilIcon, UndoIcon, VisibilityChoice },

	props: {
		ticket: { type: Object as PropType<Ticket>, required: true },
		viewer: { type: Object as PropType<ViewerInfo | null>, default: null },
		/**
		 * Ob die Kennzeichnung fuer diesen Betrachter ueberhaupt gilt (§9).
		 *
		 * Steuert **nur den Nur-Lese-Chip**. Der Schalter haengt an `mayChange`
		 * und ausdruecklich nicht hieran: Das ist die Kennzeichnung fuer interne
		 * Betrachter — waere der Schalter daran gebunden, koennte die Kundenseite
		 * die Sichtbarkeit ihrer eigenen Vorgaenge nie aendern.
		 */
		showChip: { type: Boolean, default: false },
	},

	emits: ['changed'],

	data() {
		return {
			stage: 'idle' as 'idle' | 'blocked',
			/** Wie viele Anhänge den Wechsel sperren — aus der Absage des Servers. */
			blockedCount: 0,
			busy: false,
			/** Der Stand vor dem letzten Wechsel, solange widerrufbar. */
			undoTo: null as Visibility | null,
			undoTimer: null as ReturnType<typeof setTimeout> | null,
		}
	},

	computed: {
		/**
		 * §7, wörtlich: „Ändern darf die Sichtbarkeit nur die Seite, der das
		 * Ticket gehört."
		 *
		 * Das ist die **Schreib**regel, nicht die Sichtbarkeitsregel — deshalb
		 * darf sie hier stehen. Sie entscheidet nur, ob ein Knopf angeboten
		 * wird; abgewiesen wird weiterhin auf dem Server, und der 403 landet
		 * unten in der Fehlerbehandlung.
		 *
		 * Bewusst **nicht** an die Kennzeichnung gekoppelt: Die zeigt §9 nur
		 * internen Betrachtern. Wäre der Knopf daran gebunden, könnte die
		 * Kundenseite die Sichtbarkeit ihrer eigenen Vorgänge nie ändern.
		 */
		mayChange(): boolean {
			return this.viewer !== null && this.ticket.creatorRole === this.viewer.role
		},

		/** §7: Auf „Nur ich" herunterstufen kann allein die anlegende Person. */
		unavailable(): Visibility[] {
			return this.viewer !== null && this.ticket.creatorUserId === this.viewer.userId
				? []
				: ['private']
		},

		/**
		 * Warum „Nur ich" hier nicht wählbar ist.
		 *
		 * Steht im Skript und nicht als Literal im Aufruf, weil der Text ein
		 * Anführungszeichen trägt — im Attribut würde es die Zeichenkette der
		 * Vorlage beenden.
		 */
		blockedHint(): string {
			return t('projektwerk', 'Auf „Nur ich" herunterstufen kann nur die anlegende Person')
		},

		/**
		 * Der Satz, der die Sperre erklärt — mit der Zahl, weil sie die
		 * Handlung bestimmt.
		 *
		 * „Lösen", nicht „löschen": Die Dateien bleiben liegen, gelöst wird nur
		 * die Verknüpfung. Wer hier „löschen" läse, räumte mehr weg als nötig.
		 */
		blockedByAttachments(): string {
			return n(
				'projektwerk',
				'Dieser Vorgang hat %n Anhang. Bitte ihn zuerst vom Vorgang lösen — die Datei selbst bleibt dabei liegen.',
				'Dieser Vorgang hat %n Anhänge. Bitte sie zuerst vom Vorgang lösen — die Dateien selbst bleiben dabei liegen.',
				this.blockedCount,
			)
		},
	},

	watch: {
		// Ein anderer Vorgang im selben Overlay: Auswahl, Rückfrage und ein noch
		// offener Widerruf gehören zum alten Ticket und dürfen nicht stehen
		// bleiben — ein „Rückgängig" hier setzte sonst das falsche Ticket zurück.
		'ticket.id': {
			immediate: true,
			handler() {
				this.reset()
				this.clearUndo()
			},
		},
	},

	beforeUnmount() {
		this.clearUndo()
	},

	methods: {
		t,

		/**
		 * @param visibility Die Stufe.
		 */
		labelFor(visibility: Visibility): string {
			if (visibility === 'public') {
				return t('projektwerk', 'Alle Beteiligten')
			}
			if (visibility === 'internal') {
				return t('projektwerk', 'Intern')
			}
			return t('projektwerk', 'Nur ich')
		},

		reset(): void {
			this.stage = 'idle'
			this.blockedCount = 0
		},

		clearUndo(): void {
			if (this.undoTimer !== null) {
				clearTimeout(this.undoTimer)
				this.undoTimer = null
			}
			this.undoTo = null
		},

		/**
		 * Eine Stufe wurde angeklickt — das ist die ganze Handlung.
		 *
		 * **Ein Aufruf statt zwei** (#103). Vorher wurde erst gefragt, was der
		 * Wechsel kostet, und danach entschieden, ob zurückgefragt wird. Ohne
		 * Rückfrage hat die Antwort keinen Abnehmer mehr: Der Wechsel wird
		 * versucht, und was nicht geht, weist der Server ab.
		 *
		 * Ein Klick auf die geltende Stufe tut nichts — er ist keine Änderung,
		 * und ein Netzaufruf dafür wäre Lärm.
		 *
		 * @param target Die angeklickte Stufe.
		 */
		async choose(target: Visibility): Promise<void> {
			if (this.busy || target === this.ticket.visibility) {
				return
			}

			const previous = this.ticket.visibility

			this.busy = true
			try {
				const updated = await changeVisibility(
					this.ticket.boardId,
					this.ticket.id,
					this.ticket.version,
					target,
				)
				this.$emit('changed', updated)
				this.reset()
				this.armUndo(previous)
			} catch (e) {
				// **Anhänge sperren den Wechsel** (§3.10 Stufe 1) — und das ist
				// nicht derselbe Fall wie ein Versionskonflikt, obwohl der Server
				// beide mit 409 beantwortet. Wer nur den Status liest, meldet der
				// Person mit Anhängen „bitte neu laden", und Neuladen hilft nichts.
				//
				// Unterschieden wird an der Zahl, die der Controller eigens
				// beilegt. Ohne sie gäbe es kein Merkmal: Der Status ist gleich,
				// und die Meldung ist Text.
				const anhaenge = Number((e as ApiError)?.data?.attachments ?? 0)
				if (anhaenge > 0) {
					this.blockedCount = anhaenge
					this.stage = 'blocked'

					return
				}

				this.fail(e, t('projektwerk', 'Sichtbarkeit konnte nicht geändert werden'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param previous Der Stand, zu dem der Widerruf zurückführt.
		 */
		armUndo(previous: Visibility): void {
			this.clearUndo()
			this.undoTo = previous
			this.undoTimer = setTimeout(() => this.clearUndo(), UNDO_WINDOW_MS)
		},

		/**
		 * Zurück auf den Stand von eben.
		 *
		 * Ohne Rückfrage, obwohl es der Richtung nach ein Herunterstufen sein
		 * kann: Der Widerruf stellt den Zustand wieder her, den die Person
		 * Sekunden zuvor selbst hatte. Eine Rückfrage nach Namen, die genau
		 * dadurch wieder ausgesperrt werden, wäre eine Warnung vor dem
		 * Rückgängigmachen einer Warnung.
		 */
		async undo(): Promise<void> {
			const target = this.undoTo
			if (target === null || this.busy) {
				return
			}

			this.busy = true
			try {
				const updated = await changeVisibility(
					this.ticket.boardId,
					this.ticket.id,
					this.ticket.version,
					target,
				)
				this.$emit('changed', updated)
				this.clearUndo()
			} catch (e) {
				this.fail(e, t('projektwerk', 'Rückgängig machen fehlgeschlagen'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param e Was der Aufruf geworfen hat.
		 * @param fallback Meldung, wenn der Server keine eigene mitgibt.
		 */
		fail(e: unknown, fallback: string): void {
			// 409 heißt: jemand anders war schneller. Der Stand im Overlay ist
			// veraltet, und ein zweiter Versuch mit derselben `version` scheiterte
			// genauso — deshalb zurück in den Ruhezustand statt einer Wiederholung.
			// Nachladen kann dieser Bereich nicht, er hält nur ein Ticket.
			if (reportWriteError(e, fallback)) {
				this.reset()
			}
		},
	},
})
</script>
