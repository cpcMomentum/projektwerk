<template>
	<nav class="pw-subnav" :aria-label="ariaLabel || t('projektwerk', 'Bereiche')">
		<div
			v-for="group in blocks"
			:key="group.key"
			class="pw-subnav__block"
			:role="group.label ? 'group' : undefined"
			:aria-labelledby="group.label ? `pw-subnav-${group.key}` : undefined">
			<div v-if="group.label" :id="`pw-subnav-${group.key}`" class="pw-subnav__group">
				{{ group.label }}
			</div>
			<slot :name="`group-${group.key}`" />
			<button
				v-for="s in group.items"
				:key="s.key"
				type="button"
				class="pw-subnav__item"
				:class="{ 'pw-subnav__item--active': modelValue === s.key }"
				:aria-current="modelValue === s.key ? 'page' : undefined"
				@click="$emit('update:modelValue', s.key)">
				{{ s.label }}
			</button>
		</div>
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

/** Eine Überschrift mit ihren Bereichen. */
export interface SettingsGroup {
	key: string
	label: string
	items: SettingsSection[]
}

/** Labels sind zugleich die zugänglichen Namen, über die e2e-Tests klicken: stabil halten. */
export default defineComponent({
	name: 'PwSettingsNav',

	props: {
		/** Flache Bereiche, wenn keine Gruppen gesetzt sind. */
		sections: { type: Array as PropType<SettingsSection[]>, default: () => [] },
		/** Gruppen mit Überschrift; `role="group"` unterscheidet gleichnamige Einträge. */
		groups: { type: Array as PropType<SettingsGroup[]>, default: () => [] },
		modelValue: { type: String, required: true },
		ariaLabel: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	computed: {
		blocks(): SettingsGroup[] {
			return this.groups.length > 0 ? this.groups : [{ key: 'alle', label: '', items: this.sections }]
		},
	},

	methods: { t },
})
</script>
