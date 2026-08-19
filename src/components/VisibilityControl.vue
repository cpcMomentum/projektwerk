<template>
	<!--
		Sitzt seit #99 **in der Kopfzeile**, nicht mehr in einem eigenen
		Abschnitt: Ueberschrift, Trennlinie und die beiden Erklaerzeilen kosteten
		zusammen 110 px fuer eine Angabe, die §9 als Chip in der Kopfzeile fuehrt.

		Der Schalter ist ein rechtsbuendiges Element neben Nummer und Spaltenname
		— und bleibt es. Drei fruehere Zusatzbloecke, die die Zeile aufbrachen
		(`--offen`), sind weg: die Rueckfrage beim Herunterstufen (#103), der
		Widerruf nach jedem Wechsel (#181) und die Anhaenge-Absage (#185, seit die
		Datei mit der Sichtbarkeit umzieht statt den Wechsel zu sperren).

		**Der Nur-Lese-Chip haengt mit hier drin** und nicht im Elternteil: Wer
		aendern darf, folgt aus `mayChange`, und diese Frage soll an genau einer
		Stelle beantwortet werden. Im Elternteil waere sie ein zweites Mal
		formuliert — mit der Gefahr, dass beide auseinanderlaufen und die
		Kundenseite entweder zwei Anzeigen oder gar keine bekommt.
	-->
	<div
		v-if="mayChange"
		class="pw-viscontrol">
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
				:busy="busy"
				:hideHints="true"
				@update:modelValue="choose" />
		</div>

		<!--
			**Anhänge sperren den Wechsel nicht mehr** (#185). Der Ablageort IST
			die Sichtbarkeit, also zieht die Datei mit in den Ordner der neuen
			Stufe — statt die Person zu bitten, sie zuerst zu lösen. Der frühere
			Warnblock ist deshalb weg.

			Geht der Umzug ausnahmsweise nicht — die Zielstufe hat keinen Ordner
			(nach „Nur ich"; für die Kundenseite „Intern") —, weist der Server
			den Wechsel mit 400 ab, und die Servermeldung erscheint als Fehler
			(kein eigener Zustand hier). Der private Ablageort kommt mit Phase B.
		-->
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
import type { ViewerInfo, Visibility } from '@/types/board'
import type { Ticket } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import VisibilityChoice from '@/components/VisibilityChoice.vue'
import { changeVisibility } from '@/services/tickets'
import { reportWriteError } from '@/services/writeError'

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
 * **Ein eigenes „Rückgängig" gibt es seit #181 nicht mehr.** Der Umschalter
 * steht ohnehin offen und ist Ein-Klick; zurück kommt man, indem man die
 * vorige Stufe wieder anklickt — derselbe Datei-Rückzug, den der Widerruf
 * machte. Er sparte nur das Merken der vorigen Stufe und liess dafür die
 * Kopfzeile springen (Axel, 2026-08-18).
 *
 * Das Frontend kennt die Rangfolge der drei Stufen weiterhin nicht und braucht
 * sie nicht: Es wechselt, und der Server weist ab, was nicht geht. Die
 * Sichtbarkeitsregel steht damit an genau einer Stelle — mit dem Wegfall von
 * `visibility-impact` sogar an einer weniger als zuvor.
 */
export default defineComponent({
	name: 'VisibilityControl',

	components: { AccountMultipleIcon, OfficeBuildingIcon, PencilIcon, VisibilityChoice },

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
			busy: false,
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

			this.busy = true
			try {
				const updated = await changeVisibility(
					this.ticket.boardId,
					this.ticket.id,
					this.ticket.version,
					target,
				)
				this.$emit('changed', updated)
			} catch (e) {
				// Was nicht geht, weist der Server ab — Versionskonflikt (409 mit
				// aktuellem Stand) oder, seit #185, ein fehlender Zielordner für
				// die Anhänge (400 mit Meldung). Beides ist hier dasselbe: die
				// Servermeldung anzeigen. Der frühere Sonderfall „Anhänge sperren"
				// entfällt, weil die Datei jetzt mitzieht.
				this.fail(e, t('projektwerk', 'Sichtbarkeit konnte nicht geändert werden'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param e Was der Aufruf geworfen hat.
		 * @param fallback Meldung, wenn der Server keine eigene mitgibt.
		 */
		fail(e: unknown, fallback: string): void {
			reportWriteError(e, fallback)
		},
	},
})
</script>
