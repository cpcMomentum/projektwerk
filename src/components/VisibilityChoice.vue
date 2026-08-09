<template>
	<div class="pw-visrow">
		<button
			v-for="option in options"
			:key="option.value"
			type="button"
			class="pw-visopt"
			:aria-pressed="modelValue === option.value"
			:disabled="isBlocked(option.value)"
			:title="isBlocked(option.value) ? blockedHint : undefined"
			@click="$emit('update:modelValue', option.value)">
			<AccountMultipleIcon v-if="option.value === 'public'" :size="20" />
			<OfficeBuildingIcon v-else-if="option.value === 'internal'" :size="20" />
			<PencilIcon v-else :size="20" />
			<span class="pw-visopt__body">
				<span class="pw-visopt__name">{{ option.name }}</span>
				<span class="pw-visopt__hint">
					{{ isBlocked(option.value) ? blockedHint : option.hint }}
				</span>
			</span>
		</button>
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
	},

	emits: ['update:modelValue'],

	computed: {
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
