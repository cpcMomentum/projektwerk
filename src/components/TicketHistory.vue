<!--
  - SPDX-FileCopyrightText: 2026 cpcMomentum
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pw-verlauf">
		<p v-if="loading && entries.length === 0" class="pw-verlauf__leer">
			{{ t('projektwerk', 'Verlauf wird geladen …') }}
		</p>
		<p v-else-if="failed" class="pw-verlauf__leer">
			{{ t('projektwerk', 'Der Verlauf konnte nicht geladen werden.') }}
		</p>
		<p v-else-if="entries.length === 0" class="pw-verlauf__leer">
			{{ t('projektwerk', 'Noch keine Einträge.') }}
		</p>

		<ol v-else class="pw-verlauf__liste">
			<li v-for="entry in newestFirst" :key="entry.id" class="pw-verlauf__eintrag">
				<NcAvatar
					:user="entry.actorUserId"
					:displayName="nameOf(entry.actorUserId)"
					:isNoUser="entry.actorUserId === ACTOR_SYSTEM"
					:size="24"
					:disableMenu="true"
					:hideStatus="true" />
				<div class="pw-verlauf__text">
					<span class="pw-verlauf__name">{{ nameOf(entry.actorUserId) }}</span>
					{{ describe(entry) }}
					<span class="pw-verlauf__meta">
						<NcDateTime :timestamp="new Date(entry.createdAt)" relativeTime="short" />
						<template v-if="entry.via === 'tool'">
							· {{ t('projektwerk', 'über KI-Werkzeug') }}
						</template>
					</span>
				</div>
			</li>
		</ol>
	</div>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Member } from '@/types/board'
import type { TicketLogEntry } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import { fetchTicketHistory } from '@/services/tickets'
import { asDate } from '@/utils/date'

// Die Werte landen per Mustache im Text, Vue escaped selbst.
const RAW = { escape: false }

/** Akteur für Änderungen ohne handelnde Person, etwa per `occ`; gleicher Wert wie `TicketLog::ACTOR_SYSTEM`. */
const ACTOR_SYSTEM = 'system'

