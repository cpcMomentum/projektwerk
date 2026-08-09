<template>
	<div class="pw-view">
		<div class="pw-view__head">
			<h2>{{ t('projektwerk', 'Projekteinstellungen') }}</h2>
			<NcButton @click="back">
				{{ t('projektwerk', 'Zurück zum Projekt') }}
			</NcButton>
		</div>

		<!--
			Die Verwaltung steht nur internen Mitgliedern mit Verwaltungsrecht
			offen (§8). Der Server weist jeden Schreibversuch ohnehin mit 403 ab
			— hier faellt nur die Bedienung weg, damit niemand ein Formular
			ausfuellt, das er nicht abschicken darf.
		-->
		<NcEmptyContent
			v-if="!store.loading && !mayManage"
			:name="t('projektwerk', 'Keine Berechtigung')"
			:description="t('projektwerk', 'Projekteinstellungen pflegen interne Mitglieder mit Verwaltungsrecht.')">
			<template #icon>
				<LockIcon :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else-if="mayManage" class="pw-settings">
			<section class="pw-settings__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Projekt') }}
				</h3>

				<div class="pw-field">
					<label for="pw-set-title">{{ t('projektwerk', 'Titel') }}</label>
					<NcTextField id="pw-set-title" v-model="board.title" :label="t('projektwerk', 'Titel')" />
				</div>

				<div class="pw-field">
					<label for="pw-set-desc">{{ t('projektwerk', 'Beschreibung') }}</label>
					<textarea id="pw-set-desc" v-model="board.description" rows="3" />
				</div>

				<!--
					Beide Firmennamen stehen gleichberechtigt nebeneinander.
					Truege nur die Kundenseite eine Firma, waere die interne
					stumm „der Normalfall" (§8).
				-->
				<div class="pw-settings__pair">
					<div class="pw-field">
						<label for="pw-set-orgi">{{ t('projektwerk', 'Firma (eigene Seite)') }}</label>
						<NcTextField id="pw-set-orgi" v-model="board.orgInternal" :label="t('projektwerk', 'Firma (eigene Seite)')" />
					</div>
					<div class="pw-field">
						<label for="pw-set-orge">{{ t('projektwerk', 'Firma (Kundenseite)') }}</label>
						<NcTextField id="pw-set-orge" v-model="board.orgExternal" :label="t('projektwerk', 'Firma (Kundenseite)')" />
					</div>
				</div>

				<div class="pw-field">
					<label for="pw-set-chat">{{ t('projektwerk', 'Adresse des Projektchats') }}</label>
					<NcTextField id="pw-set-chat" v-model="board.chatUrl" :label="t('projektwerk', 'Adresse des Projektchats')" />
					<!-- Ohne Adresse entfaellt der Knopf ersatzlos (§9). -->
					<span class="pw-settings__hint">
						{{ t('projektwerk', 'Leer lassen blendet den Knopf „Zum Projektchat“ aus.') }}
					</span>
				</div>

				<div class="pw-viscontrol__actions">
					<NcButton variant="primary" :disabled="busy || board.title.trim() === ''" @click="saveBoard">
						{{ t('projektwerk', 'Speichern') }}
					</NcButton>
				</div>
			</section>

			<section class="pw-settings__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Spalten') }}
				</h3>

				<div v-for="(column, index) in store.columns" :key="column.id" class="pw-settings__row">
					<NcTextField
						:modelValue="column.title"
						:label="t('projektwerk', 'Name der Spalte')"
						@update:modelValue="renameColumnTo(column.id, $event)" />
					<!--
						Hoch und runter statt Ziehen: Jede Zieh-Geste braucht eine
						Alternative ohne Ziehen, und hier ist sie der einzige Weg
						— Tastatur und Screenreader sind Abnahmekriterium.
					-->
					<NcButton
						:disabled="busy || index === 0"
						:aria-label="t('projektwerk', 'Spalte nach oben')"
						@click="moveColumn(index, -1)">
						<template #icon>
							<ArrowUpIcon :size="20" />
						</template>
					</NcButton>
					<NcButton
						:disabled="busy || index === store.columns.length - 1"
						:aria-label="t('projektwerk', 'Spalte nach unten')"
						@click="moveColumn(index, 1)">
						<template #icon>
							<ArrowDownIcon :size="20" />
						</template>
					</NcButton>
				</div>

				<div class="pw-settings__row">
					<NcTextField
						v-model="newColumn"
						:label="t('projektwerk', 'Neue Spalte')"
						@keydown.enter="addColumn" />
					<NcButton :disabled="busy || newColumn.trim() === ''" @click="addColumn">
						{{ t('projektwerk', 'Hinzufügen') }}
					</NcButton>
				</div>
			</section>

			<section class="pw-settings__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Mitglieder') }}
				</h3>

				<div v-for="member in store.members" :key="member.userId" class="pw-settings__member">
					<NcAvatar
						:user="member.userId"
						:displayName="member.resolvedName"
						:size="32"
						:disableMenu="true" />

					<span class="pw-person__body">
						<span class="pw-person__name">{{ member.resolvedName }}</span>
						<span class="pw-person__org">{{ member.userId }}</span>
					</span>

					<select
						:value="member.role"
						:aria-label="t('projektwerk', 'Rolle')"
						:disabled="busy || isOwner(member)"
						@change="changeRole(member, $event)">
						<option value="internal">
							{{ t('projektwerk', 'Intern') }}
						</option>
						<option value="external">
							{{ t('projektwerk', 'Kundenseite') }}
						</option>
					</select>

					<!--
						Das Verwaltungsrecht gibt es nur fuer interne Mitglieder,
						und der Eigentuemer behaelt es immer (§8). Beides steht
						auch im Dienst — hier faellt nur der Schalter weg.
					-->
					<label class="pw-settings__check">
						<input
							type="checkbox"
							:checked="member.isManager"
							:disabled="busy || member.role === 'external' || isOwner(member)"
							@change="changeManager(member, $event)">
						{{ t('projektwerk', 'Verwaltung') }}
					</label>
				</div>

				<div class="pw-settings__row">
					<NcTextField
						v-model="newMember"
						:label="t('projektwerk', 'Benutzerkennung')" />
					<select v-model="newMemberRole" :aria-label="t('projektwerk', 'Rolle')">
						<option value="internal">
							{{ t('projektwerk', 'Intern') }}
						</option>
						<option value="external">
							{{ t('projektwerk', 'Kundenseite') }}
						</option>
					</select>
					<NcButton :disabled="busy || newMember.trim() === ''" @click="addMemberToBoard">
						{{ t('projektwerk', 'Hinzufügen') }}
					</NcButton>
				</div>
				<span class="pw-settings__hint">
					{{ t('projektwerk', 'Personenweise, keine Gruppen — die Rolle hängt an der Mitgliedschaft, nicht am Nextcloud-Konto.') }}
				</span>
			</section>

			<section class="pw-settings__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Archiv') }}
				</h3>
				<span class="pw-settings__hint">
					{{ t('projektwerk', 'Ein archiviertes Projekt bleibt lesbar und verschwindet aus der Projektliste.') }}
				</span>
				<div class="pw-viscontrol__actions">
					<NcButton :disabled="busy" @click="toggleArchived">
						{{ store.board?.archived ? t('projektwerk', 'Aus dem Archiv holen') : t('projektwerk', 'Archivieren') }}
					</NcButton>
				</div>
			</section>
		</div>
	</div>
