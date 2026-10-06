<template>
	<div class="pw-view pw-settingspage">
		<h2 class="pw-settingspage__title">
			{{ t('projektwerk', 'Verwaltung') }}
		</h2>

		<div class="pw-settingspage__body">
			<PwSettingsNav
				:sections="sections"
				:modelValue="activeSection"
				@update:modelValue="activeSection = $event" />

			<div class="pw-settingspage__content">
				<!--
					**Eigene Firma** (#352): einmal je Instanz. Neue Projekte
					übernehmen sie als „eigene Firma"; im Projekt bleibt sie änderbar.
				-->
				<div v-show="activeSection === 'firma'">
					<h3 class="pw-settingspage__head">
						{{ t('projektwerk', 'Eigene Firma') }}
					</h3>
					<section class="pw-settingspage__block">
						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Der Name Ihrer Firma. Neue Projekte übernehmen ihn als „Eigene Firma"; im einzelnen Projekt lässt er sich ändern. Bestehende Projekte bleiben unverändert.') }}
						</p>
						<div class="pw-settings__row">
							<NcTextField
								v-model="firmaEntwurf"
								:label="t('projektwerk', 'Eigene Firma')"
								:disabled="busy"
								:maxlength="255"
								@keydown.enter="firmaSpeichern" />
							<NcButton variant="primary" :disabled="busy || firmaEntwurf.trim() === ownCompany" @click="firmaSpeichern">
								{{ t('projektwerk', 'Speichern') }}
							</NcButton>
						</div>
					</section>
				</div>

				<div v-show="activeSection === 'antworten'">
					<h3 class="pw-settingspage__head">
						{{ t('projektwerk', 'Antworten per E-Mail') }}
					</h3>
					<section class="pw-settingspage__block">
						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Antworten auf Benachrichtigungs-E-Mails werden als Kommentar am Vorgang eingetragen. Dazu liest ProjektWerk ein IMAP-Postfach, das Sie hier hinterlegen. Es verlässt nichts die Instanz — das Postfach gehört Ihnen.') }}
						</p>

						<label class="pw-settings__check pw-replymail__switch">
							<NcCheckboxRadioSwitch
								v-model="form.replyEnabled"
								type="switch"
								:disabled="busy">
								{{ t('projektwerk', 'Antworten per E-Mail aktivieren') }}
							</NcCheckboxRadioSwitch>
						</label>
						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Ist der Schalter aus, ändert sich am Versand nichts — nur die Antwortadresse (Reply-To) wird dann nicht gesetzt.') }}
						</p>
					</section>

					<section class="pw-settingspage__block">
						<h3 class="pw-settingspage__head">
							{{ t('projektwerk', 'Antwortadresse') }}
						</h3>
						<p class="pw-settings__hint">
							{{ t('projektwerk', 'Die Adresse, an die Kunden antworten (z. B. projekte@ihre-firma.de). Sie wird als Reply-To gesetzt und gehört zu dem Postfach unten.') }}
						</p>
						<div class="pw-settings__row">
							<NcTextField
								v-model="form.replyAddress"
								:label="t('projektwerk', 'Antwortadresse (Reply-To)')"
								:disabled="busy"
								type="email"
								autocomplete="off" />
						</div>
					</section>

					<section class="pw-settingspage__block">
						<h3 class="pw-settingspage__head">
							{{ t('projektwerk', 'Posteingang (IMAP)') }}
						</h3>

						<div class="pw-settings__row">
							<NcTextField
								v-model="form.imapHost"
								:label="t('projektwerk', 'Server')"
								placeholder="imap.ihre-firma.de"
								:disabled="busy"
								autocomplete="off" />
							<NcTextField
								v-model.number="form.imapPort"
								:label="t('projektwerk', 'Port')"
								type="number"
								:disabled="busy"
								class="pw-replymail__port" />
						</div>

						<div class="pw-settings__row">
							<label class="pw-replymail__field">
								<span class="pw-replymail__label">{{ t('projektwerk', 'Verschlüsselung') }}</span>
								<select v-model="form.imapSecurity" :disabled="busy" class="pw-replymail__select">
									<option value="ssl">SSL/TLS</option>
									<option value="starttls">STARTTLS</option>
									<option value="tls">TLS</option>
								</select>
							</label>
							<NcTextField
								v-model="form.imapFolder"
								:label="t('projektwerk', 'Ordner')"
								placeholder="INBOX"
								:disabled="busy"
								autocomplete="off" />
						</div>

						<div class="pw-settings__row">
							<NcTextField
								v-model="form.imapUser"
								:label="t('projektwerk', 'Benutzername')"
								:disabled="busy"
								autocomplete="off" />
							<NcTextField
								v-model="passwordDraft"
								:label="t('projektwerk', 'Passwort')"
								type="password"
								:placeholder="form.imapPasswordSet ? t('projektwerk', 'Gespeichert — zum Ändern neu eingeben') : ''"
								:disabled="busy || passwordClear"
								autocomplete="off" />
						</div>

						<label v-if="form.imapPasswordSet" class="pw-settings__check">
							<input v-model="passwordClear" type="checkbox" :disabled="busy">
							{{ t('projektwerk', 'Gespeichertes Passwort entfernen') }}
						</label>

						<p v-if="testResult" class="pw-settings__status" :class="testOk ? 'pw-replymail__ok' : 'pw-replymail__err'">
							<CheckIcon v-if="testOk" :size="18" class="pw-settings__status-icon pw-settings__status-icon--ok" />
							<AlertIcon v-else :size="18" class="pw-settings__status-icon" />
							{{ testResult }}
						</p>

						<div class="pw-settings__row pw-replymail__actions">
							<NcButton :disabled="busy || form.imapHost.trim() === ''" @click="verbindungTesten">
								<template #icon>
									<NcLoadingIcon v-if="testing" :size="20" />
									<LanConnectIcon v-else :size="20" />
								</template>
								{{ t('projektwerk', 'Verbindung testen') }}
							</NcButton>
							<NcButton variant="primary" :disabled="busy" @click="speichern">
								{{ t('projektwerk', 'Speichern') }}
							</NcButton>
						</div>
					</section>
				</div>
			</div>
		</div>
	</div>
</template>

<script lang="ts">
import type { ReplyMailboxConfig } from '@/services/replyMailbox'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckIcon from 'vue-material-design-icons/CheckCircleOutline.vue'
import LanConnectIcon from 'vue-material-design-icons/LanConnect.vue'
import PwSettingsNav from '@/components/PwSettingsNav.vue'
import { ownCompany, saveOwnCompany } from '@/services/ownCompany'
import { fetchReplyMailbox, saveReplyMailbox, testReplyMailbox } from '@/services/replyMailbox'
import { showError, showSuccess } from '@/services/toast'

/**
 * Antworten per E-Mail (#286) — die Admin-Einstellungen des Antwort-Postfachs.
 *
 * Eine Instanz-weite Konfiguration: das IMAP-Postfach des Betreibers, aus dem
 * der Einlese-Job (#287) Antworten liest. Die Seite ist nur über den
 * admin-only Menüeintrag erreichbar; die eigentliche Sperre sitzt serverseitig
 * (die Endpunkte verlangen ein Admin-Konto).
 *
 * Das Passwort wird nie geladen — nur `imapPasswordSet` sagt, ob eines
 * hinterlegt ist. Ein leeres Passwortfeld lässt das gespeicherte beim Speichern
 * unberührt.
 */
export default defineComponent({
	name: 'ReplyMailboxSettingsView',

	components: { AlertIcon, CheckIcon, LanConnectIcon, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcTextField, PwSettingsNav },

	data() {
		return {
			activeSection: 'firma',
			/** Das Eingabefeld der eigenen Firma, bis es gespeichert wird. */
			firmaEntwurf: ownCompany.value,
			busy: false,
			testing: false,
			form: {
				replyEnabled: false,
				replyAddress: '',
				imapHost: '',
				imapPort: 993,
				imapSecurity: 'ssl',
				imapUser: '',
				imapFolder: 'INBOX',
				imapPasswordSet: false,
			} as ReplyMailboxConfig,

			/** Das neu eingegebene Passwort, bis es gespeichert wird. */
			passwordDraft: '',
			/** Ob das gespeicherte Passwort beim Speichern gelöscht werden soll. */
			passwordClear: false,
			/** Text der letzten Testmeldung, oder leer. */
			testResult: '',
			/** Ob die letzte Testmeldung ein Erfolg war. */
			testOk: false,
		}
	},

	computed: {
		/** Die Bereiche der Verwaltung. */
		sections(): { key: string, label: string }[] {
			return [
				{ key: 'firma', label: t('projektwerk', 'Eigene Firma') },
				{ key: 'antworten', label: t('projektwerk', 'Antworten per E-Mail') },
			]
		},

		/** Der gespeicherte Name, zum Vergleich mit dem Entwurf. */
		ownCompany(): string {
			return ownCompany.value
		},
	},

	async mounted() {
		await this.laden()
	},

	methods: {
		t,

		async laden(): Promise<void> {
			this.busy = true
			try {
				this.form = await fetchReplyMailbox()
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Einstellungen konnten nicht geladen werden'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Die Verbindung mit den aktuell im Formular stehenden Daten testen.
		 * Ein leeres Passwortfeld heißt „gespeichertes verwenden"; der Server
		 * setzt es ein.
		 */
		async verbindungTesten(): Promise<void> {
			if (this.busy || this.form.imapHost.trim() === '') {
				return
			}

			this.testing = true
			this.busy = true
			this.testResult = ''
			try {
				await testReplyMailbox({
					imapHost: this.form.imapHost,
					imapPort: this.form.imapPort,
					imapSecurity: this.form.imapSecurity,
					imapUser: this.form.imapUser,
					imapFolder: this.form.imapFolder,
					imapPassword: this.passwordDraft || undefined,
				})
				this.testOk = true
				this.testResult = t('projektwerk', 'Verbindung erfolgreich.')
			} catch (e) {
				this.testOk = false
				this.testResult = (e as { message?: string }).message ?? t('projektwerk', 'Verbindung fehlgeschlagen.')
			} finally {
				this.testing = false
				this.busy = false
			}
		},

		/** Die eigene Firma speichern. */
		async firmaSpeichern(): Promise<void> {
			if (this.busy) {
				return
			}

			this.busy = true
			try {
				this.firmaEntwurf = await saveOwnCompany(this.firmaEntwurf.trim())
				showSuccess(t('projektwerk', 'Einstellungen gespeichert.'))
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Einstellungen konnten nicht gespeichert werden'))
			} finally {
				this.busy = false
			}
		},

		/** Die Einstellungen speichern. */
		async speichern(): Promise<void> {
			if (this.busy) {
				return
			}

			this.busy = true
			try {
				this.form = await saveReplyMailbox({
					replyEnabled: this.form.replyEnabled,
					replyAddress: this.form.replyAddress,
					imapHost: this.form.imapHost,
					imapPort: this.form.imapPort,
					imapSecurity: this.form.imapSecurity,
					imapUser: this.form.imapUser,
					imapFolder: this.form.imapFolder,
					imapPassword: this.passwordClear ? undefined : (this.passwordDraft || undefined),
					imapPasswordClear: this.passwordClear,
				})
				this.passwordDraft = ''
				this.passwordClear = false
				this.testResult = ''
				showSuccess(t('projektwerk', 'Einstellungen gespeichert.'))
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Einstellungen konnten nicht gespeichert werden'))
			} finally {
				this.busy = false
			}
		},
	},
})
</script>

<style scoped>
.pw-replymail__switch {
	margin-block: 8px;
}

.pw-replymail__actions {
	margin-block-start: 16px;
	gap: 8px;
}

.pw-replymail__port {
	max-width: 8rem;
}

.pw-replymail__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.pw-replymail__label {
	font-weight: 600;
}

.pw-replymail__select {
	min-height: 44px;
}

.pw-replymail__ok {
	color: var(--color-success-text, var(--color-success));
}

.pw-replymail__err {
	color: var(--color-error-text, var(--color-error));
}
</style>
