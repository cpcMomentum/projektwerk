<template>
	<nav class="pw-subnav" :aria-label="ariaLabel || t('projektwerk', 'Bereiche')">
		<button
			v-for="s in sections"
			:key="s.key"
			type="button"
			class="pw-subnav__item"
			:class="{ 'pw-subnav__item--active': modelValue === s.key }"
			:aria-current="modelValue === s.key ? 'page' : undefined"
			@click="$emit('update:modelValue', s.key)">
			{{ s.label }}
		</button>
	</nav>
</template>

<script lang="ts">
import type { PropType } from 'vue'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'

/** Ein Bereich in der linken Einstellungs-Navigation. */
export interface SettingsSection {
	key: string
	label: string
}

/**
 * Die linke Bereichs-Navigation der Einstellungsseiten (#195).
 *
 * Ein gemeinsames Bauteil für „Meine Einstellungen" und die
 * Projekteinstellungen, damit beide gleich aussehen. Es hält keinen eigenen
 * Zustand: Der aktive Bereich kommt über `modelValue` herein und geht über
 * `update:modelValue` zurück — die Seite entscheidet, was sie darunter zeigt.
 *
 * Die Beschriftung ist zugleich der zugängliche Name des Knopfes; e2e-Tests
 * klicken darüber (z. B. „Dateiablage"), also müssen die Labels stabil bleiben.
 */
export default defineComponent({
	name: 'PwSettingsNav',

	props: {
		/** Die Bereiche in Anzeigereihenfolge. */
		sections: { type: Array as PropType<SettingsSection[]>, required: true },
		/** Der aktive Bereichsschlüssel. */
		modelValue: { type: String, required: true },
		/** Beschriftung der Navigation für Screenreader; leer = Vorgabe „Bereiche". */
		ariaLabel: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	methods: { t },
})
</script>