</template>

<script lang="ts">
import type { Member, MemberRole } from '@/types/board'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import {
	addMember,
	createColumn,
	renameColumn,
	reorderColumns,
	setBoardArchived,
	updateBoard,
	updateMember,
} from '@/services/settings'
import { showError } from '@/services/toast'
import { useBoardStore } from '@/stores/boardStore'

/**
 * Projekt, Spalten, Mitglieder und Archiv pflegen.
 *
 * Die Dateiablage fehlt hier mit Absicht: Sie braucht den Ordner-Picker aus
 * `@nextcloud/dialogs`, und das Paket bricht den IIFE-Build (siehe
 * `src/types/globals.d.ts`). Sie gehört ohnehin zu den Anhängen ab Phase 7 —
 * ein Ordnerfeld ohne Anhänge wäre ein Versprechen ohne Einlösung.
 */
export default defineComponent({
	name: 'BoardSettingsView',

	components: { ArrowDownIcon, ArrowUpIcon, LockIcon, NcAvatar, NcButton, NcEmptyContent, NcTextField },

	setup() {
		return { store: useBoardStore() }
	},

	data() {
		return {
			busy: false,
			newColumn: '',
			newMember: '',
			newMemberRole: 'external' as MemberRole,
			// Ein eigener Entwurf statt direkter Bindung an den Speicher: Sonst
			// stuenden Tippfehler sofort in der Kopfzeile des Boards, und ein
			// Abbruch waere nicht mehr moeglich.
			board: { title: '', description: '', orgInternal: '', orgExternal: '', chatUrl: '' },
		}
	},

	computed: {
		boardId(): number {
			return Number(this.$route.params.boardId)
		},

		/** §8: Pflegen darf nur ein internes Mitglied mit Verwaltungsrecht. */
		mayManage(): boolean {
			return this.store.viewer?.isManager === true
		},
	},

	watch: {
		boardId: {
			immediate: true,
			async handler(id: number) {
				await this.store.open(id)
				this.fillDraft()
			},
		},
	},

	methods: {
		t,

		fillDraft() {
			const board = this.store.board
			if (board === null) {
				return
			}
			this.board = {
				title: board.title,
				description: board.description ?? '',
				orgInternal: board.orgInternal ?? '',
				orgExternal: board.orgExternal ?? '',
				chatUrl: board.chatUrl ?? '',
			}
		},

		back() {
			this.$router.push({ name: 'board', params: { boardId: String(this.boardId) } })
		},

		/**
		 * @param member Die Mitgliedschaft.
		 */
		isOwner(member: Member): boolean {
			return this.store.board?.ownerUserId === member.userId
		},

		/**
		 * Einen Schreibvorgang fahren und danach neu laden.
		 *
		 * Neu laden statt lokal nachzuziehen: Rolle und Verwaltungsrecht
		 * korrigiert der Server nach §8 gegebenenfalls selbst — ein extern
		 * gesetztes Verwaltungsrecht kommt als `false` zurück. Eine lokale
		 * Vorwegnahme zeigte einen Zustand, den es auf dem Server nie gab.
		 *
		 * @param run Der Aufruf.
		 * @param fallback Meldung, wenn der Server keine eigene mitgibt.
		 */
		async write(run: () => Promise<unknown>, fallback: string): Promise<void> {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				await run()
				await this.store.open(this.boardId)
				this.fillDraft()
			} catch (e) {
				showError((e as { message?: string }).message ?? fallback)
			} finally {
				this.busy = false
			}
		},

		saveBoard() {
			return this.write(
				() => updateBoard(this.boardId, {
					title: this.board.title.trim(),
					description: this.blankToNull(this.board.description),
					orgInternal: this.blankToNull(this.board.orgInternal),
					orgExternal: this.blankToNull(this.board.orgExternal),
					chatUrl: this.blankToNull(this.board.chatUrl),
				}),
				t('projektwerk', 'Speichern fehlgeschlagen'),
			)
		},

		addColumn() {
			const title = this.newColumn.trim()
			if (title === '') {
				return
			}
			return this.write(
				async () => {
					await createColumn(this.boardId, title)
					this.newColumn = ''
				},
				t('projektwerk', 'Spalte konnte nicht angelegt werden'),
			)
		},

		/**
		 * @param columnId Kennung der Spalte.
		 * @param title Der neue Name.
		 */
		renameColumnTo(columnId: number, title: string) {
			if (title.trim() === '') {
				return
			}
			return this.write(
				() => renameColumn(this.boardId, columnId, title.trim()),
				t('projektwerk', 'Umbenennen fehlgeschlagen'),
			)
		},

		/**
		 * Eine Spalte um einen Platz verschieben.
		 *
		 * Der Server verlangt die **vollständige** Reihenfolge und weist eine
		 * unvollständige ab — sonst entschiede über die nicht genannten Spalten
		 * der Zufall. Deshalb wird hier die ganze Liste getauscht und geschickt.
		 *
		 * @param index Aktuelle Position.
		 * @param delta -1 nach oben, +1 nach unten.
		 */
		moveColumn(index: number, delta: number) {
			const ids = this.store.columns.map((c) => c.id)
			const target = index + delta
			if (target < 0 || target >= ids.length) {
				return
			}
			const moved = ids[index]
			ids[index] = ids[target]
			ids[target] = moved

			return this.write(
				() => reorderColumns(this.boardId, ids),
				t('projektwerk', 'Reihenfolge konnte nicht gespeichert werden'),
			)
		},

		addMemberToBoard() {
			const userId = this.newMember.trim()
			if (userId === '') {
				return
			}
			return this.write(
				async () => {
					await addMember(this.boardId, { userId, role: this.newMemberRole })
					this.newMember = ''
				},
				t('projektwerk', 'Mitglied konnte nicht hinzugefügt werden'),
			)
		},

		/**
		 * @param member Die Mitgliedschaft.
		 * @param event Das Auswahlereignis.
		 */
		changeRole(member: Member, event: Event) {
			const role = (event.target as HTMLSelectElement).value as MemberRole

			return this.write(
				() => updateMember(this.boardId, member.userId, { role }),
				t('projektwerk', 'Rolle konnte nicht geändert werden'),
			)
		},

		/**
		 * @param member Die Mitgliedschaft.
		 * @param event Das Umschaltereignis.
		 */
		changeManager(member: Member, event: Event) {
			const isManager = (event.target as HTMLInputElement).checked

			return this.write(
				() => updateMember(this.boardId, member.userId, { isManager }),
				t('projektwerk', 'Verwaltungsrecht konnte nicht geändert werden'),
			)
		},

		toggleArchived() {
			const archived = this.store.board?.archived !== true

			return this.write(
				() => setBoardArchived(this.boardId, archived),
				t('projektwerk', 'Archivieren fehlgeschlagen'),
			)
		},

		/**
		 * @param value Der eingegebene Text.
		 */
		blankToNull(value: string): string | null {
			const trimmed = value.trim()
			return trimmed === '' ? null : trimmed
		},
	},
})
</script>
