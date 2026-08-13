<template>
	<div class="pw-vischoice" :class="{ 'pw-vischoice--knapp': hideHints }">
		<!--
			`NcRadioGroup` statt des handgebauten Schalters (#99). Optisch
			derselbe Eindruck; dazu kommen echte Radio-Semantik, Bedienung mit
			den Pfeiltasten und der Fokusring der Plattform — an einer Auswahl,
			an der haengt, wer den Vorgang sieht, ist das kein Beiwerk.

			Der Weg ist der von Nextcloud vorgesehene: `NcCheckboxRadioSwitch`
			fuehrt seine Props `buttonVariant`/`buttonVariantGrouped`
			ausdruecklich als „@deprecated — Use `NcRadioGroup` instead".
		-->
		<NcRadioGroup
			ref="gruppe"
			:modelValue="modelValue"
			:label="t('projektwerk', 'Sichtbarkeit')"
			:hideLabel="true"
			@update:modelValue="waehlen">
			<NcRadioGroupButton
				v-for="option in options"
				:key="option.value"
				:value="option.value"
				:label="option.name"
				:disabled="isBlocked(option.value) || busy">
				<template #icon>
					<AccountMultipleIcon v-if="option.value === 'public'" :size="20" />
					<OfficeBuildingIcon v-else-if="option.value === 'internal'" :size="20" />
					<PencilIcon v-else :size="20" />
				</template>
			</NcRadioGroupButton>
		</NcRadioGroup>

		<!--
			Die Erklaerung stand frueher in jeder Karte und machte den Abschnitt
			dreimal so hoch. Sie gehoert aber nur zu der Stufe, die gilt — was die
			anderen beiden bedeuten, erfaehrt man, indem man sie waehlt. Der Satz
			bleibt damit da, wo er gebraucht wird, und kostet eine Zeile statt
			neun.

			**Im Vorgang entfaellt er ganz** (`hideHints`, #99): §9 verlangt die
			Zusatzzeile fuer das **Anlege-Formular**, wo man die Stufe zum ersten
			Mal waehlt. Im geoeffneten Vorgang nennt die Beschriftung des
			markierten Segments das Publikum bereits.
		-->
		<p v-if="!hideHints" class="pw-vishint">
			{{ selectedHint }}
		</p>

		<!--
			Warum eine Stufe fehlt, muss **sichtbar** dastehen. Frueher trug der
			gesperrte Knopf den Grund im Text; als `title` allein waere er auf dem
			Telefon unerreichbar, weil es dort kein Ueberfahren gibt.

			**Und deshalb haengt er nicht mehr an `hideHints`** (#103). Bis dahin
			lag er mit dem Erklaersatz im selben Zweig und war im Vorgang damit
			nie zu sehen: Dort stand „Nur ich" grau da, ohne ein Wort dazu, warum.
			Der Satz oben nahm das schon fuer sich in Anspruch — die Regel stand
			im Kommentar und war seit #99 ausser Kraft.

			Der Unterschied ist der Grund: Der Erklaersatz sagt, was eine Stufe
			bedeutet, und das sagt die Beschriftung im Vorgang bereits. Warum eine
			Stufe **nicht waehlbar** ist, sagt sie nicht.
		-->
		<p v-if="blockedReason !== ''" class="pw-vishint pw-vishint--blocked">
			{{ blockedReason }}
		</p>
	</div>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Visibility } from '@/types/board'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

/**
 * Die drei Stufen zur Auswahl — eine Zeile, nie eingeklappt (§9).
 *
 * Eigene Komponente, weil sie an **zwei** Stellen steht: im Anlege-Formular und
 * beim nachträglichen Ändern im Ticket-Detail. Zweimal geschrieben wären es
 * zwei Sätze Beschriftungen, die auseinanderlaufen — und die Beschriftung ist
 * hier keine Zierde: Sie ist das Einzige, woran jemand ablesen kann, wer den
 * Vorgang danach sieht.
 *
 * Die Reihenfolge ist **zu → offen** (`private` → `internal` → `public`): Von
 * links nach rechts wird der Kreis größer, und genau so liest man eine Zeile.
 * Sie ist aber **Anzeige, keine Rangfolge im Code** — ob ein Wechsel geht,
 * entscheidet allein der Server, der jeden Versuch direkt annimmt oder abweist
 * (seit #103, ohne den Umweg über `visibility-impact`).
 *
 * Der Unterschied ist der ganze Punkt und darf nicht verwischen: Wer aus der
 * sichtbaren Reihenfolge ein `if (neuerIndex < alterIndex)` ableitet, hat die
 * Sichtbarkeitsregel in zweiter Fassung — und die zweite prüft niemand. Zwei
 * Tests in `VisibilityControl.spec.ts` laufen absichtlich **gegen** den
 * Anschein der Richtung und fallen genau dann.
 */
export default defineComponent({
	name: 'VisibilityChoice',

	components: { AccountMultipleIcon, NcRadioGroup, NcRadioGroupButton, OfficeBuildingIcon, PencilIcon },

	props: {
		modelValue: { type: String as PropType<Visibility>, required: true },
		/**
		 * Stufen, die dieser Person hier nicht offenstehen.
		 *
		 * Bisher nur `private` an einem fremden Ticket: Herunterstufen auf
		 * „Nur ich" kann nach §7 allein die anlegende Person. Der Server weist
		 * es ohnehin ab — die Sperre hier erspart den Fehlschlag, sie ersetzt
		 * ihn nicht.
		 */
		unavailable: { type: Array as PropType<Visibility[]>, default: () => [] },
		blockedHint: { type: String, default: '' },
		/**
		 * Ein Wechsel läuft gerade.
		 *
		 * Sperrt die ganze Reihe. Nötig, seit ein Klick die Entscheidung ist
		 * (#75): Zwischen Klick und Antwort liegt ein Netzaufruf, und ohne
		 * Sperre liesse sich in dieser Lücke eine zweite Stufe anklicken — die
		 * zweite Rueckfrage ueberschriebe dann die erste.
		 *
		 * Im Anlege-Formular gibt es nichts zu warten; dort bleibt es bei `false`.
		 */
		busy: { type: Boolean, default: false },
		/**
		 * Ohne die Erklaerzeilen — fuer den geoeffneten Vorgang (#99).
		 *
		 * §9 verlangt die Zusatzzeile („Auch die Kundenseite sieht diesen
		 * Vorgang") fuer das **Anlege-Formular**, wo die Stufe zum ersten Mal
		 * gewaehlt wird. Im Vorgang steht der Schalter in der Kopfzeile, und
		 * dort nennt die Beschriftung des markierten Segments das Publikum
		 * bereits — zwei Zeilen Text darunter waeren die Wand, die der Umbau
		 * abgeraeumt hat.
		 */
		hideHints: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	computed: {
		/** Die Erklärung zu der Stufe, die gerade gilt. */
		selectedHint(): string {
			return this.options.find((o) => o.value === this.modelValue)?.hint ?? ''
		},

		/**
		 * Der Grund, falls hier eine Stufe fehlt — sonst leer.
		 *
		 * Eine Zeile für alle gesperrten zusammen: Bisher ist immer höchstens
		 * `private` gesperrt, und drei Gründe untereinander wären wieder die
		 * Wand aus Text, die dieser Umbau abgeräumt hat.
		 */
		blockedReason(): string {
			return this.unavailable.length > 0 ? this.blockedHint : ''
		},

		options(): { value: Visibility, name: string, hint: string }[] {
			// Benannt nach dem Publikum, nicht nach der Technik (§7) — das traegt
			// auch bei rein internen Projekten, wo „oeffentlich" falsch klaenge.
			return [
				{
					value: 'private',
					name: t('projektwerk', 'Nur ich'),
					hint: t('projektwerk', 'Entwurf — niemand sonst sieht ihn'),
				},
				{
					value: 'internal',
					name: t('projektwerk', 'Intern'),
					hint: t('projektwerk', 'Nur meine Seite des Projekts'),
				},
				{
					value: 'public',
					name: t('projektwerk', 'Alle Beteiligten'),
					hint: t('projektwerk', 'Auch die Kundenseite sieht diesen Vorgang'),
				},
			]
		},
	},

	methods: {
		t,

		/**
		 * Eine Stufe wurde gewaehlt — nur weitergereicht, nie selbst uebernommen.
		 *
		 * **Die Markierung zeigt, was gilt, nicht was geklickt wurde.** Zwischen
		 * Klick und Antwort liegt ein Netzaufruf und moeglicherweise eine
		 * Rueckfrage; spraenge die Markierung sofort, saehe eine Aenderung
		 * erledigt aus, die noch aussteht.
		 *
		 * Der Nachtrag im `nextTick` ist der Preis fuer echte Radio-Knoepfe: Ein
		 * `<input type="radio">` setzt sich beim Klick **selbst**, und Vue
		 * korrigiert das nicht, weil sich aus seiner Sicht nichts geaendert hat
		 * — `modelValue` haengt am Ticket und steht noch auf dem alten Wert. Der
		 * handgebaute Schalter hatte dieses Problem nicht, weil ein `<button>`
		 * keinen Eigenzustand hat.
		 *
		 * @param value Die angeklickte Stufe.
		 */
		waehlen(value: string): void {
			this.$emit('update:modelValue', value as Visibility)

			this.$nextTick(() => {
				const radios = this.$el?.querySelectorAll?.('input[type="radio"]')
				radios?.forEach((radio: HTMLInputElement) => {
					radio.checked = radio.value === this.modelValue
				})
			})
		},

		/**
		 * @param value Die angefragte Stufe.
		 */
		isBlocked(value: Visibility): boolean {
			return this.unavailable.includes(value)
		},
	},
})
</script>