export default defineComponent({
	name: 'TicketHistory',

	components: { NcAvatar, NcDateTime },

	props: {
		boardId: { type: Number, required: true },
		ticketId: { type: Number, required: true },
		/** Lädt neu, sobald sich der Vorgang ändert. */
		version: { type: Number, default: 0 },
		/** Steps, Anhänge und Kommentare ändern die Version nicht; ihr Zähler löst das Neuladen aus. */
		changeKey: { type: String, default: '' },
		members: { type: Array as PropType<Member[]>, default: () => [] },
	},

	data() {
		return {
			entries: [] as TicketLogEntry[],
			loading: false,
			failed: false,
			/** Nur die jüngste Anfrage darf schreiben; eine langsamere ältere überschriebe sonst den neueren Stand. */
			request: 0,
			ACTOR_SYSTEM,
		}
	},

	computed: {
		newestFirst(): TicketLogEntry[] {
			return [...this.entries].reverse()
		},
	},

	watch: {
		ticketId() {
			this.entries = []
			this.load()
		},

		version() {
			this.load()
		},

		changeKey() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		async load(): Promise<void> {
			const request = ++this.request
			this.loading = true
			try {
				const entries = await fetchTicketHistory(this.boardId, this.ticketId)
				if (request === this.request) {
					this.entries = entries
					this.failed = false
				}
			} catch {
				if (request === this.request) {
					this.failed = true
				}
			} finally {
				if (request === this.request) {
					this.loading = false
				}
			}
		},

		nameOf(userId: string | null): string {
			if (userId === null) {
				return ''
			}
			if (userId === ACTOR_SYSTEM) {
				return t('projektwerk', 'System')
			}
			return this.members.find((m) => m.userId === userId)?.resolvedName ?? userId
		},

		visibilityName(value: string | null): string {
			switch (value) {
				case 'public': return t('projektwerk', 'Alle Beteiligten')
				case 'internal': return t('projektwerk', 'Intern')
				default: return t('projektwerk', 'Nur ich')
			}
		},

		describe(entry: TicketLogEntry): string {
			const subject = entry.subject ?? ''
			const from = entry.oldValue ?? ''
			const to = entry.newValue ?? ''

			switch (entry.action) {
				case 'created':
					return t('projektwerk', 'hat den Vorgang angelegt')
				case 'moved':
					return t('projektwerk', 'hat den Vorgang verschoben: {from} → {to}', { from, to }, undefined, RAW)
				case 'title':
					return t('projektwerk', 'hat den Titel geändert: „{from}“ → „{to}“', { from, to }, undefined, RAW)
				case 'description':
					return t('projektwerk', 'hat die Beschreibung geändert')
				case 'responsible':
					return entry.newValue === null
						? t('projektwerk', 'hat die Zuständigkeit entfernt')
						: t('projektwerk', 'hat {name} als zuständig eingetragen', { name: this.nameOf(entry.newValue) }, undefined, RAW)
				case 'due_date':
					return entry.newValue === null
						? t('projektwerk', 'hat die Fälligkeit entfernt')
						: t('projektwerk', 'hat die Fälligkeit auf {date} gesetzt', { date: asDate(entry.newValue)?.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric' }) ?? '' }, undefined, RAW)
				case 'closed':
					return entry.newValue === 'discarded'
						? t('projektwerk', 'hat den Vorgang als verworfen abgeschlossen')
						: t('projektwerk', 'hat den Vorgang als erledigt abgeschlossen')
				case 'reopened':
					return t('projektwerk', 'hat den Vorgang wieder geöffnet')
				case 'archived':
					return t('projektwerk', 'hat den Vorgang archiviert')
				case 'unarchived':
					return t('projektwerk', 'hat den Vorgang aus dem Archiv geholt')
				case 'visibility':
					return entry.oldValue === null
						? t('projektwerk', 'hat die Sichtbarkeit auf „{to}“ gesetzt', { to: this.visibilityName(entry.newValue) }, undefined, RAW)
						: t('projektwerk', 'hat die Sichtbarkeit geändert: „{from}“ → „{to}“', {
								from: this.visibilityName(entry.oldValue),
								to: this.visibilityName(entry.newValue),
							}, undefined, RAW)
				case 'github':
					return t('projektwerk', 'hat den Vorgang nach GitHub übertragen')
				case 'deleted':
					return t('projektwerk', 'hat den Vorgang gelöscht')
				case 'restored':
					return t('projektwerk', 'hat den Vorgang wiederhergestellt')
				case 'step_added':
					return t('projektwerk', 'hat den Arbeitsschritt „{step}“ angelegt', { step: subject }, undefined, RAW)
				case 'step_done':
					return t('projektwerk', 'hat den Arbeitsschritt „{step}“ abgehakt', { step: subject }, undefined, RAW)
				case 'step_reopened':
					return t('projektwerk', 'hat den Haken bei „{step}“ entfernt', { step: subject }, undefined, RAW)
				case 'step_assigned':
					return entry.newValue === null
						? t('projektwerk', 'hat die Zuweisung von „{step}“ entfernt', { step: subject }, undefined, RAW)
						: t('projektwerk', 'hat den Arbeitsschritt „{step}“ {name} zugewiesen', { step: subject, name: this.nameOf(entry.newValue) }, undefined, RAW)
				case 'step_deleted':
					return t('projektwerk', 'hat den Arbeitsschritt „{step}“ gelöscht', { step: subject }, undefined, RAW)
				case 'attachment_added':
					return t('projektwerk', 'hat den Anhang „{file}“ hinzugefügt', { file: subject }, undefined, RAW)
				case 'attachment_removed':
					return t('projektwerk', 'hat den Anhang „{file}“ entfernt', { file: subject }, undefined, RAW)
				default:
					return t('projektwerk', 'hat den Vorgang geändert')
			}
		},
	},
})
</script>
