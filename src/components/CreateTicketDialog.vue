<template>
	<NcDialog
		:open="open"
		:name="t('projektwerk', 'Neuer Vorgang')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="pw-field">
			<label for="pw-new-title">{{ t('projektwerk', 'Titel') }}</label>
			<NcTextField
				id="pw-new-title"
				v-model="title"
				:label="t('projektwerk', 'Titel')" />
		</div>

		<div class="pw-field">
			<label for="pw-new-desc">{{ t('projektwerk', 'Beschreibung') }}</label>
			<textarea id="pw-new-desc" v-model="description" rows="4" />
		</div>

		<!--
			Die Sichtbarkeitszeile steht neben Titel und Beschreibung, ist NIE
			eingeklappt und nie hinter einem Zahnrad (§9). Voreinstellung „Alle
			Beteiligten", fuer alle Rollen gleich.
		-->
		<div class="pw-field">
			<label>{{ t('projektwerk', 'Wer sieht diesen Vorgang?') }}</label>
			<div class="pw-visrow">
				<button
					v-for="option in options"
					:key="option.value"
					type="button"
					class="pw-visopt"
					:aria-pressed="visibility === option.value"
					@click="visibility = option.value">
					<AccountMultipleIcon v-if="option.value === 'public'" :size="20" />
					<OfficeBuildingIcon v-else-if="option.value === 'internal'" :size="20" />
					<PencilIcon v-else :size="20" />
					<span class="pw-visopt__body">
						<span class="pw-visopt__name">{{ option.name }}</span>
						<span class="pw-visopt__hint">{{ option.hint }}</span>
					</span>
				</button>
			</div>
		</div>

		<div class="pw-field">
			<label for="pw-new-col">{{ t('projektwerk', 'Spalte') }}</label>
			<select id="pw-new-col" v-model="columnId">
				<option v-for="column in columns" :key="column.id" :value="column.id">
					{{ column.title }}
				</option>
			</select>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('projektwerk', 'Abbrechen') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave" @click="save">
				{{ t('projektwerk', 'Anlegen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column, Visibility } from '@/types/board'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default defineComponent({
	name: 'CreateTicketDialog',

	components: { NcButton, NcDialog, NcTextField, AccountMultipleIcon, OfficeBuildingIcon, PencilIcon },

	props: {
		open: { type: Boolean, default: false },
		columns: { type: Array as PropType<Column[]>, default: () => [] },
	},

	emits: ['update:open', 'create'],

	data() {
		return {
			title: '',
			description: '',
			// „Alle Beteiligten" ist die Voreinstellung, fuer alle Rollen gleich (§9).
			visibility: 'public' as Visibility,
			columnId: null as number | null,
		}
	},

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

		canSave(): boolean {
			return this.title.trim() !== '' && this.columnId !== null
		},
	},

	watch: {
		open(isOpen: boolean) {
			if (isOpen) {
				this.title = ''
				this.description = ''
				this.visibility = 'public'
				this.columnId = this.columns[0]?.id ?? null
			}
		},
	},

	methods: {
		t,

		save() {
			if (!this.canSave) {
				return
			}
			this.$emit('create', {
				title: this.title.trim(),
				description: this.description.trim() === '' ? null : this.description.trim(),
				visibility: this.visibility,
				columnId: this.columnId as number,
			})
		},
	},
})
</script>
