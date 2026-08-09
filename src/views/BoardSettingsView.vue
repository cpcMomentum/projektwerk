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
					<!--
						Ein Entwurf je Spalte, gespeichert erst bei Enter oder
						beim Verlassen des Feldes. Direkt am Tastendruck zu
						speichern hiesse ein PATCH je Zeichen — und weil
						`write()` waehrend eines laufenden Aufrufs weitere
						verwirft, gingen Anschlaege verloren und das Feld spraenge
						auf den zuletzt gespeicherten Stand zurueck.
					-->
					<NcTextField
						:modelValue="draftFor(column)"
						:label="t('projektwerk', 'Name der Spalte')"
						@update:modelValue="columnDrafts[column.id] = $event"
						@keydown.enter="commitColumn(column)"
						@blur="commitColumn(column)" />
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
					<!--
						Eigener Endpunkt statt Nextclouds Personensuche: Die
						liefert in Gast-Sitzungen eine leere Liste, und ein
						Aufruf mit dieser Eigenschaft waere irgendwann an einer
						Stelle kopiert, wo Gaeste hinkommen.
					-->
					<NcTextField
						v-model="memberSearch"
						:label="t('projektwerk', 'Person suchen')"
						@update:modelValue="searchPeople" />
					<select v-model="newMemberRole" :aria-label="t('projektwerk', 'Rolle')">
						<option value="internal">
							{{ t('projektwerk', 'Intern') }}
						</option>
						<option value="external">
							{{ t('projektwerk', 'Kundenseite') }}
						</option>
					</select>
					<NcButton :disabled="busy || newMember === ''" @click="addMemberToBoard">
						{{ t('projektwerk', 'Hinzufügen') }}
					</NcButton>
				</div>

				<div v-if="candidates.length > 0" class="pw-settings__hits">
					<button
						v-for="person in candidates"
						:key="person.userId"
						type="button"
						class="pw-settings__hit"
						:aria-pressed="newMember === person.userId"
						@click="newMember = person.userId">
						<!--
							Name UND Kennung: Zwei Konten mit gleichem
							Anzeigenamen waeren sonst nicht unterscheidbar.
						-->
						<span class="pw-person__name">{{ person.displayName }}</span>
						<span class="pw-person__org" :title="person.userId">{{ person.userId }}</span>
					</button>
				</div>
				<span v-else-if="memberSearch.trim() !== '' && !searching" class="pw-settings__hint">
					{{ t('projektwerk', 'Keine passenden Konten gefunden.') }}
				</span>
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
import type { Candidate } from '@/services/settings'
import type { Column, Member, MemberRole } from '@/types/board'

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
	searchCandidates,
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
			/** Entwurf je Spalte, solange getippt wird. */
			columnDrafts: {} as Record<number, string>,
			newMember: '',
			memberSearch: '',
			searching: false,
			candidates: [] as Candidate[],
			searchTimer: null as ReturnType<typeof setTimeout> | null,
			searchToken: 0,
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
				// Die Entwuerfe sind mit dem Neuladen ueberholt; blieben sie
				// stehen, ueberdeckten sie den Stand vom Server.
				this.columnDrafts = {}
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
		 * Der Text im Feld: der Entwurf, sonst der gespeicherte Name.
		 *
		 * @param column Die Spalte.
		 */
		draftFor(column: Column): string {
			return this.columnDrafts[column.id] ?? column.title
		},

		/**
		 * Den Entwurf speichern — bei Enter oder beim Verlassen des Feldes.
		 *
		 * Ein leerer Name wird verworfen statt geschickt: Der Server wiese ihn
		 * ab, und die Meldung waere die Antwort auf ein Versehen. Das Feld
		 * kehrt dann zum gespeicherten Namen zurueck.
		 *
		 * @param column Die Spalte.
		 */
		commitColumn(column: Column) {
			const draft = this.columnDrafts[column.id]
			if (draft === undefined) {
				return
			}

			const title = draft.trim()
			if (title === '' || title === column.title) {
				delete this.columnDrafts[column.id]

				return
			}

			return this.write(
				() => renameColumn(this.boardId, column.id, title),
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

		/**
		 * Konten suchen — gedrosselt, nicht bei jedem Tastendruck.
		 *
		 * Eine Suche je Zeichen hiesse ein Aufruf pro Anschlag. Das Zeitfenster
		 * allein reicht aber nicht: Zwei tatsaechlich abgeschickte Anfragen
		 * koennen im Netz die Reihenfolge tauschen. Ein Zaehler je Anfrage
		 * verwirft deshalb jede Antwort, die nicht mehr zur letzten gehoert.
		 */
		searchPeople() {
			if (this.searchTimer !== null) {
				clearTimeout(this.searchTimer)
			}
			this.newMember = ''
			this.searchToken += 1

			const begriff = this.memberSearch.trim()
			if (begriff === '') {
				this.candidates = []
				this.searching = false

				return
			}

			this.searching = true
			const token = this.searchToken
			this.searchTimer = setTimeout(async () => {
				try {
					const treffer = await searchCandidates(this.boardId, begriff)
					if (token === this.searchToken) {
						this.candidates = treffer
					}
				} catch (e) {
					if (token === this.searchToken) {
						// Ohne Verwaltungsrecht antwortet der Server mit 403 statt
						// mit einer leeren Liste — die Meldung sagt dann, woran es
						// liegt, statt „niemand gefunden" vorzutäuschen.
						this.candidates = []
						showError((e as { message?: string }).message ?? t('projektwerk', 'Suche fehlgeschlagen'))
					}
				} finally {
					if (token === this.searchToken) {
						this.searching = false
					}
				}
			}, 300)
		},

		addMemberToBoard() {
			const userId = this.newMember
			if (userId === '') {
				return
			}
			return this.write(
				async () => {
					await addMember(this.boardId, { userId, role: this.newMemberRole })
					this.newMember = ''
					this.memberSearch = ''
					this.candidates = []
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
