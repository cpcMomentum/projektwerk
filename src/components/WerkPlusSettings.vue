<!--
	SPDX-FileCopyrightText: 2026 cpcMomentum
	SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="pw-form-section pw-werkplus">
		<h3>
			{{ t('projektwerk', 'WerkPlus') }}
		</h3>
		<p class="pw-settings__hint">
			{{ t('projektwerk', 'Frei enthalten sind zehn aktive Projekte mit je einem Board und ein Kundenprojekt. Mit einem WerkPlus-Schlüssel schaltet ProjektWerk weitere Projekte, Kundenprojekte und Boards frei, dazu Antworten per E-Mail und den KI-Zugriff über MCP.') }}
			<a :href="WERKPLUS_LINKS.features" target="_blank" rel="noopener noreferrer">{{ t('projektwerk', 'Alle WerkPlus-Funktionen') }}</a>
		</p>
		<p class="pw-settings__hint">
			{{ t('projektwerk', 'Der Schlüssel gilt für alle Werk-Apps dieser Nextcloud und muss nur in einer davon eingetragen werden.') }}
		</p>

		<NcNoteCard v-if="loadError" type="error" :text="loadError" />

		<template v-if="status">
			<NcNoteCard
				v-if="status.storeOutdated"
				type="warning"
				:text="t('projektwerk', 'Eine andere Werk-App hat die Lizenz in einem neueren Format gespeichert. Bitte aktualisiere ProjektWerk.')" />
			<NcNoteCard v-if="stateNote" :type="stateNote.type" :text="stateNote.text" />
			<p v-if="trialActive" class="pw-settings__hint">
				{{ t('projektwerk', 'Mit dem Kauf erhältst du einen eigenen Schlüssel. Trage ihn hier ein, er ersetzt den Testschlüssel.') }}
			</p>
			<NcNoteCard v-if="trialKey" type="success">
				<p v-if="licenseActive">
					{{ t('projektwerk', 'Der Testzeitraum läuft. Das ist dein WerkPlus-Schlüssel:') }}
				</p>
				<p v-else>
					{{ t('projektwerk', 'Der Testzeitraum ist gestartet, die Lizenz konnte aber noch nicht geprüft werden. Mit „Jetzt prüfen“ versuchst du es erneut. Das ist dein WerkPlus-Schlüssel:') }}
				</p>
				<p><code>{{ trialKey }}</code></p>
				<p>{{ t('projektwerk', 'Bitte bewahre ihn sicher auf. Er ist hinterlegt, wird aber nur jetzt vollständig angezeigt.') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="actionInfo" type="info" :text="actionInfo" />
			<NcNoteCard v-if="actionError" type="error" :text="actionError" />
			<NcNoteCard v-else-if="status.lastError && !status.trialEnded" type="warning" :text="status.lastError.message" />

			<dl v-if="status.keySet" class="pw-werkplus__facts">
				<dt>{{ t('projektwerk', 'Tarif') }}</dt>
				<dd>{{ tierLabel }}</dd>
				<template v-if="status.licensedEmployees">
					<dt>{{ t('projektwerk', 'Lizenzierte Mitarbeitende') }}</dt>
					<dd>{{ status.licensedEmployees }}</dd>
				</template>
				<template v-if="termLine">
					<dt>{{ termLine.label }}</dt>
					<dd>{{ termLine.value }}</dd>
				</template>
				<dt>{{ t('projektwerk', 'Letzte erfolgreiche Prüfung') }}</dt>
				<dd>{{ status.lastCheckAt ? formatDateTime(status.lastCheckAt) : t('projektwerk', 'noch keine') }}</dd>
				<dt>{{ t('projektwerk', 'Schlüssel') }}</dt>
				<dd>{{ status.keyHint }}</dd>
			</dl>

			<div v-if="!status.storeOutdated" class="pw-feld">
				<label class="pw-feld__label" for="pw-werkplus-key">{{ t('projektwerk', 'WerkPlus-Schlüssel') }}</label>
				<NcTextField
					id="pw-werkplus-key"
					v-model="keyInput"
					:label="t('projektwerk', 'WerkPlus-Schlüssel')"
					:labelOutside="true"
					:disabled="busy"
					autocomplete="off"
					spellcheck="false"
					:placeholder="status.keySet ? t('projektwerk', 'gespeichert, leer lassen') : 'wp_…'" />
			</div>

			<div v-if="!status.storeOutdated" class="pw-werkplus__actions">
				<NcButton variant="primary" :disabled="busy || (!keyInput.trim() && !status.keySet)" @click="onActivate">
					{{ status.keySet ? t('projektwerk', 'Speichern und prüfen') : t('projektwerk', 'Schlüssel prüfen und speichern') }}
				</NcButton>
				<NcButton v-if="!status.keySet" :disabled="busy" @click="confirmTrial = true">
					{{ t('projektwerk', '30 Tage testen') }}
				</NcButton>
				<NcButton v-if="showBuy" :href="WERKPLUS_LINKS.buy" target="_blank">
					{{ status.keySet ? t('projektwerk', 'Jetzt kaufen') : t('projektwerk', 'WerkPlus kaufen') }}
				</NcButton>
				<NcButton v-if="status.keySet" :disabled="busy" @click="onRefresh">
					{{ t('projektwerk', 'Jetzt prüfen') }}
				</NcButton>
				<NcButton
					v-if="status.keySet"
					variant="tertiary"
					:disabled="busy"
					@click="confirmRemove = true">
					{{ t('projektwerk', 'Schlüssel entfernen') }}
				</NcButton>
			</div>

			<h4>
				{{ t('projektwerk', 'Was an WerkPlus übertragen wird') }}
			</h4>
			<p class="pw-settings__hint">
				{{ t('projektwerk', 'Eine aus dieser Nextcloud abgeleitete Kennung, die Versionen der aktivierten Werk-Apps und, sobald vorhanden, der Schlüssel. Nicht übertragen werden Vorgänge, Kommentare, Dateien, Namen, Adressen, Nextcloud-Benutzer oder Datei-IDs.') }}
			</p>
			<p class="pw-settings__hint">
				{{ t('projektwerk', 'Die Lizenz wird auf diesem Server geprüft. Ist WerkPlus nicht erreichbar, arbeitet ProjektWerk normal weiter.') }}
			</p>
			<p class="pw-settings__hint">
				{{ t('projektwerk', 'Kennung dieser Nextcloud:') }} <code>{{ status.instanceUuid }}</code>
			</p>
		</template>

		<NcDialog
			:open="confirmTrial"
			:name="t('projektwerk', 'WerkPlus 30 Tage testen')"
			size="normal"
			@update:open="confirmTrial = false">
			<div class="app-projektwerk">
				<p>{{ t('projektwerk', 'Der Testzeitraum läuft 30 Tage, braucht keine Zahlungsdaten, gilt für alle Werk-Apps dieser Nextcloud und ist je Nextcloud nur einmal möglich. Jetzt starten?') }}</p>
			</div>
			<template #actions>
				<NcButton @click="confirmTrial = false">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" @click="onTrial">
					{{ t('projektwerk', 'Testzeitraum starten') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			:open="confirmRemove"
			:name="t('projektwerk', 'WerkPlus-Schlüssel entfernen')"
			size="normal"
			@update:open="confirmRemove = false">
			<div class="app-projektwerk">
				<p>{{ t('projektwerk', 'Der Schlüssel wird für alle Werk-Apps dieser Nextcloud entfernt, sie arbeiten danach im freien Umfang. Den Schlüssel kannst du jederzeit wieder eintragen. Fortfahren?') }}</p>
			</div>
			<template #actions>
				<NcButton @click="confirmRemove = false">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="error" @click="onRemove">
					{{ t('projektwerk', 'Entfernen') }}
				</NcButton>
			</template>
		</NcDialog>
	</section>
</template>

<script setup lang="ts">
import type { LicenseStatus } from '@/services/license'

import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { activateLicense, getLicense, refreshLicense, removeLicense, startTrial } from '@/services/license'
import { WERKPLUS_LINKS } from '@/utils/werkplusLinks'

const emit = defineEmits<{ changed: [] }>()

const status = ref<LicenseStatus | null>(null)
const keyInput = ref('')
const busy = ref(false)
const loadError = ref('')
const actionError = ref('')
const actionInfo = ref('')
const trialKey = ref('')
const confirmRemove = ref(false)
const confirmTrial = ref(false)

// Der Vertrag verlangt hier eine ruhige Anzeige statt eines Fehlers.
const CALM_CODES = ['TRIAL_ALREADY_USED']

const licenseActive = computed(() => status.value?.state === 'valid' || status.value?.state === 'grace')

const trialActive = computed(() => licenseActive.value && status.value?.trial === true)

// Kein Kauf-Link für Zahlende, deren Token nur gerade fehlt oder ungültig ist.
const showBuy = computed(() => {
	const s = status.value
	return !s?.keySet || trialActive.value || s.trialEnded || s.state === 'expired' || s.lastError?.code === 'KEY_EXPIRED'
})

const tierLabel = computed(() => {
	if (status.value?.tier !== 'werkplus') {
		return t('projektwerk', 'Frei')
	}
	return trialActive.value ? t('projektwerk', 'WerkPlus – Testzeitraum') : 'WerkPlus'
})

// Das Token-Ende (exp) ist nur der nächste Prüftermin und wird nie angezeigt.
const termLine = computed<{ label: string, value: string } | null>(() => {
	const s = status.value
	if (!s?.licenseValidUntil || !licenseActive.value) {
		return null
	}
	const until = new Date(s.licenseValidUntil)
	if (s.trial) {
		const days = Math.max(0, Math.ceil((until.getTime() - Date.now()) / 86400000))
		return {
			label: t('projektwerk', 'Gültig bis'),
			value: n('projektwerk', '{date} (noch %n Tag)', '{date} (noch %n Tage)', days, { date: formatDate(s.licenseValidUntil) }),
		}
	}
	if (until.getTime() <= Date.now() && s.state === 'grace' && s.graceUntil) {
		return {
			label: t('projektwerk', 'Laufzeit'),
			value: t('projektwerk', 'Laufzeit abgelaufen, Karenz bis {date}', { date: formatDate(s.graceUntil) }),
		}
	}
	return { label: t('projektwerk', 'Laufzeit bis'), value: formatDate(s.licenseValidUntil) }
})

const stateNote = computed<{ type: 'success' | 'warning' | 'error', text: string } | null>(() => {
	const s = status.value
	if (!s) {
		return null
	}
	if (s.trialEnded) {
		return { type: 'warning', text: t('projektwerk', 'Testzeitraum beendet. ProjektWerk arbeitet im freien Umfang weiter, deine Daten bleiben erhalten.') }
	}
	switch (s.state) {
		case 'valid':
			return { type: 'success', text: t('projektwerk', 'WerkPlus ist aktiv.') }
		case 'grace':
			return {
				type: 'warning',
				text: t('projektwerk', 'Die Lizenz ist abgelaufen und konnte noch nicht erneuert werden. Die Funktionen bleiben bis {date} erhalten.', { date: s.graceUntil ? formatDate(s.graceUntil) : '' }),
			}
		case 'expired':
			return { type: 'error', text: t('projektwerk', 'Die Lizenz ist abgelaufen. ProjektWerk arbeitet im freien Umfang weiter.') }
		case 'invalid':
			return { type: 'error', text: t('projektwerk', 'Der gespeicherte Lizenznachweis ist ungültig. Bitte prüfe die Lizenz erneut.') }
		default:
			return null
	}
})

/**
 * @param iso Zeitpunkt im ISO-Format.
 */
function formatDate(iso: string): string {
	return new Date(iso).toLocaleDateString()
}

/**
 * @param iso Zeitpunkt im ISO-Format.
 */
function formatDateTime(iso: string): string {
	return new Date(iso).toLocaleString()
}

/** Den Lizenzstand vom Server holen. */
async function load(): Promise<void> {
	try {
		status.value = await getLicense()
		loadError.value = ''
	} catch (e) {
		loadError.value = (e as { message?: string }).message ?? t('projektwerk', 'Lizenzstatus konnte nicht geladen werden.')
	}
}

/** @param action Liefert den neuen Status; nach einem Fehler bleibt die Eingabe stehen. */
async function run(action: () => Promise<LicenseStatus>): Promise<void> {
	busy.value = true
	actionError.value = ''
	actionInfo.value = ''
	try {
		status.value = await action()
		keyInput.value = ''
	} catch (e) {
		const { message, data } = e as { message?: string, data?: Record<string, unknown> }
		const text = message ?? t('projektwerk', 'WerkPlus hat einen Fehler gemeldet. Bitte versuche es später erneut.')
		if (typeof data?.code === 'string' && CALM_CODES.includes(data.code)) {
			actionInfo.value = text
		} else {
			actionError.value = text
		}
		await load()
	} finally {
		busy.value = false
		emit('changed')
	}
}

/** Schlüssel prüfen und speichern. */
function onActivate(): Promise<void> {
	trialKey.value = ''
	return run(() => activateLicense(keyInput.value.trim()))
}

/** Den Testzeitraum starten; der Schlüssel kommt nur dieses eine Mal. */
function onTrial(): Promise<void> {
	confirmTrial.value = false
	trialKey.value = ''
	return run(async () => {
		const result = await startTrial()
		trialKey.value = result.key
		return result.status
	})
}

/** Das Token jetzt erneuern. */
function onRefresh(): Promise<void> {
	return run(refreshLicense)
}

/** Schlüssel und Token entfernen. */
function onRemove(): Promise<void> {
	confirmRemove.value = false
	trialKey.value = ''
	return run(removeLicense)
}

onMounted(load)
</script>

<style scoped>
.pw-werkplus__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 12px 0 16px;
}

.pw-werkplus__facts dt,
.pw-werkplus__facts dd {
	width: auto;
	margin: 0;
	padding: 0;
	text-align: start;
}

.pw-werkplus__facts dt {
	color: var(--color-text-maxcontrast);
}

.pw-werkplus__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 12px 0 24px;
}
</style>
