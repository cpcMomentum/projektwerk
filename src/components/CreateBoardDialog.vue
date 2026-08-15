<template>
	<NcDialog
		:open="open"
		:name="t('projektwerk', 'Neues Projekt')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<!--
			Die App-Klasse MUSS hier drin stehen: NcDialog teleportiert seinen
			Inhalt an den `body`, wo `.app-projektwerk` kein Vorfahr mehr ist.
			Ohne sie stünden die Felder unformatiert da (wie im Vorgangs-Dialog).
		-->
		<div class="app-projektwerk">
			<div class="pw-field">
				<label for="pw-newboard-title">{{ t('projektwerk', 'Titel') }}</label>
				<NcTextField
					id="pw-newboard-title"
					v-model="title"
					:label="t('projektwerk', 'Titel')" />
			</div>

			<div class="pw-field">
				<label for="pw-newboard-desc">{{ t('projektwerk', 'Beschreibung') }}</label>
				<textarea id="pw-newboard-desc" v-model="description" rows="3" />
			</div>

			<!--
				Beide Firmennamen gleichberechtigt, wie in den Board-Einstellungen:
				Trüge nur die Kundenseite eine Firma, wäre die eigene stumm „der
				Normalfall". Beide sind optional und lassen sich später ändern.
			-->
			<div class="pw-field">
				<label for="pw-newboard-orgi">{{ t('projektwerk', 'Firma (eigene Seite)') }}</label>
				<NcTextField
					id="pw-newboard-orgi"
					v-model="orgInternal"
					:label="t('projektwerk', 'Firma (eigene Seite)')" />
			</div>

			<div class="pw-field">
				<label for="pw-newboard-orge">{{ t('projektwerk', 'Firma (Kundenseite)') }}</label>
				<NcTextField
					id="pw-newboard-orge"
					v-model="orgExternal"
					:label="t('projektwerk', 'Firma (Kundenseite)')" />
			</div>
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
import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default defineComponent({
	name: 'CreateBoardDialog',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		open: { type: Boolean, default: false },
	},

	emits: ['update:open', 'create'],

	data() {
		return {
			title: '',
			description: '',
			orgInternal: '',
			orgExternal: '',
		}
	},

	computed: {
		canSave(): boolean {
			return this.title.trim() !== ''
		},
	},

	watch: {
		open(isOpen: boolean) {
			if (isOpen) {
				this.title = ''
				this.description = ''
				this.orgInternal = ''
				this.orgExternal = ''
			}
		},
	},

	methods: {
		t,

		/** Ein leeres Feld wird zu `null` — nicht zu einem leeren String. */
		save() {
			if (!this.canSave) {
				return
			}
			const trim = (value: string): string | null => (value.trim() === '' ? null : value.trim())
			this.$emit('create', {
				title: this.title.trim(),
				description: trim(this.description),
				orgInternal: trim(this.orgInternal),
				orgExternal: trim(this.orgExternal),
			})
		},
	},
})
</script>
