<template>
	<NcDialog
		:open="open"
		:name="dialogName"
		size="normal"
		@update:open="onToggle">
		<!--
			Die App-Klasse MUSS hier drin stehen: NcDialog teleportiert seinen
			Inhalt an den `body`, wo `.app-projektwerk` kein Vorfahr mehr ist.
			Ohne sie stünden die Felder unformatiert da.
		-->
		<div class="app-projektwerk">
			<!--
				Fortschritt in Worten, nicht als Balken: Fünf Schritte lassen
				sich zählen, und wer bei „3 von 5" steht, weiß, was noch kommt.
			-->
			<p class="pw-wizard__progress">
				{{ t('projektwerk', 'Schritt {current} von {total}', { current: step + 1, total: STEP_COUNT }) }}
				· {{ stepTitle }}
			</p>

			<!-- Schritt 1: Projekt -->
			<section v-if="step === 0" class="pw-wizard__step">
				<div class="pw-field">
					<label for="pw-wiz-title">{{ t('projektwerk', 'Titel') }}</label>
					<NcTextField
						id="pw-wiz-title"
						v-model="title"
						:label="t('projektwerk', 'Titel')" />
				</div>
				<div class="pw-field">
					<label for="pw-wiz-desc">{{ t('projektwerk', 'Beschreibung') }}</label>
					<textarea id="pw-wiz-desc" v-model="description" rows="3" />
				</div>
			</section>

			<!-- Schritt 2: Die zwei Seiten -->
			<section v-else-if="step === 1" class="pw-wizard__step">
				<!--
					Beide Firmennamen gleichberechtigt: Trüge nur die Kundenseite
					eine Firma, wäre die eigene stumm „der Normalfall". Beide sind
					optional und lassen sich später ändern.
				-->
				<p class="pw-settings__hint">
					{{ t('projektwerk', 'Die beiden Seiten des Projekts. Beides ist optional und jederzeit änderbar.') }}
				</p>
				<div class="pw-field">
					<label for="pw-wiz-orgi">{{ t('projektwerk', 'Firma (eigene Seite)') }}</label>
					<NcTextField
						id="pw-wiz-orgi"
						v-model="orgInternal"
						:label="t('projektwerk', 'Firma (eigene Seite)')" />
				</div>
				<div class="pw-field">
					<label for="pw-wiz-orge">{{ t('projektwerk', 'Firma (Kundenseite)') }}</label>
					<NcTextField
						id="pw-wiz-orge"
						v-model="orgExternal"
						:label="t('projektwerk', 'Firma (Kundenseite)')" />
				</div>
			</section>

			<!-- Schritt 3: Mitglieder -->
			<section v-else-if="step === 2" class="pw-wizard__step">
				<div class="pw-wizard__memberadd">
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
					<NcButton :disabled="saving || newMember === ''" @click="addMemberToBoard">
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
						@click="selectCandidate(person)">
						<span class="pw-person__name">{{ person.displayName }}</span>
						<span class="pw-person__org" :title="person.userId">{{ person.userId }}</span>
					</button>
				</div>
				<span v-else-if="memberSearch.trim() !== '' && !searching" class="pw-settings__hint">
					{{ t('projektwerk', 'Keine passenden Konten gefunden.') }}
				</span>

				<!-- Was in diesem Durchlauf schon hinzugefügt wurde. -->
				<ul v-if="addedMembers.length > 0" class="pw-wizard__added">
					<li v-for="member in addedMembers" :key="member.userId">
						<span class="pw-person__name">{{ member.resolvedName }}</span>
						<span class="pw-person__org">{{ roleLabel(member.role) }}</span>
					</li>
				</ul>

				<span class="pw-settings__hint">
					{{ t('projektwerk', 'Personenweise, keine Gruppen — die Rolle hängt an der Mitgliedschaft, nicht am Nextcloud-Konto.') }}
				</span>
				<!--
					Die Suche findet nur bestehende Konten. Ohne diesen Satz
					sucht jemand eine Kundin, die es in Nextcloud noch gar nicht
					gibt, bekommt „Keine passenden Konten gefunden." und rät,
					woran es liegt. Die App legt bewusst keine Konten und keine
					Freigaben an (#74) — sie kann aber sagen, wo sie herkommen,
					und in die Dateien führen, wo eine Freigabe ein Gastkonto
					entstehen lässt.
				-->
				<span class="pw-settings__hint">
					{{ t('projektwerk', 'Gefunden werden nur bestehende Nextcloud-Konten. Wer noch keines hat — etwa auf Kundenseite — braucht zuerst eines: Die Administration legt es an, als Gastzugang oder als Vollkonto.') }}
				</span>
				<span class="pw-settings__hint">
					{{ t('projektwerk', 'Ein Gastkonto entsteht, wenn du in „Dateien“ einen Ordner an die E-Mail-Adresse der Person freigibst.') }}
					<a
						:href="filesUrl"
						target="_blank"
						rel="noopener noreferrer"
						class="pw-wizard__link">
						{{ t('projektwerk', 'Dateien öffnen') }}
					</a>
				</span>
			</section>

			<!-- Schritt 4: Spalten -->
			<section v-else-if="step === 3" class="pw-wizard__step">
				<p class="pw-settings__hint">
					{{ t('projektwerk', 'Diese Spalten wurden angelegt. Anpassen, umbenennen oder ergänzen kannst du sie jederzeit in den Projekteinstellungen.') }}
				</p>
				<ol v-if="columns.length > 0" class="pw-wizard__columns">
					<li v-for="column in columns" :key="column.id">
						{{ column.title }}
					</li>
				</ol>
				<span v-else class="pw-settings__hint">
					{{ t('projektwerk', 'Die Standardspalten stehen bereit.') }}
				</span>
			</section>

			<!-- Schritt 5: Projektchat -->
			<section v-else-if="step === 4" class="pw-wizard__step">
				<div class="pw-field">
					<label for="pw-wiz-chat">{{ t('projektwerk', 'Adresse des Projektchats') }}</label>
					<NcTextField
						id="pw-wiz-chat"
						v-model="chatUrl"
						:label="t('projektwerk', 'Adresse des Projektchats')" />
				</div>
				<span class="pw-settings__hint">
					{{ t('projektwerk', 'Leer lassen blendet den Knopf „Zum Projektchat“ aus.') }}
				</span>
			</section>
		</div>

		<template #actions>
			<!--
				Abbrechen nur, solange nichts angelegt ist. Danach ist das
				Projekt da; „Rest später" speichert den aktuellen Schritt und
				springt hinein. Es gibt bewusst kein „Überspringen": Ein
				optionaler Schritt wird übersprungen, indem man ihn leer lässt
				und „Weiter" drückt — ein eigener Knopf, der getippte Firmen oder
				eine Chat-Adresse stillschweigend verwirft, war die Falle.
			-->
			<NcButton v-if="boardId === null" @click="onToggle(false)">
				{{ t('projektwerk', 'Abbrechen') }}
			</NcButton>
			<NcButton v-else :disabled="saving" @click="finishNow">
				{{ t('projektwerk', 'Rest später') }}
			</NcButton>

			<NcButton v-if="step > 0" :disabled="saving" @click="back">
				{{ t('projektwerk', 'Zurück') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving || !canAdvance" @click="next">
				{{ primaryLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script lang="ts">
import type { Candidate } from '@/services/settings'
import type { Column, Member, MemberRole } from '@/types/board'

import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { fetchBoard } from '@/services/boards'
import { addMember, searchCandidates, updateBoard } from '@/services/settings'
import { showError } from '@/services/toast'
import { useBoardStore } from '@/stores/boardStore'

const STEP_COUNT = 5

/**
 * Einrichtungsassistent beim Anlegen eines Projekts (#63).
 *
 * Das Projekt entsteht **nach dem ersten Schritt**; danach existiert es mit den
 * sechs Standardspalten, und die Folgeschritte konfigurieren es. So kann der
 * Mitglieder-Schritt über den board-gebundenen Endpunkt suchen, und „Rest
 * später" hinterlässt jederzeit ein brauchbares Projekt statt eines Entwurfs.
 *
 * Die App legt hier bewusst **keine** Freigaben und **keine** Gastkonten an
 * (#74) — der Mitglieder-Schritt führt nur dorthin, wo Nextcloud das tut.
 */
export default defineComponent({
	name: 'CreateBoardWizard',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		open: { type: Boolean, default: false },
	},

	emits: ['update:open', 'finished'],

	setup() {
		return { store: useBoardStore() }
	},

	data() {
		return {
			STEP_COUNT,
			step: 0,
			boardId: null as number | null,
			saving: false,
			title: '',
			description: '',
			orgInternal: '',
			orgExternal: '',
			memberSearch: '',
			searching: false,
			candidates: [] as Candidate[],
			searchTimer: null as ReturnType<typeof setTimeout> | null,
			searchToken: 0,
			newMember: '',
			/**
			     Anzeigename des ausgewählten Treffers — die addMember-Antwort
			    liefert `resolvedName` leer, also merken wir ihn hier.
			 */
			newMemberName: '',
			newMemberRole: 'external' as MemberRole,
			addedMembers: [] as Member[],
			columns: [] as Column[],
			chatUrl: '',
		}
	},

	computed: {
		stepTitle(): string {
			return [
				t('projektwerk', 'Projekt'),
				t('projektwerk', 'Die zwei Seiten'),
				t('projektwerk', 'Mitglieder'),
				t('projektwerk', 'Spalten'),
				t('projektwerk', 'Projektchat'),
			][this.step]
		},

		dialogName(): string {
			return t('projektwerk', 'Neues Projekt')
		},

		primaryLabel(): string {
			return this.step === STEP_COUNT - 1
				? t('projektwerk', 'Fertig')
				: t('projektwerk', 'Weiter')
		},

		/** Weiter geht nur mit Titel; alles andere darf leer bleiben. */
		canAdvance(): boolean {
			return this.step !== 0 || this.title.trim() !== ''
		},

		filesUrl(): string {
			return generateUrl('/apps/files/')
		},
	},

	watch: {
		open(isOpen: boolean) {
			if (isOpen) {
				this.reset()
			}
		},
	},

	/** Ein schwebender Such-Timer darf die Komponente nicht überleben. */
	beforeUnmount() {
		this.cancelSearch()
	},

	methods: {
		t,

		reset() {
			this.cancelSearch()
			this.step = 0
			this.boardId = null
			this.saving = false
			this.title = ''
			this.description = ''
			this.orgInternal = ''
			this.orgExternal = ''
			this.memberSearch = ''
			this.newMember = ''
			this.newMemberName = ''
			this.newMemberRole = 'external'
			this.addedMembers = []
			this.columns = []
			this.chatUrl = ''
		},

		/**
		 * Eine schwebende Personensuche stilllegen: den entprellten Timer
		 * löschen, den Token weiterdrehen (damit eine schon abgeschickte
		 * Antwort verworfen wird) und die Trefferliste leeren. Sonst fiele eine
		 * Suche aus einer geschlossenen Sitzung in die nächste — oder feuerte
		 * einen Fehler-Toast auf der Seite, in die man gerade gesprungen ist.
		 */
		cancelSearch() {
			if (this.searchTimer !== null) {
				clearTimeout(this.searchTimer)
				this.searchTimer = null
			}
			this.searchToken += 1
			this.searching = false
			this.candidates = []
		},

		/**
		 * `NcDialog` meldet Auf- und Zugehen über dasselbe Ereignis. Nur das
		 * Schließen wird nach oben gereicht.
		 *
		 * @param isOpen Der neue Zustand.
		 */
		onToggle(isOpen: boolean) {
			if (!isOpen) {
				this.cancelSearch()
				this.$emit('update:open', false)
			}
		},

		/**
		 * Ein leeres Feld wird zu `null`, nicht zu einem leeren String.
		 *
		 * @param value
		 */
		trimOrNull(value: string): string | null {
			return value.trim() === '' ? null : value.trim()
		},

		roleLabel(role: MemberRole): string {
			return role === 'internal'
				? t('projektwerk', 'Intern')
				: t('projektwerk', 'Kundenseite')
		},

		/**
		 * Der primäre Knopf: den aktuellen Schritt speichern und weiterrücken;
		 * im letzten Schritt speichern und ins Projekt springen.
		 */
		async next() {
			if (!this.canAdvance || this.saving) {
				return
			}
			this.saving = true
			try {
				await this.persistStep()
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Anlegen fehlgeschlagen'))
				this.saving = false

				return
			}
			this.saving = false

			if (this.step === STEP_COUNT - 1) {
				this.finish()

				return
			}
			this.step += 1
			await this.onEnterStep()
		},

		/**
		 * „Rest später": den aktuellen Schritt noch speichern, dann ins Projekt
		 * springen. Ohne das Speichern ginge verloren, was auf diesem Schritt
		 * schon eingetippt war (etwa die Chat-Adresse auf dem letzten Schritt).
		 */
		async finishNow() {
			if (this.saving) {
				return
			}
			this.saving = true
			try {
				await this.persistStep()
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Anlegen fehlgeschlagen'))
				this.saving = false

				return
			}
			this.saving = false
			this.finish()
		},

		back() {
			if (this.step > 0) {
				this.step -= 1
			}
		},

		/**
		 * Schreibt, was der aktuelle Schritt an geänderten Feldern hält.
		 *
		 * Schritt 1 legt das Projekt an (oder aktualisiert Titel/Beschreibung,
		 * falls man zurückgegangen ist). Die Mitglieder werden schon beim
		 * Hinzufügen geschrieben, die Spalten sind nur Anzeige — beide Schritte
		 * speichern hier nichts.
		 */
		async persistStep() {
			if (this.step === 0) {
				const data = {
					title: this.title.trim(),
					description: this.trimOrNull(this.description),
				}
				if (this.boardId === null) {
					const board = await this.store.createBoard(data)
					this.boardId = board.id
				} else {
					await updateBoard(this.boardId, data)
				}
			} else if (this.step === 1 && this.boardId !== null) {
				await updateBoard(this.boardId, {
					orgInternal: this.trimOrNull(this.orgInternal),
					orgExternal: this.trimOrNull(this.orgExternal),
				})
			} else if (this.step === 4 && this.boardId !== null) {
				await updateBoard(this.boardId, {
					chatUrl: this.trimOrNull(this.chatUrl),
				})
			}
		},

		/** Beim Betreten des Spalten-Schritts die angelegten Spalten holen. */
		async onEnterStep() {
			if (this.step === 3 && this.boardId !== null && this.columns.length === 0) {
				try {
					const detail = await fetchBoard(this.boardId)
					this.columns = detail.columns
				} catch {
					// Nur Anzeige: Schlägt das Laden fehl, bleibt der erklärende
					// Satz stehen, der Schritt ist trotzdem überspringbar.
					this.columns = []
				}
			}
		},

		/**
		 * Entprellte, board-gebundene Personensuche — nicht Nextclouds
		 * Personensuche, die in Gast-Sitzungen leer bliebe. Ein Zähler je
		 * Anfrage verwirft Antworten, die im Netz die Reihenfolge tauschen.
		 */
		searchPeople() {
			if (this.searchTimer !== null) {
				clearTimeout(this.searchTimer)
			}
			this.newMember = ''
			this.newMemberName = ''
			this.searchToken += 1

			const begriff = this.memberSearch.trim()
			if (begriff === '' || this.boardId === null) {
				this.candidates = []
				this.searching = false

				return
			}

			this.searching = true
			const token = this.searchToken
			const boardId = this.boardId
			this.searchTimer = setTimeout(async () => {
				try {
					const treffer = await searchCandidates(boardId, begriff)
					if (token === this.searchToken) {
						this.candidates = treffer
					}
				} catch (e) {
					if (token === this.searchToken) {
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

		/**
		 * Einen Treffer übernehmen — Kennung UND Anzeigename. Den Namen liefert
		 * die addMember-Antwort nicht (`resolvedName` bleibt leer), also nehmen
		 * wir ihn hier aus dem Treffer, den der Server schon aufgelöst hat.
		 *
		 * @param person Der ausgewählte Treffer.
		 */
		selectCandidate(person: Candidate) {
			this.newMember = person.userId
			this.newMemberName = person.displayName
		},

		async addMemberToBoard() {
			const userId = this.newMember
			if (userId === '' || this.boardId === null || this.saving) {
				return
			}
			const name = this.newMemberName
			this.saving = true
			try {
				const member = await addMember(this.boardId, { userId, role: this.newMemberRole })
				// `resolvedName` kommt leer zurück; der Name aus dem Treffer,
				// sonst die Kennung, damit die Zeile nie namenlos dasteht.
				this.addedMembers = [...this.addedMembers, {
					...member,
					userId: member.userId || userId,
					resolvedName: member.resolvedName || name || userId,
				}]
				this.newMember = ''
				this.newMemberName = ''
				this.memberSearch = ''
				this.candidates = []
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Mitglied konnte nicht hinzugefügt werden'))
			} finally {
				this.saving = false
			}
		},

		/** Schließen und ins frisch angelegte Projekt springen. */
		finish() {
			this.cancelSearch()
			const boardId = this.boardId
			this.$emit('update:open', false)
			if (boardId !== null) {
				this.$emit('finished', boardId)
			}
		},
	},
})
</script>

<style scoped>
.pw-wizard__progress {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 12px;
}

.pw-wizard__step {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.pw-wizard__memberadd {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.pw-wizard__added {
	list-style: none;
	padding: 0;
	margin: 4px 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.pw-wizard__added li {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	padding: 4px 8px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.pw-wizard__columns {
	margin: 4px 0;
	padding-left: 1.4em;
}

.pw-wizard__columns li {
	padding: 2px 0;
}

.pw-wizard__link {
	text-decoration: underline;
	white-space: nowrap;
}
</style>
