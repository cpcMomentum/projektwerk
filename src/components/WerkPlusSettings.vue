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
		</p>
		<p class="pw-settings__hint">
			{{ t('projektwerk', 'Ein Schlüssel gilt für alle Werk-Apps dieser Nextcloud. Ist er schon in einer anderen Werk-App hinterlegt, ist hier nichts weiter zu tun.') }}
		</p>

		<NcNoteCard v-if="loadError" type="error" :text="loadError" />

		<template v-if="status">
			<NcNoteCard
				v-if="status.storeOutdated"
				type="warning"
				:text="t('projektwerk', 'Eine neuere Werk-App hat die Lizenzablage umgestellt. Bitte aktualisieren Sie ProjektWerk. Bis dahin lässt sich die Lizenz hier nicht ändern.')" />
			<NcNoteCard v-if="stateNote" :type="stateNote.type" :text="stateNote.text" />
			<NcNoteCard v-if="trialKey" type="success">
				<p v-if="licenseActive">
					{{ t('projektwerk', 'Der Testzeitraum läuft. Das ist Ihr WerkPlus-Schlüssel:') }}
				</p>
				<p v-else>
					{{ t('projektwerk', 'Der Testzeitraum ist gestartet, die Lizenz konnte aber noch nicht geprüft werden. Mit „Jetzt prüfen“ versuchen Sie es erneut. Das ist Ihr WerkPlus-Schlüssel:') }}
				</p>
				<p><code>{{ trialKey }}</code></p>
				<p>{{ t('projektwerk', 'Bitte bewahren Sie ihn sicher auf. Er ist hinterlegt, wird aber nur jetzt vollständig angezeigt.') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="actionInfo" type="info" :text="actionInfo" />
			<NcNoteCard v-if="actionError" type="error" :text="actionError" />
			<NcNoteCard v-else-if="status.lastError" type="warning" :text="status.lastError.message" />

			<dl v-if="status.keySet" class="pw-werkplus__facts">
				<dt>{{ t('projektwerk', 'Tarif') }}</dt>
				<dd>{{ tierLabel }}</dd>
				<template v-if="status.licensedEmployees">
					<dt>{{ t('projektwerk', 'Lizenziert für') }}</dt>
					<dd>{{ n('projektwerk', '%n Mitarbeitende', '%n Mitarbeitende', status.licensedEmployees) }}</dd>
				</template>
				<template v-if="status.expiresAt">
					<dt>{{ t('projektwerk', 'Gültig bis') }}</dt>
					<dd>{{ formatDate(status.expiresAt) }}</dd>
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

			<h3>
				{{ t('projektwerk', 'Was an WerkPlus übertragen wird') }}
			</h3>
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
				<p>{{ t('projektwerk', 'Der Testzeitraum läuft 30 Tage, braucht keine Zahlungsdaten und ist je Nextcloud nur einmal möglich. Er gilt für alle Werk-Apps dieser Nextcloud.') }}</p>
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
				<p>{{ t('projektwerk', 'Der Schlüssel wird für alle Werk-Apps dieser Nextcloud entfernt, nicht nur für ProjektWerk. Sie arbeiten danach im freien Umfang. Bestehende Projekte und Boards bleiben erhalten. Den Schlüssel können Sie jederzeit wieder eintragen.') }}</p>
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

const tierLabel = computed(() => (status.value?.tier === 'werkplus' ? 'WerkPlus' : t('projektwerk', 'Frei')))

const stateNote = computed<{ type: 'success' | 'warning' | 'error', text: string } | null>(() => {
	const s = status.value
	if (!s) {
		return null
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
			return { type: 'error', text: t('projektwerk', 'Der gespeicherte Lizenznachweis ist ungültig. Bitte prüfen Sie die Lizenz erneut.') }
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
		const text = message ?? t('projektwerk', 'WerkPlus hat einen Fehler gemeldet. Bitte versuchen Sie es später erneut.')
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
	margin: 12px 0;
}

.pw-werkplus__facts dt {
	color: var(--color-text-maxcontrast);
}

.pw-werkplus__facts dd {
	margin: 0;
}

.pw-werkplus__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 12px 0 24px;
}
</style>
