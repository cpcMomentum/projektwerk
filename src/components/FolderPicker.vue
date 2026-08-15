<template>
	<NcDialog
		:open="open"
		:name="t('projektwerk', 'Ordner wählen')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="app-projektwerk">
			<div class="pw-folderpick">
				<!--
				Wo man gerade steht. Die Wurzel trägt keinen Namen im Dateibaum,
				deshalb „Meine Dateien" — und von dort führt kein „hoch" mehr.
			-->
				<div class="pw-folderpick__bar">
					<NcButton
						variant="tertiary"
						:disabled="loading || current === ''"
						:aria-label="t('projektwerk', 'Eine Ebene höher')"
						@click="goUp">
						<template #icon>
							<ArrowUpIcon :size="20" />
						</template>
					</NcButton>
					<span class="pw-folderpick__crumb">{{ current === '' ? t('projektwerk', 'Meine Dateien') : current }}</span>
				</div>

				<div v-if="loading" class="pw-folderpick__note">
					{{ t('projektwerk', 'Wird geladen…') }}
				</div>
				<div v-else-if="error !== null" class="pw-folderpick__note pw-folderpick__note--error">
					{{ error }}
				</div>
				<div v-else-if="entries.length === 0" class="pw-folderpick__note">
					{{ t('projektwerk', 'Hier gibt es keine Unterordner.') }}
				</div>
				<ul v-else class="pw-folderpick__list">
					<li v-for="entry in entries" :key="entry.path">
						<button type="button" class="pw-folderpick__item" @click="enter(entry)">
							<FolderIcon :size="20" />
							<span>{{ entry.name }}</span>
							<ChevronRightIcon :size="20" class="pw-folderpick__chev" />
						</button>
					</li>
				</ul>

				<!--
				Anlegen sitzt im Wähler selbst: Wer den Zielordner noch nicht hat,
				legt ihn dort an, wo er gerade steht, und ist gleich drin.
			-->
				<div class="pw-folderpick__new">
					<NcTextField
						v-model="newName"
						:label="t('projektwerk', 'Name des neuen Ordners')"
						:placeholder="t('projektwerk', 'Neuer Ordner')"
						:disabled="loading || creating"
						@keydown.enter="create" />
					<NcButton :disabled="loading || creating || newName.trim() === ''" @click="create">
						{{ t('projektwerk', 'Ordner anlegen') }}
					</NcButton>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('projektwerk', 'Abbrechen') }}
			</NcButton>
			<!--
				Gewählt wird der Ordner, in dem man steht. Die Wurzel selbst ist
				kein gültiger Ablageort (der Server lehnt sie ab), deshalb dort
				gesperrt.
			-->
			<NcButton
				variant="primary"
				:disabled="loading || current === ''"
				@click="choose">
				{{ t('projektwerk', 'Diesen Ordner wählen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script lang="ts">
import type { FolderEntry } from '@/services/folders'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import { createFolder, folderChildren } from '@/services/folders'

export default defineComponent({
	name: 'FolderPicker',

	components: { NcButton, NcDialog, NcTextField, ArrowUpIcon, ChevronRightIcon, FolderIcon },

	props: {
		open: { type: Boolean, default: false },
		/** Der Ordner, in dem der Wähler öffnet — leer heißt Wurzel. */
		startPath: { type: String, default: '' },
	},

	emits: ['update:open', 'select'],

	data() {
		return {
			current: '',
			entries: [] as FolderEntry[],
			newName: '',
			loading: false,
			creating: false,
			error: null as string | null,
		}
	},

	watch: {
		open(isOpen: boolean) {
			if (isOpen) {
				this.newName = ''
				// Vom hinterlegten Ordner aus starten, wenn es einen gibt — sonst
				// von der Wurzel. Ein veralteter Pfad fällt sanft auf die Wurzel
				// zurück (siehe load()).
				this.load(this.startPath)
			}
		},
	},

	methods: {
		t,

		/**
		 * Ein Verzeichnis laden und anzeigen.
		 *
		 * Schlägt der Pfad fehl (etwa weil der hinterlegte Ordner umbenannt
		 * wurde), fällt der Wähler auf die Wurzel zurück, statt den Nutzer vor
		 * einer Fehlermeldung stehen zu lassen.
		 *
		 * @param path Pfad relativ zur Files-Wurzel.
		 */
		async load(path: string): Promise<void> {
			this.loading = true
			this.error = null
			try {
				this.entries = await folderChildren(path)
				this.current = path.replace(/\/+$/, '').replace(/^\/+/, '')
			} catch (e) {
				if (path !== '') {
					await this.load('')
					return
				}
				this.error = (e as { message?: string }).message ?? t('projektwerk', 'Die Ordner konnten nicht geladen werden.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * In einen Unterordner wechseln.
		 *
		 * @param entry Der gewählte Unterordner.
		 */
		enter(entry: FolderEntry): void {
			this.load(entry.path)
		},

		goUp(): void {
			const parent = this.current.split('/').slice(0, -1).join('/')
			this.load(parent)
		},

		async create(): Promise<void> {
			const name = this.newName.trim()
			if (name === '' || this.creating) {
				return
			}
			this.creating = true
			this.error = null
			try {
				const target = this.current === '' ? name : this.current + '/' + name
				await createFolder(target)
				this.newName = ''
				await this.load(target)
			} catch (e) {
				this.error = (e as { message?: string }).message ?? t('projektwerk', 'Der Ordner konnte nicht angelegt werden.')
			} finally {
				this.creating = false
			}
		},

		choose(): void {
			if (this.current === '') {
				return
			}
			this.$emit('select', this.current)
			this.$emit('update:open', false)
		},
	},
})
</script>

<style scoped>
.pw-folderpick {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 280px;
}

.pw-folderpick__bar {
	display: flex;
	align-items: center;
	gap: 8px;
}

.pw-folderpick__crumb {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pw-folderpick__note {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.pw-folderpick__note--error {
	color: var(--color-error);
}

.pw-folderpick__list {
	flex: 1;
	overflow-y: auto;
	max-height: 320px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.pw-folderpick__item {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 10px 12px;
	background: transparent;
	border: none;
	border-radius: 0;
	text-align: start;
	cursor: pointer;
}

.pw-folderpick__item:hover {
	background: var(--color-background-hover);
}

.pw-folderpick__chev {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
}

.pw-folderpick__new {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}
</style>
