<template>
	<div class="pw-vischoice">
		<!--
			Segmentierter Schalter nach dem Muster aus WorkTime (`layout-seg` /
			`seg-btn`, dort in Zeiterfassung und Auswertung). Uebernommen und nicht
			neu erfunden: Die Flotte soll an gleichen Stellen gleich aussehen.

			`role="group"` und `aria-pressed` gehen darueber hinaus — WorkTime
			markiert den aktiven Knopf nur ueber eine Klasse, und eine Klasse sagt
			einem Screenreader nichts. Hier haengt an der Auswahl, wer den Vorgang
			sieht; das darf nicht nur sichtbar sein.
		-->
		<div class="pw-visrow" role="group" :aria-label="t('projektwerk', 'Sichtbarkeit')">
			<button
				v-for="option in options"
				:key="option.value"
				type="button"
				class="pw-visopt"
				:aria-pressed="modelValue === option.value"
				:disabled="isBlocked(option.value) || busy"
				@click="$emit('update:modelValue', option.value)">
				<AccountMultipleIcon v-if="option.value === 'public'" :size="16" />
				<OfficeBuildingIcon v-else-if="option.value === 'internal'" :size="16" />
				<PencilIcon v-else :size="16" />
				{{ option.name }}
			</button>
		</div>

		<!--
			Die Erklaerung stand frueher in jeder Karte und machte den Abschnitt
			dreimal so hoch. Sie gehoert aber nur zu der Stufe, die gilt — was die
			anderen beiden bedeuten, erfaehrt man, indem man sie waehlt. Der Satz
			bleibt damit da, wo er gebraucht wird, und kostet eine Zeile statt
			neun.
		-->
		<p class="pw-vishint">
			{{ selectedHint }}
		</p>

		<!--
			Warum eine Stufe fehlt, muss **sichtbar** dastehen. Frueher trug der
			gesperrte Knopf den Grund im Text; als `title` allein waere er auf dem
			Telefon unerreichbar, weil es dort kein Ueberfahren gibt.
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
 * Die Reihenfolge public → internal → private ist **Anzeige, keine Rangfolge**.
 * Ob ein Wechsel jemandem den Zugriff nimmt, beantwortet der Server über
 * `visibility-impact`; hier wird nicht verglichen.
 */
export default defineComponent({
	name: 'VisibilityChoice',

	components: { AccountMultipleIcon, OfficeBuildingIcon, PencilIcon },

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
					value: 'public',
					name: t('projektwerk', 'Alle Beteiligten'),
					hint: t('projektwerk', 'Auch die Kundenseite sieht diesen Vorgang'),
				},
				{
					value: 'internal',
					name: t('projektwerk', 'Intern'),
					hint: t('projektwerk', 'Nur meine Seite des Projekts'),
				},
				{
					value: 'private',
					name: t('projektwerk', 'Nur ich'),
					hint: t('projektwerk', 'Entwurf — niemand sonst sieht ihn'),
				},
			]
		},
	},

	methods: {
		t,

		/**
		 * @param value Die angefragte Stufe.
		 */
		isBlocked(value: Visibility): boolean {
			return this.unavailable.includes(value)
		},
	},
})
</script>
