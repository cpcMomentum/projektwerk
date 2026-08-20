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

				<!--
					**GitHub-Anbindung** (#12) — nur für Softwareprojekte. Der
					Schalter blendet die Überführungs-Aktion an den Vorgängen
					dieses Boards ein; das Repo sagt, wohin überführte Issues
					gehen. Der persönliche Token liegt getrennt in „Meine
					Einstellungen", nicht am Board.
				-->
				<div class="pw-field">
					<label class="pw-settings__check">
						<input
							type="checkbox"
							:checked="board.githubEnabled"
							:disabled="busy"
							@change="board.githubEnabled = ($event.target as HTMLInputElement).checked">
						{{ t('projektwerk', 'GitHub-Anbindung für dieses Projekt') }}
					</label>
				</div>

				<div v-if="board.githubEnabled" class="pw-field">
					<label for="pw-set-ghrepo">{{ t('projektwerk', 'Ziel-Repository') }}</label>
					<!--
						Tippen sucht in den Repos, auf die der eigene Token
						Zugriff hat (#196) — die Treffer darunter zum Auswählen.
						Das Feld bleibt zugleich der Wert: Wer den Namen kennt oder
						keinen Token hinterlegt hat, tippt „owner/repo" von Hand.
					-->
					<!--
						`@focusin` (nicht `@focus`) am Wrapper: Der Fokus liegt am
						inneren Input von NcTextField; `focusin` bubbelt zuverlässig
						hoch, `focus` nicht. Beim Reinklicken erscheinen so gleich
						die ersten Repos — ohne dass man erst tippen muss (#196).
					-->
					<div @focusin="repoFokus">
						<NcTextField
							id="pw-set-ghrepo"
							v-model="board.githubRepo"
							:label="t('projektwerk', 'Ziel-Repository')"
							placeholder="owner/repo"
							@update:modelValue="repoSuchen" />
					</div>

					<div v-if="repoHits.length > 0" class="pw-settings__hits">
						<button
							v-for="repo in repoHits"
							:key="repo"
							type="button"
							class="pw-settings__hit"
							:aria-pressed="board.githubRepo === repo"
							@click="repoWaehlen(repo)">
							<span class="pw-person__name">{{ repo }}</span>
						</button>
					</div>
					<span v-if="repoSuchtLaeuft" class="pw-settings__hint">
						{{ t('projektwerk', 'Suche läuft…') }}
					</span>
					<span v-else-if="repoSuchFehler !== ''" class="pw-settings__hint">
						{{ repoSuchFehler }}
					</span>

					<span class="pw-settings__hint">
						{{ t('projektwerk', 'Im Format „owner/repo“, z. B. „cpcMomentum/projektwerk“. Tippen sucht in Ihren GitHub-Repositorys — dazu muss ein Token in „Meine Einstellungen“ hinterlegt sein. Leer lassen lässt die Überführung ausgeblendet, bis ein Ziel eingetragen ist.') }}
					</span>
				</div>

				<div class="pw-viscontrol__actions">
					<NcButton variant="primary" :disabled="busy || board.title.trim() === ''" @click="saveBoard">
						{{ t('projektwerk', 'Speichern') }}
					</NcButton>
				</div>
			</section>

			<!--
				Dateiablage.

				**Der Ablageort IST die Sichtbarkeit** (§5.18) — deshalb zwei
				Ordner und nicht einer mit Unterordnern. Was in „Austausch"
				liegt, sieht die Kundenseite; was im internen Ordner liegt,
				nicht. Dass Nextcloud diese Trennung durchsetzt und nicht die
				App, ist der Grund, warum sie trägt.

				Anders als die Felder darüber wird hier **sofort** geschrieben:
				Einen Ordner auszuwählen ist bereits die Bestätigung, ein
				„Speichern" danach wäre eine zweite für dieselbe Entscheidung.
			-->
			<section class="pw-settings__block">
				<h3 class="pw-col__head">
					{{ t('projektwerk', 'Dateiablage') }}
				</h3>

				<p class="pw-settings__hint">
					{{ t('projektwerk', 'Anhänge an Vorgängen landen in diesen Ordnern. Ohne Ordner sind an den betreffenden Vorgängen keine Anhänge möglich.') }}
				</p>

				<div v-for="slot in folderSlots" :key="slot.key" class="pw-settings__row">
					<div class="pw-field pw-field--grow">
						<label :for="`pw-set-${slot.key}`">{{ slot.label }}</label>
						<NcTextField
							:id="`pw-set-${slot.key}`"
							v-model="folderDrafts[slot.key]"
							:label="slot.label"
							:placeholder="slot.placeholder"
							:disabled="busy || !mayManage"
							@keydown.enter="saveFolder(slot.key)" />
						<span class="pw-settings__hint">{{ slot.hint }}</span>
					</div>

					<!--
						Der Wähler ist der eigentliche Weg (#139): auswählen oder
						anlegen, ohne einen Pfad abzutippen. Das Textfeld bleibt
						daneben stehen — es zeigt den gesetzten Ordner und dient
						als Rückfallweg.
					-->
					<NcButton
						:disabled="busy || !mayManage"
						@click="openPicker(slot.key)">
						<template #icon>
							<FolderIcon :size="20" />
						</template>
						{{ t('projektwerk', 'Ordner wählen') }}
					</NcButton>

					<NcButton
						:disabled="busy || !mayManage || folderDrafts[slot.key] === slot.path"
						@click="saveFolder(slot.key)">
						{{ t('projektwerk', 'Übernehmen') }}
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
					<!--
						Nur der Eigentuemer, nicht jeder mit Verwaltungsrecht:
						Der Vorgang fasst Daten aller Beteiligten an, auch die
						hier unsichtbaren. Die letzte Spalte bleibt stehen — es
						gaebe kein Ziel.
					-->
					<!--
						Der Name gehoert in die Beschriftung: Ohne ihn stehen
						sechs Knoepfe „Spalte entfernen" untereinander, und wer
						die Seite hoert statt sieht, kann sie nicht
						auseinanderhalten — bei einem Knopf, der eine Spalte
						abraeumt, ist das keine Feinheit.
					-->
					<NcButton
						v-if="mayRemoveColumns"
						:disabled="busy || store.columns.length < 2"
						:aria-label="removeLabelFor(column)"
						@click="askRemoveColumn(column)">
						<template #icon>
							<DeleteIcon :size="20" />
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
						:disableMenu="true"
						:hideStatus="true" />

					<span class="pw-person__body">
						<span class="pw-person__name">{{ member.resolvedName }}</span>
						<!--
							Die Kennung als Zweitzeile hilft, gleichnamige Personen
							zu unterscheiden — aber nur, wenn sie lesbar ist. Ein
							Gast-Konto trägt als Kennung einen 64-stelligen Hash;
							der sagt niemandem etwas und wird deshalb weggelassen.
							Der Anzeigename oben steht ohnehin (resolvedName).
						-->
						<span v-if="handleFor(member) !== ''" class="pw-person__org">{{ handleFor(member) }}</span>
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

					<!--
						Entfernen (§5.29): Der Eigentuemer laesst sich nicht
						entfernen (er behaelt das Verwaltungsrecht). Die
						Rueckfrage nennt die Zahl der privaten Vorgaenge, die
						dabei geloescht werden.
					-->
					<NcButton
						variant="tertiary"
						:disabled="busy || isOwner(member)"
						:aria-label="t('projektwerk', 'Mitglied entfernen')"
						@click="askRemoveMember(member)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
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
				<!--
					Die Suche findet nur bestehende Konten. Ohne diesen Satz
					sucht jemand eine Kundin, die es in Nextcloud noch gar nicht
					gibt, bekommt „Keine passenden Konten gefunden." und raet,
					woran es liegt. Die App legt bewusst keine Konten an — sie
					haengt an keiner anderen App —, aber sie kann sagen, wo sie
					herkommen (#74).
				-->
				<span class="pw-settings__hint">
					{{ t('projektwerk', 'Gefunden werden nur bestehende Nextcloud-Konten. Wer noch keines hat — etwa auf Kundenseite — braucht zuerst eines: Die Administration legt es an, als Gastzugang oder als Vollkonto.') }}
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

		<!--
			Die Rueckfrage zum Entfernen einer Spalte. Ein Dialog und kein
			zweistufiger Bereich wie bei der Sichtbarkeit: Diese Seite ist kein
			Modal, hier legen sich also keine zwei Fokusfallen uebereinander.
		-->
		<NcDialog
			:open="removing !== null"
			:name="t('projektwerk', 'Spalte entfernen')"
			size="normal"
			@update:open="onRemoveDialogToggle">
			<!--
				Die App-Klasse MUSS hier drin stehen: NcDialog teleportiert
				seinen Inhalt an den `body`, wo `.app-projektwerk` kein Vorfahr
				mehr ist.
			-->
			<div v-if="removing !== null" class="app-projektwerk">
				<div class="pw-field">
					<label for="pw-del-target">{{ t('projektwerk', 'Wohin wandern die Vorgänge?') }}</label>
					<!--
						Ohne Vorbelegung: Wohin die Arbeit anderer wandert, ist
						eine Entscheidung. Eine geratene Antwort verschoebe sie
						an einen Ort, den niemand gewaehlt hat.
					-->
					<select id="pw-del-target" v-model="removeTarget">
						<option :value="null" disabled>
							{{ t('projektwerk', 'Zielspalte wählen') }}
						</option>
						<option v-for="column in removeTargets" :key="column.id" :value="column.id">
							{{ column.title }}
						</option>
					</select>
				</div>

				<p class="pw-settings__hint">
					{{ removeLead }}
				</p>
				<!--
					Die Zahl stammt aus der eigenen Ticketliste dieses Betrachters
					und weiss damit nie mehr als er. Der Satz danach sagt, dass
					sie nicht alles ist — sonst laese sie sich als Vollstaendigkeit.
				-->
				<p class="pw-settings__hint">
					{{ removeVisibleCount }}
				</p>
				<p class="pw-settings__hint">
					{{ t('projektwerk', 'Verborgene Vorgänge wandern mit, ohne hier aufzutauchen. Es geht nichts verloren.') }}
				</p>
			</div>

			<template #actions>
				<NcButton @click="cancelRemove">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy || removeTarget === null" @click="confirmRemove">
					{{ t('projektwerk', 'Verschieben und entfernen') }}
				</NcButton>
			</template>
		</NcDialog>

		<!--
			Mitglied entfernen (§5.29) — mit bezifferter Rückfrage. Die Zahl der
			privaten Vorgänge kommt vom Server (`removal-impact`); sie ist hier
			kein Leck, weil sie nur die Vorgänge **dieser** Person zählt, die
			ohnehin niemand sonst sieht.
		-->
		<NcDialog
			:open="removingMember !== null"
			:name="t('projektwerk', 'Mitglied entfernen')"
			size="normal"
			@update:open="onRemoveMemberToggle">
			<div v-if="removingMember !== null" class="app-projektwerk">
				<p class="pw-settings__hint">
					{{ removeMemberLead }}
				</p>
				<p v-if="removeMemberImpact === null" class="pw-settings__hint">
					{{ t('projektwerk', 'Wird geprüft…') }}
				</p>
				<p v-else-if="removeMemberImpact > 0" class="pw-settings__hint pw-settings__hint--warn">
					{{ removeMemberImpactText }}
				</p>
				<p v-else class="pw-settings__hint">
					{{ t('projektwerk', 'Keine privaten Vorgänge dieser Person werden gelöscht. Interne und öffentliche Vorgänge bleiben dem Projekt erhalten.') }}
				</p>
			</div>

			<template #actions>
				<NcButton @click="cancelRemoveMember">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy || removeMemberImpact === null" @click="confirmRemoveMember">
					{{ t('projektwerk', 'Entfernen') }}
				</NcButton>
			</template>
		</NcDialog>

		<FolderPicker
			:open="picker.open"
			:startPath="picker.start"
			@update:open="picker.open = $event"
			@select="onPicked" />
	</div>
</template>

<script lang="ts">
import type { Candidate } from '@/services/settings'
import type { Column, Member, MemberRole } from '@/types/board'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import DeleteIcon from 'vue-material-design-icons/DeleteOutline.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import FolderPicker from '@/components/FolderPicker.vue'
import { searchGithubRepos } from '@/services/github'
import {
	addMember,
	createColumn,
	deleteColumn,
	memberRemovalImpact,
	removeMember,
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

	components: { ArrowDownIcon, ArrowUpIcon, DeleteIcon, FolderIcon, FolderPicker, LockIcon, NcAvatar, NcButton, NcDialog, NcEmptyContent, NcTextField },

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
			// Repo-Live-Suche (#196) — eigener Such-State, damit die
			// Mitglieder-Suche darüber unberührt bleibt.
			repoHits: [] as string[],
			repoSuchtLaeuft: false,
			/** Servermeldung, wenn die Suche scheitert (z. B. kein Token). */
			repoSuchFehler: '',
			repoSuchTimer: null as ReturnType<typeof setTimeout> | null,
			repoSuchToken: 0,
			/** Die Spalte, über deren Entfernen gerade zurückgefragt wird. */
			removing: null as Column | null,
			/** Pflichtangabe, deshalb ohne Vorbelegung. */
			removeTarget: null as number | null,
			/** Das Mitglied, über dessen Entfernen gerade zurückgefragt wird (§5.29). */
			removingMember: null as Member | null,
			/** Zahl der privaten Vorgänge, die das Entfernen löscht; null = wird noch geprüft. */
			removeMemberImpact: null as number | null,
			// Ein eigener Entwurf statt direkter Bindung an den Speicher: Sonst
			// stuenden Tippfehler sofort in der Kopfzeile des Boards, und ein
			// Abbruch waere nicht mehr moeglich.
			board: { title: '', description: '', orgInternal: '', orgExternal: '', chatUrl: '', githubEnabled: false, githubRepo: '' },
			// Eigene Entwuerfe wie beim Board oben, aus demselben Grund: Der
			// Pfad muss erst geprueft werden, und bis dahin darf er nirgends
			// als der gespeicherte gelten.
			folderDrafts: { public: '', internal: '' } as Record<'public' | 'internal', string>,
			// Der Ordner-Wähler (#139) und für welchen der beiden Slots er offen ist.
			picker: { open: false, slot: 'public' as 'public' | 'internal', start: '' },
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

		/**
		 * Die beiden Ablageorte als Zeilen, damit die Vorlage sie nicht doppelt
		 * ausschreibt.
		 *
		 * Die Reihenfolge ist nicht beliebig: „Austausch" steht oben, weil er
		 * der Ordner ist, den **beide** Seiten sehen — und weil ein Projekt ohne
		 * internen Ordner arbeitsfähig ist, eines ohne Austauschordner aber
		 * seinen Zweck verfehlt.
		 */
		folderSlots(): { key: 'public' | 'internal', label: string, hint: string, placeholder: string, path: string }[] {
			const board = this.store.board

			return [
				{
					key: 'public',
					label: t('projektwerk', 'Ordner für Vorgänge, die alle Beteiligten sehen'),
					hint: t('projektwerk', 'Die Kundenseite hat Zugriff. Leer lassen heißt: an diesen Vorgängen sind keine Anhänge möglich.'),
					placeholder: 'Projekte/Kunde A/90_Austausch',
					path: board?.folderPublicPath ?? '',
				},
				{
					key: 'internal',
					label: t('projektwerk', 'Ordner für interne Vorgänge'),
					hint: t('projektwerk', 'Nur die eigene Seite hat Zugriff. Leer lassen heißt: an internen Vorgängen sind keine Anhänge möglich.'),
					placeholder: 'Projekte/Kunde A/91_Tickets_intern',
					path: board?.folderInternalPath ?? '',
				},
			]
		},

		/**
		 * Spalten entfernen darf **nur der Eigentümer** — nicht jeder mit
		 * Verwaltungsrecht (#60).
		 *
		 * Der Server weist jeden anderen mit 403 ab; hier fällt nur der Knopf
		 * weg, damit niemand eine Zielspalte wählt, die er nicht abschicken
		 * darf.
		 */
		mayRemoveColumns(): boolean {
			const owner = this.store.board?.ownerUserId
			return owner !== undefined && owner === this.store.viewer?.userId
		},

		/** Jede Spalte außer der, die wegfällt. */
		removeTargets(): Column[] {
			return this.store.columns.filter((column) => column.id !== this.removing?.id)
		},

		/**
		 * Was passiert, in einem Satz — als Verschiebung, nicht als Verlust.
		 *
		 * Steht im Skript und nicht in der Vorlage, weil der Text
		 * Anführungszeichen trägt; im Attribut beendeten sie die Zeichenkette.
		 */
		removeLead(): string {
			const target = this.store.columns.find((column) => column.id === this.removeTarget)

			if (this.removing === null || target === undefined) {
				return t('projektwerk', 'Alle Vorgänge wandern in die gewählte Spalte, danach fällt „{from}“ weg.', {
					from: this.removing?.title ?? '',
				})
			}

			return t('projektwerk', 'Alle Vorgänge wandern nach „{to}“, danach fällt „{from}“ weg.', {
				from: this.removing.title,
				to: target.title,
			})
		},

		/**
		 * Wie viele Vorgänge **dieser Betrachter** in der Spalte sieht.
		 *
		 * Aus der bereits geladenen Ticketliste, nicht aus einer eigenen
		 * Abfrage: Ein Zähler-Endpunkt wäre ein zweiter Ort, an dem die
		 * Sichtbarkeitsregel stimmen müsste, und die Zahl darf ohnehin nie mehr
		 * wissen als der Betrachter. Der Filter „Nur wartend" bleibt hier außen
		 * vor — deshalb `columnOrder` und nicht `ticketsIn()`.
		 */
		removeVisibleCount(): string {
			const anzahl = this.removing === null
				? 0
				: (this.store.columnOrder.get(this.removing.id)?.length ?? 0)

			return n(
				'projektwerk',
				'Für dich sichtbar ist davon %n Vorgang.',
				'Für dich sichtbar sind davon %n Vorgänge.',
				anzahl,
			)
		},

		/** Der einleitende Satz der Mitglied-Entfernen-Rückfrage. */
		removeMemberLead(): string {
			const name = this.removingMember?.resolvedName ?? ''
			return t('projektwerk', '{name} aus dem Projekt entfernen? Offene Zuweisungen dieser Person werden aufgehoben.', { name })
		},

		/** Die bezifferte Warnung über die zu löschenden privaten Vorgänge. */
		removeMemberImpactText(): string {
			return n(
				'projektwerk',
				'Dabei wird %n privater Vorgang dieser Person unwiederbringlich gelöscht.',
				'Dabei werden %n private Vorgänge dieser Person unwiederbringlich gelöscht.',
				this.removeMemberImpact ?? 0,
			)
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
				githubEnabled: board.githubEnabled,
				githubRepo: board.githubRepo ?? '',
			}
			this.folderDrafts = {
				public: board.folderPublicPath ?? '',
				internal: board.folderInternalPath ?? '',
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
		 * Die Kennung als lesbare Zweitzeile — oder leer.
		 *
		 * Ein Gast-Konto trägt als Kennung einen 64-stelligen Hex-Hash (Gast-UIDs
		 * sind genau so gebaut). Der gehört nicht in die Oberfläche: Er sagt
		 * niemandem etwas und stünde als Zeichensalat unter dem Namen. In dem
		 * Fall bleibt die Zeile leer, der aufgelöste Name oben trägt sie.
		 *
		 * @param member Die Mitgliedschaft.
		 */
		handleFor(member: Member): string {
			const uid = member.userId
			return /^[a-f0-9]{64}$/i.test(uid) ? '' : uid
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
					githubEnabled: this.board.githubEnabled,
					// **Roher String, nicht blankToNull:** Ein leeres Repo soll
					// das Ziel entfernen (der Hinweis darunter verspricht genau
					// das). onlyGiven wirft nur `null` heraus; der leere String
					// kommt durch und wird serverseitig zu „kein Ziel" — dieselbe
					// Mechanik wie bei den Ordnerpfaden.
					githubRepo: this.board.githubRepo.trim(),
				}),
				t('projektwerk', 'Speichern fehlgeschlagen'),
			)
		},

		/**
		 * Einen Projektordner über seinen Pfad festlegen — oder leeren.
		 *
		 * **Der Pfad geht hin, die ID bleibt hier.** Der Server löst auf und
		 * speichert die Datei-ID; zurück kommt der kanonische Pfad, und
		 * `fillDraft` schreibt ihn ins Feld. Wer sich vertippt hat, sieht das
		 * daran, dass die Meldung des Servers kommt und das Feld unverändert
		 * bleibt.
		 *
		 * Ein leeres Feld entfernt die Zuordnung. Der Ordner selbst bleibt
		 * unangetastet — die App löscht nicht (§5.18); danach sind an den
		 * betroffenen Vorgängen nur keine Anhänge mehr möglich.
		 *
		 * @param slot Welcher der beiden Ordner.
		 */
		saveFolder(slot: 'public' | 'internal'): Promise<void> {
			const path = this.folderDrafts[slot].trim()

			return this.write(
				() => updateBoard(this.boardId, slot === 'internal'
					? { folderInternalPath: path }
					: { folderPublicPath: path }),
				t('projektwerk', 'Ordner konnte nicht gespeichert werden'),
			)
		},

		/**
		 * Den Ordner-Wähler für einen Slot öffnen — beim bereits gesetzten
		 * Ordner beginnend, damit man nicht jedes Mal von der Wurzel klickt.
		 *
		 * @param slot Welcher der beiden Ordner.
		 */
		openPicker(slot: 'public' | 'internal'): void {
			this.picker = { open: true, slot, start: this.folderDrafts[slot].trim() }
		},

		/**
		 * Die Wahl aus dem Picker übernehmen und gleich speichern — die Auswahl
		 * eines Ordners ist bereits die Bestätigung.
		 *
		 * @param path Der gewählte Pfad relativ zur Files-Wurzel.
		 */
		onPicked(path: string): void {
			this.folderDrafts[this.picker.slot] = path
			this.saveFolder(this.picker.slot)
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
		 * Die Beschriftung des Löschknopfs — mit Namen, nicht ohne.
		 *
		 * @param column Die Spalte.
		 */
		removeLabelFor(column: Column): string {
			return t('projektwerk', 'Spalte „{title}“ entfernen', { title: column.title })
		},

		/**
		 * Die Rückfrage öffnen.
		 *
		 * @param column Die Spalte, die wegfallen soll.
		 */
		askRemoveColumn(column: Column) {
			this.removing = column
			this.removeTarget = null
		},

		cancelRemove() {
			this.removing = null
			this.removeTarget = null
		},

		/**
		 * Nur das Schließen zurücknehmen, nicht jede Meldung.
		 *
		 * `NcDialog` meldet beide Richtungen über dasselbe Ereignis. Würde hier
		 * blind geschlossen, verschwände der Dialog im selben Zug, in dem er
		 * aufgeht.
		 *
		 * @param open Der neue Zustand.
		 */
		onRemoveDialogToggle(open: boolean) {
			if (!open) {
				this.cancelRemove()
			}
		},

		/**
		 * Entfernen heißt verschieben: erst wandern die Vorgänge, dann fällt
		 * die Spalte weg — beides in einer Transaktion auf dem Server.
		 */
		confirmRemove() {
			const column = this.removing
			const target = this.removeTarget
			if (column === null || target === null) {
				return
			}

			return this.write(
				async () => {
					await deleteColumn(this.boardId, column.id, target)
					this.cancelRemove()
				},
				t('projektwerk', 'Spalte konnte nicht entfernt werden'),
			)
		},

		/**
		 * Die Rückfrage zum Entfernen eines Mitglieds öffnen und die Zahl der
		 * betroffenen privaten Vorgänge vom Server holen (§5.29).
		 *
		 * @param member Das Mitglied.
		 */
		async askRemoveMember(member: Member) {
			this.removingMember = member
			this.removeMemberImpact = null
			try {
				const impact = await memberRemovalImpact(this.boardId, member.userId)
				// Nur übernehmen, wenn der Dialog noch demselben Mitglied gilt —
				// ein schneller Wechsel darf keine fremde Zahl einblenden.
				if (this.removingMember?.userId === member.userId) {
					this.removeMemberImpact = impact.privateTickets
				}
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Die Vorschau konnte nicht geladen werden'))
				this.cancelRemoveMember()
			}
		},

		cancelRemoveMember() {
			this.removingMember = null
			this.removeMemberImpact = null
		},

		/**
		 * @param open Der neue Zustand des Dialogs.
		 */
		onRemoveMemberToggle(open: boolean) {
			if (!open) {
				this.cancelRemoveMember()
			}
		},

		/**
		 * Das Mitglied entfernen — private Vorgänge löschen, Zuweisungen lösen,
		 * Mitgliedschaft entfernen, alles serverseitig in einer Transaktion.
		 */
		confirmRemoveMember() {
			const member = this.removingMember
			if (member === null) {
				return
			}

			return this.write(
				async () => {
					await removeMember(this.boardId, member.userId)
					this.cancelRemoveMember()
				},
				t('projektwerk', 'Mitglied konnte nicht entfernt werden'),
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

		/**
		 * Repos suchen, während getippt wird (#196) — gedrosselt und rennfest,
		 * wie die Personensuche darüber. Ein **leerer** Feldinhalt liefert die
		 * ersten Repos (Dropdown-Gefühl), statt die Liste zu leeren.
		 */
		repoSuchen() {
			this.repoFetch(this.board.githubRepo.trim(), false)
		},

		/**
		 * Beim Reinklicken/Fokussieren gleich die ersten Repos zeigen (#196) —
		 * nur wenn noch keine Treffer stehen, damit ein zweiter Klick nichts
		 * neu lädt. Sofort, ohne die Tipp-Drosselung.
		 */
		repoFokus() {
			// Leerer Begriff = die ersten Repos zum Browsen; Tippen filtert
			// danach. Sonst würde ein bereits eingetragener voller Name sofort
			// „keine Treffer" zeigen.
			if (this.repoHits.length === 0 && !this.repoSuchtLaeuft) {
				this.repoFetch('', true)
			}
		},

		/**
		 * Die eigentliche Suche. Scheitert sie (kein Token, GitHub-Fehler),
		 * bleibt das Feld als Freitext bedienbar und die Meldung sagt, woran es
		 * liegt.
		 *
		 * @param begriff Suchbegriff; leer liefert die ersten Repos.
		 * @param sofort Ohne Drosselung laden (beim Fokus), sonst mit 300 ms.
		 */
		repoFetch(begriff: string, sofort: boolean) {
			if (this.repoSuchTimer !== null) {
				clearTimeout(this.repoSuchTimer)
			}
			this.repoSuchFehler = ''
			this.repoSuchToken += 1
			this.repoSuchtLaeuft = true
			const token = this.repoSuchToken

			const laden = async () => {
				try {
					const { repos } = await searchGithubRepos(begriff)
					if (token === this.repoSuchToken) {
						// Den exakt schon eingetragenen Namen nicht noch einmal als
						// Vorschlag anbieten.
						this.repoHits = repos.filter((r) => r !== this.board.githubRepo)
					}
				} catch (e) {
					if (token === this.repoSuchToken) {
						this.repoHits = []
						this.repoSuchFehler = (e as { message?: string }).message
							?? t('projektwerk', 'Repositorys konnten nicht geladen werden')
					}
				} finally {
					if (token === this.repoSuchToken) {
						this.repoSuchtLaeuft = false
					}
				}
			}

			if (sofort) {
				laden()
			} else {
				this.repoSuchTimer = setTimeout(laden, 300)
			}
		},

		/**
		 * @param repo Der gewählte Repo-Name „owner/repo".
		 */
		repoWaehlen(repo: string) {
			this.board.githubRepo = repo
			this.repoHits = []
			this.repoSuchFehler = ''
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
