<template>
	<div class="pw-view pw-settingspage">
		<h2 class="pw-settingspage__title">
			{{ t('projektwerk', 'Meine Einstellungen') }}
		</h2>

		<div class="pw-settingspage__body">
			<!--
				Linke Bereichs-Navigation über das gemeinsame Bauteil (#195),
				dasselbe wie in den Projekteinstellungen — Benachrichtigungen,
				Dateien und GitHub gehören inhaltlich nicht zusammen und stehen
				deshalb getrennt.
			-->
			<PwSettingsNav
				:sections="sections"
				:modelValue="activeSection"
				@update:modelValue="activeSection = $event" />

			<div class="pw-settingspage__content">
				<!--
					**Dateien** (#195): der eigene Ordner für private Anhänge
					(#184). Dateien an „Nur ich"-Vorgängen liegen im eigenen
					Files-Bereich, nicht im geteilten Projektordner. Ohne Wahl gilt
					die Vorgabe; hier lässt sie sich ändern.
				-->
				<div v-show="activeSection === 'dateien'">
					<h3 class="pw-settingspage__head">
						{{ t('projektwerk', 'Dateien') }}
					</h3>
					<section class="pw-settingspage__block">
						<h4 class="pw-settingspage__sub">
							{{ t('projektwerk', 'Ordner für private Anhänge') }}
						</h4>
						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Dateien an „Nur ich"-Vorgängen liegen in diesem Ordner Ihrer eigenen Dateien. Ohne Wahl wird „ProjektWerk" verwendet und beim ersten Anhang angelegt.') }}
						</p>

						<div class="pw-settings__row">
							<NcTextField
								v-model="ordnerEntwurf"
								:label="t('projektwerk', 'Ordnerpfad')"
								:disabled="busy"
								@keydown.enter="ordnerSpeichern" />
							<NcButton :disabled="busy" @click="pickerOeffnen">
								<template #icon>
									<FolderIcon :size="20" />
								</template>
								{{ t('projektwerk', 'Ordner wählen') }}
							</NcButton>
							<NcButton :disabled="busy || ordnerEntwurf === ordnerAktuell" @click="ordnerSpeichern">
								{{ t('projektwerk', 'Übernehmen') }}
							</NcButton>
						</div>
					</section>
				</div>

				<!--
					**GitHub** (#195): der eigene GitHub-Token (#12). Wer Vorgänge
					nach GitHub überführt, hinterlegt hier seinen persönlichen
					Token — je Person, verschlüsselt vom Server abgelegt. Angezeigt
					wird nie der Token selbst, nur ob einer hinterlegt ist.
				-->
				<div v-show="activeSection === 'github'">
					<h3 class="pw-settingspage__head">
						GitHub
					</h3>
					<section class="pw-settingspage__block">
						<h4 class="pw-settingspage__sub">
							{{ t('projektwerk', 'GitHub-Token') }}
						</h4>
						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Zum Überführen von Vorgängen nach GitHub. Empfohlen: ein fein abgestufter Token (fine-grained PAT) mit dem Recht „Issues: read and write“ auf die Ziel-Repositorys. Der Token wird verschlüsselt gespeichert und nie wieder angezeigt.') }}
						</p>

						<p class="pw-settings__status">
							<CheckIcon v-if="githubTokenPresent" :size="18" class="pw-settings__status-icon pw-settings__status-icon--ok" />
							<AlertIcon v-else :size="18" class="pw-settings__status-icon" />
							{{ githubTokenPresent
								? t('projektwerk', 'Ein Token ist hinterlegt.')
								: t('projektwerk', 'Kein Token hinterlegt.') }}
						</p>

						<div class="pw-settings__row">
							<NcTextField
								v-model="githubTokenEntwurf"
								type="password"
								:label="t('projektwerk', 'GitHub-Token')"
								:placeholder="githubTokenPresent ? t('projektwerk', 'Neuen Token eingeben, um den vorhandenen zu ersetzen') : ''"
								:disabled="busy"
								autocomplete="off"
								@keydown.enter="githubTokenSpeichern" />
							<NcButton :disabled="busy || githubTokenEntwurf.trim() === ''" @click="githubTokenSpeichern">
								{{ t('projektwerk', 'Speichern') }}
							</NcButton>
							<NcButton :disabled="busy || !githubTokenPresent" @click="githubTokenEntfernen">
								{{ t('projektwerk', 'Entfernen') }}
							</NcButton>
						</div>
					</section>
				</div>

				<!--
					**Benachrichtigungen** (#195) — „Wie" und „Wovon", zwei Fragen,
					zwei Formen.

					„Wie" ist eine Handvoll Schalter und gilt überall gleich —
					eine Zeile reicht. „Wovon" ist je Projekt verschieden und
					gehört deshalb in die Tabelle darunter. Beides in eine
					Tabelle zu zwingen hiesse, dem Kanal eine Spalte je Projekt
					zu geben, die niemand je anders setzt.
				-->
				<div v-show="activeSection === 'benachrichtigungen'">
					<h3 class="pw-settingspage__head">
						{{ t('projektwerk', 'Benachrichtigungen') }}
					</h3>
					<section class="pw-settingspage__block">
						<h4 class="pw-settingspage__sub">
							{{ t('projektwerk', 'Wie möchten Sie benachrichtigt werden?') }}
						</h4>
						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Gilt für alle Projekte.') }}
						</p>

						<div class="pw-notify__global">
							<label v-for="kanal in kanaele" :key="kanal.key" class="pw-settings__check">
								<input
									type="checkbox"
									:checked="globalStand(kanal.key)"
									:disabled="busy"
									@change="setzen(kanal.key, ($event.target as HTMLInputElement).checked, 0)">
								{{ kanal.label }}
							</label>
						</div>
					</section>

					<section class="pw-settingspage__block">
						<h4 class="pw-settingspage__sub">
							{{ t('projektwerk', 'Wovon möchten Sie benachrichtigt werden?') }}
						</h4>
						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Die erste Zeile gilt für alle Projekte — auch für die, die später dazukommen. Darunter weichen einzelne Projekte davon ab.') }}
						</p>

						<div class="pw-table-scroll">
							<table class="pw-table pw-table--notify">
								<thead>
									<tr>
										<th scope="col">
											{{ t('projektwerk', 'Projekt') }}
										</th>
										<th
											v-for="anlass in anlaesse"
											:key="anlass.key"
											scope="col"
											class="pw-table__mid">
											{{ anlass.label }}
										</th>
									</tr>
								</thead>
								<tbody>
									<!--
									**Die erste Zeile ist die allgemeine Wahl** — in
									derselben Tabelle statt in einem zweiten Block.
									Ohne sie kostete „den Rundruf will ich generell
									nicht" einen Klick je Projekt.
								-->
									<tr class="pw-table__row--global">
										<th scope="row" class="pw-table__name">
											{{ t('projektwerk', 'Alle Projekte') }}
										</th>
										<td v-for="anlass in anlaesse" :key="anlass.key" class="pw-table__mid">
											<input
												type="checkbox"
												:checked="globalStand(anlass.key)"
												:disabled="busy"
												:aria-label="beschriftung(anlass, null)"
												@change="setzen(anlass.key, ($event.target as HTMLInputElement).checked, 0)">
										</td>
									</tr>

									<tr v-for="board in boards" :key="board.id">
										<th scope="row" class="pw-table__name">
											{{ board.title }}
										</th>
										<!--
										Die Markierung sitzt am Kästchen, nicht an der
										Zeile: Die fünf Anlässe sind unabhängig, eine
										Angabe am Zeilenende wäre für vier von ihnen
										falsch.
									-->
										<td v-for="anlass in anlaesse" :key="anlass.key" class="pw-table__mid">
											<span class="pw-pin" :class="{ 'pw-pin--set': istGesetzt(anlass.key, board.id) }">
												<input
													type="checkbox"
													:checked="stand(anlass.key, board.id)"
													:disabled="busy"
													:aria-label="beschriftung(anlass, board)"
													@change="setzen(anlass.key, ($event.target as HTMLInputElement).checked, board.id)">
											</span>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Ein Punkt am Kästchen heißt: für dieses Projekt festgelegt. Ohne Punkt gilt die erste Zeile.') }}
						</p>

						<div class="pw-viscontrol__actions">
							<NcButton :disabled="busy || !hatAusnahmen" @click="alleZuruecksetzen">
								{{ t('projektwerk', 'Alle Abweichungen aufheben') }}
							</NcButton>
						</div>
					</section>
				</div>
			</div>
		</div>

		<FolderPicker
			:open="picker.open"
			:startPath="picker.start"
			@update:open="picker.open = $event"
			@select="onOrdnerGewaehlt" />
	</div>
</template>

<script lang="ts">
import type { Channel, NotifyEvent, NotifyPrefs, PrefKey } from '@/services/notifyPrefs'
import type { Board } from '@/types/board'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckIcon from 'vue-material-design-icons/CheckCircleOutline.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderPicker from '@/components/FolderPicker.vue'
import PwSettingsNav from '@/components/PwSettingsNav.vue'
import { fetchBoards } from '@/services/boards'
import { clearGithubToken, fetchGithubTokenStatus, setGithubToken } from '@/services/github'
import { clearNotifyOverrides, fetchNotifyPrefs, setNotifyPref } from '@/services/notifyPrefs'
import { fetchPrivateFolder, setPrivateFolder } from '@/services/privateFolder'
import { showError } from '@/services/toast'

/**
 * Meine Einstellungen — eine Seite mit zweiter Menüebene, wie im
 * WorkTime-Verwaltungsbereich.
 *
 * **Zwei Fragen, getrennt gestellt** (Entscheidung mit Axel, 2026-08-11):
 *
 * - **Wie** benachrichtigt wird — E-Mail, Glocke. Nur global; das beantwortet
 *   niemand je Projekt anders.
 * - **Wovon** — die fünf Anlässe aus §21 und #98. Je Projekt, denn genau hier sitzt das
 *   Rauschen: „Neuer Vorgang im Projekt" ist ein Rundruf und kommt bei zwanzig
 *   Projekten zwanzigmal.
 *
 * Die allgemeine Wahl für das „wovon" steht als **erste Zeile derselben
 * Tabelle** und nicht in einem zweiten Block. Ohne sie kostete „den Rundruf
 * will ich generell nicht" einen Klick je Projekt — und das war der Anlass für
 * die ganze Aufschlüsselung.
 */
export default defineComponent({
	name: 'MySettingsView',

	components: { AlertIcon, CheckIcon, FolderIcon, FolderPicker, NcButton, NcTextField, PwSettingsNav },

	data() {
		return {
			busy: false,
			/** Der aktive Bereich der linken Navigation (#195). */
			activeSection: 'benachrichtigungen',
			prefs: { global: {}, boards: {} } as NotifyPrefs,
			boards: [] as Board[],
			/** Der gespeicherte Ordner für private Anhänge — der Vergleichswert. */
			ordnerAktuell: '',
			/** Der bearbeitete Pfad, bis er übernommen wird. */
			ordnerEntwurf: '',
			picker: { open: false, start: '' },
			/** Ob ein GitHub-Token hinterlegt ist (#12) — nie der Token selbst. */
			githubTokenPresent: false,
			/** Der eingegebene Token, bis er gespeichert wird. Nach dem Speichern geleert. */
			githubTokenEntwurf: '',
		}
	},

	computed: {
		/** Die Bereiche der linken Navigation (#195). */
		sections(): { key: string, label: string }[] {
			return [
				{ key: 'benachrichtigungen', label: t('projektwerk', 'Benachrichtigungen') },
				{ key: 'dateien', label: t('projektwerk', 'Dateien') },
				{ key: 'github', label: 'GitHub' },
			]
		},

		kanaele(): { key: Channel, label: string }[] {
			return [
				{ key: 'mail', label: t('projektwerk', 'E-Mail') },
				{ key: 'bell', label: t('projektwerk', 'Glocke in Nextcloud') },
			]
		},

		/**
		 * Die fünf Anlässe aus §21 und #98 — die Spalten der Tabelle.
		 *
		 * **Kurze Spaltentitel, lange Vorlesebeschriftung.** „Arbeitsschritt mir
		 * zugewiesen" als Spaltenkopf machte die Tabelle breiter als der Platz,
		 * und sie fing an, waagerecht zu scrollen — der Projektname stand dann
		 * ausserhalb des Bildes, und die Zeile war nicht mehr zuzuordnen. Die
		 * ganze Bedeutung steht in `beschriftung()` und in der Überschrift
		 * darüber („Wovon möchten Sie benachrichtigt werden?").
		 */
		anlaesse(): { key: NotifyEvent, label: string, lang: string }[] {
			return [
				{
					key: 'ticket_assigned',
					label: t('projektwerk', 'Vorgang'),
					lang: t('projektwerk', 'Ein Vorgang wird mir zugewiesen'),
				},
				{
					key: 'step_assigned',
					label: t('projektwerk', 'Arbeitsschritt'),
					lang: t('projektwerk', 'Ein Arbeitsschritt wird mir zugewiesen'),
				},
				{
					key: 'ticket_created',
					label: t('projektwerk', 'Neuer Vorgang'),
					lang: t('projektwerk', 'Ein neuer Vorgang entsteht im Projekt'),
				},
				{
					key: 'comment_added',
					label: t('projektwerk', 'Kommentar'),
					lang: t('projektwerk', 'Jemand kommentiert einen Vorgang, an dem ich beteiligt bin'),
				},
				{
					key: 'ticket_closed',
					label: t('projektwerk', 'Abschluss'),
					lang: t('projektwerk', 'Ein Vorgang, an dem ich beteiligt bin, wird geschlossen'),
				},
			]
		},

		/** Ob es überhaupt etwas aufzuheben gibt. */
		hatAusnahmen(): boolean {
			return Object.keys(this.prefs.boards).length > 0
		},
	},

	async mounted() {
		await this.laden()
	},

	methods: {
		t,

		async laden(): Promise<void> {
			try {
				// Beides zusammen: Ohne die Projektliste bliebe nur die
				// allgemeine Zeile — und die war ja gerade das Problem.
				const [prefs, boards] = await Promise.all([fetchNotifyPrefs(), fetchBoards()])
				this.prefs = prefs
				this.boards = boards
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Einstellungen konnten nicht geladen werden'))
			}

			// **Der private Ordner getrennt** (#191): eine Nebeneinstellung darf
			// die Benachrichtigungs-Tabelle nicht mitreißen. Scheitert der Abruf,
			// bleibt der Rest der Seite bedienbar; nur das Ordnerfeld ist leer.
			try {
				const { path } = await fetchPrivateFolder()
				this.ordnerAktuell = path
				this.ordnerEntwurf = path
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Der Ordner für private Anhänge konnte nicht geladen werden'))
			}

			// **Der GitHub-Token getrennt** (#12), aus demselben Grund wie der
			// private Ordner: eine Nebeneinstellung darf die übrige Seite nicht
			// mitreißen. Scheitert der Abruf, bleibt der Rest bedienbar.
			try {
				const { present } = await fetchGithubTokenStatus()
				this.githubTokenPresent = present
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Der GitHub-Token-Status konnte nicht geladen werden'))
			}
		},

		/** Den Wähler öffnen, ab dem aktuell gesetzten Ordner. */
		pickerOeffnen(): void {
			this.picker.start = this.ordnerAktuell
			this.picker.open = true
		},

		/**
		 * @param pfad Der im Wähler gewählte Ordner — noch nicht gespeichert.
		 */
		onOrdnerGewaehlt(pfad: string): void {
			this.ordnerEntwurf = pfad
		},

		/**
		 * Den gewählten Ordner übernehmen. Der Server prüft den Pfad und legt
		 * ihn bei Bedarf an; ein unmöglicher Ordner kommt als Fehler zurück.
		 */
		async ordnerSpeichern(): Promise<void> {
			if (this.busy || this.ordnerEntwurf === this.ordnerAktuell) {
				return
			}

			this.busy = true
			try {
				const { path } = await setPrivateFolder(this.ordnerEntwurf)
				this.ordnerAktuell = path
				this.ordnerEntwurf = path
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Ordner konnte nicht gespeichert werden'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Den eingegebenen GitHub-Token speichern (#12). Nach dem Speichern wird
		 * das Feld geleert — der Token kommt nie wieder zurück ins Formular.
		 */
		async githubTokenSpeichern(): Promise<void> {
			if (this.busy || this.githubTokenEntwurf.trim() === '') {
				return
			}

			this.busy = true
			try {
				const { present } = await setGithubToken(this.githubTokenEntwurf.trim())
				this.githubTokenPresent = present
				this.githubTokenEntwurf = ''
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Token konnte nicht gespeichert werden'))
			} finally {
				this.busy = false
			}
		},

		/** Den hinterlegten GitHub-Token entfernen (#12). */
		async githubTokenEntfernen(): Promise<void> {
			if (this.busy || !this.githubTokenPresent) {
				return
			}

			this.busy = true
			try {
				const { present } = await clearGithubToken()
				this.githubTokenPresent = present
				this.githubTokenEntwurf = ''
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Token konnte nicht entfernt werden'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param key Kanal oder Anlass.
		 */
		globalStand(key: PrefKey): boolean {
			return this.prefs.global[key] ?? true
		},

		/**
		 * Der aufgelöste Stand — dieselben drei Stufen wie auf dem Server.
		 *
		 * @param key Ein Anlass.
		 * @param boardId Projekt.
		 */
		stand(key: NotifyEvent, boardId: number): boolean {
			return this.prefs.boards[boardId]?.[key] ?? this.globalStand(key)
		},

		/**
		 * Ist **dieses Kästchen** ausdrücklich gesetzt — oder geerbt?
		 *
		 * Je Anlass, nicht je Zeile: Die fünf sind unabhängig, und eine Angabe
		 * für alle wäre für vier von ihnen falsch.
		 *
		 * @param key Ein Anlass.
		 * @param boardId Projekt.
		 */
		istGesetzt(key: NotifyEvent, boardId: number): boolean {
			return this.prefs.boards[boardId]?.[key] !== undefined
		},

		/**
		 * Die Beschriftung fürs Vorlesen — am Kästchen steht sonst nur die
		 * Spalte, nicht die Zeile. Und der Punkt allein ist kein Text (§9:
		 * Farbe UND Symbol UND Text).
		 *
		 * @param anlass Der Anlass.
		 * @param anlass.key
		 * @param anlass.label
		 * @param anlass.lang
		 * @param board Das Projekt, oder null für die allgemeine Zeile.
		 */
		beschriftung(anlass: { key: NotifyEvent, label: string, lang: string }, board: Board | null): string {
			if (board === null) {
				return t('projektwerk', '{event} — für alle Projekte', { event: anlass.lang })
			}

			const basis = t('projektwerk', '{event} — {project}', { event: anlass.lang, project: board.title })

			return this.istGesetzt(anlass.key, board.id)
				? basis + ' — ' + t('projektwerk', 'für dieses Projekt festgelegt')
				: basis + ' — ' + t('projektwerk', 'wie alle Projekte')
		},

		/**
		 * @param key Kanal oder Anlass.
		 * @param an Neuer Stand.
		 * @param boardId Projekt, oder 0 für die allgemeine Wahl.
		 */
		async setzen(key: PrefKey, an: boolean, boardId: number): Promise<void> {
			await this.schreiben(() => setNotifyPref(key, an, boardId))
		},

		async alleZuruecksetzen(): Promise<void> {
			await this.schreiben(() => clearNotifyOverrides())
		},

		/**
		 * @param run Der Schreibaufruf.
		 */
		async schreiben(run: () => Promise<NotifyPrefs>): Promise<void> {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				this.prefs = await run()
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Einstellung konnte nicht gespeichert werden'))
			} finally {
				this.busy = false
			}
		},
	},
})
</script>
