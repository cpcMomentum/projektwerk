<!--
  - SPDX-FileCopyrightText: 2026 cpcMomentum
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pw-verlauf">
		<p v-if="loading && entries.length === 0" class="pw-verlauf__leer">
			{{ t('projektwerk', 'Protokoll wird geladen …') }}
		</p>
		<p v-else-if="failed && entries.length === 0" class="pw-verlauf__leer">
			{{ t('projektwerk', 'Das Protokoll konnte nicht geladen werden.') }}
		</p>
		<p v-else-if="entries.length === 0" class="pw-verlauf__leer">
			{{ t('projektwerk', 'Noch keine Einträge.') }}
		</p>

		<ol v-else class="pw-verlauf__liste">
			<li v-for="entry in entries" :key="entry.id" class="pw-verlauf__eintrag">
				<NcAvatar
					:user="entry.actorUserId"
					:displayName="actorName(entry)"
					:isNoUser="entry.actorUserId === ACTOR_SYSTEM"
					:size="24"
					:disableMenu="true"
					:hideStatus="true" />
				<div class="pw-verlauf__text">
					<span class="pw-verlauf__name">{{ actorName(entry) }}</span>
					{{ describe(entry) }}
					<span class="pw-verlauf__meta">
						<NcDateTime :timestamp="new Date(entry.createdAt)" relativeTime="short" />
						<template v-if="entry.via === 'tool'">
							· {{ t('projektwerk', 'über KI-Werkzeug') }}
						</template>
						<template v-else-if="entry.via === VIA_CONSOLE">
							· {{ t('projektwerk', 'über die Kommandozeile') }}
						</template>
					</span>
				</div>
			</li>
		</ol>

		<NcButton v-if="more" :disabled="loading" @click="load">
			{{ t('projektwerk', 'Ältere Einträge laden') }}
		</NcButton>
	</div>
</template>

<script lang="ts">
import type { ProjectLogEntry } from '@/services/settings'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import { fetchProjectLog } from '@/services/settings'

// Die Werte landen per Mustache im Text, Vue escaped selbst.
const RAW = { escape: false }
const ACTOR_SYSTEM = 'system'
// Spiegeln ProjectLog::VIA_CONSOLE und ProjectLog::REASON_ACCOUNT_DELETED.
const VIA_CONSOLE = 'occ'
const REASON_ACCOUNT_DELETED = 'account_deleted'

export default defineComponent({
	name: 'ProjectLogList',

	components: { NcAvatar, NcButton, NcDateTime },

	props: {
		boardId: { type: Number, required: true },
	},

	data() {
		return {
			entries: [] as ProjectLogEntry[],
			loading: false,
			failed: false,
			more: false,
			ACTOR_SYSTEM,
			VIA_CONSOLE,
		}
	},

	watch: {
		boardId() {
			this.entries = []
			this.more = false
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		async load(): Promise<void> {
			this.loading = true
			try {
				const boardId = this.boardId
				const seite = await fetchProjectLog(boardId, this.entries.at(-1)?.id)
				if (boardId !== this.boardId) {
					return
				}
				this.entries = [...this.entries, ...seite.entries]
				this.more = seite.hasMore
				this.failed = false
			} catch {
				this.failed = true
			} finally {
				this.loading = false
			}
		},

		actorName(entry: ProjectLogEntry): string {
			return entry.actorUserId === ACTOR_SYSTEM ? t('projektwerk', 'System') : (entry.actorName ?? entry.actorUserId)
		},

		roleName(role: string | null): string {
			return role === 'external' ? t('projektwerk', 'Kundenseite') : t('projektwerk', 'Intern')
		},

		ownerChange(from: string, to: string | null): string {
			if (to === null) {
				return from === ''
					? t('projektwerk', 'hat das Projekt geändert')
					: t('projektwerk', 'hat {name} als Eigentümer entfernt, das Projekt hat jetzt keinen Eigentümer', { name: from }, undefined, RAW)
			}

			return from === ''
				? t('projektwerk', 'hat {to} zum Eigentümer gemacht', { to }, undefined, RAW)
				: t('projektwerk', 'hat den Eigentümer geändert: {from} → {to}', { from, to }, undefined, RAW)
		},

		describe(entry: ProjectLogEntry): string {
			const subject = entry.subject ?? ''
			const name = entry.targetName ?? entry.targetUserId ?? ''

			switch (entry.action) {
				case 'project_created':
					return t('projektwerk', 'hat das Projekt „{title}“ angelegt', { title: subject }, undefined, RAW)
				case 'project_archived':
					return t('projektwerk', 'hat das Projekt archiviert')
				case 'project_unarchived':
					return t('projektwerk', 'hat das Projekt aus dem Archiv geholt')
				case 'board_created':
					return t('projektwerk', 'hat das Board „{title}“ angelegt', { title: subject }, undefined, RAW)
				case 'board_archived':
					return t('projektwerk', 'hat das Board „{title}“ archiviert', { title: subject }, undefined, RAW)
				case 'board_unarchived':
					return t('projektwerk', 'hat das Board „{title}“ aus dem Archiv geholt', { title: subject }, undefined, RAW)
				case 'board_deleted':
					return t('projektwerk', 'hat das Board „{title}“ gelöscht', { title: subject }, undefined, RAW)
				case 'member_added':
					return t('projektwerk', 'hat {name} hinzugefügt ({role})', { name, role: this.roleName(entry.newValue) }, undefined, RAW)
				case 'member_removed':
					return entry.newValue === REASON_ACCOUNT_DELETED
						? t('projektwerk', 'hat {name} aus dem Projekt entfernt, weil das Konto gelöscht wurde', { name }, undefined, RAW)
						: t('projektwerk', 'hat {name} aus dem Projekt entfernt', { name }, undefined, RAW)
				case 'owner_changed':
					return this.ownerChange(name, entry.newValue === null ? null : (entry.newName ?? entry.newValue))
				case 'role_changed':
					return t('projektwerk', 'hat die Rolle von {name} geändert: {from} → {to}', { name, from: this.roleName(entry.oldValue), to: this.roleName(entry.newValue) }, undefined, RAW)
				case 'manager_granted':
					return t('projektwerk', 'hat {name} das Verwaltungsrecht gegeben', { name }, undefined, RAW)
				case 'manager_revoked':
					return t('projektwerk', 'hat {name} das Verwaltungsrecht entzogen', { name }, undefined, RAW)
				default:
					return t('projektwerk', 'hat das Projekt geändert')
			}
		},
	},
})
</script>
