<template>
	<NcDialog
		:open="open"
		:name="t('projektwerk', 'Neuer Vorgang')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<!--
			Die App-Klasse MUSS hier drin stehen: NcDialog teleportiert seinen
			Inhalt an den `body`, wo `.app-projektwerk` kein Vorfahr mehr ist.
			Ohne sie steht die Sichtbarkeitszeile aus §9 unformatiert da, und die
			Klickflaechen fallen unter `--default-clickable-area`.
		-->
		<div class="app-projektwerk">
			<div class="pw-field">
				<label for="pw-new-title">{{ t('projektwerk', 'Titel') }}</label>
				<NcTextField
					id="pw-new-title"
					v-model="title"
					:label="t('projektwerk', 'Titel')" />
			</div>

			<div class="pw-field">
				<label for="pw-new-desc">{{ t('projektwerk', 'Beschreibung') }}</label>
				<textarea id="pw-new-desc" v-model="description" rows="4" />
			</div>

			<!--
				Die Sichtbarkeitszeile steht neben Titel und Beschreibung, ist NIE
				eingeklappt und nie hinter einem Zahnrad (§9). Voreinstellung „Alle
				Beteiligten", fuer alle Rollen gleich.
			-->
			<div class="pw-field">
				<label>{{ t('projektwerk', 'Wer sieht diesen Vorgang?') }}</label>
				<VisibilityChoice v-model="visibility" />
			</div>

			<!--
				Die/der Zuständige gleich beim Anlegen (#146). Die Auswahl folgt der
				gewählten Sichtbarkeit — bei einem öffentlichen Vorgang alle
				Mitglieder, bei einem internen nur die eigene Seite. Deshalb kommt
				sie vom Server (`assignable-new`) und wird bei jedem
				Sichtbarkeitswechsel neu geholt; im Browser zu filtern wäre eine
				zweite Fassung der Sichtbarkeitsregel. Voreinstellung „Niemand".
			-->
			<div class="pw-field">
				<label :for="responsibleInputId">{{ t('projektwerk', 'Zuständige Person') }}</label>
				<NcSelectUsers
					:options="assignableOptions"
					:modelValue="responsibleOption"
					:inputId="responsibleInputId"
					:labelOutside="true"
					:placeholder="t('projektwerk', 'Niemand')"
					@update:modelValue="setResponsible" />
			</div>

			<div class="pw-field">
				<label for="pw-new-col">{{ t('projektwerk', 'Spalte') }}</label>
				<select id="pw-new-col" v-model="columnId">
					<option v-for="column in columns" :key="column.id" :value="column.id">
						{{ column.title }}
					</option>
				</select>
			</div>

			<!--
				Die Fälligkeit gleich beim Anlegen, nicht erst danach (#72) — wie
				Bearbeiter und Frist am Schritt (#86). „Bis wann ist die Sache
				fertig", die Zusage an die Gegenseite. Optional: leer heißt keine
				Frist.
			-->
			<div class="pw-field">
				<label for="pw-new-due">{{ t('projektwerk', 'Fällig bis') }}</label>
				<NcDateTimePicker
					id="pw-new-due"
					v-model="dueDate"
					type="date"
					:clearable="true"
					:appendToBody="true"
					:ariaLabel="t('projektwerk', 'Fällig bis')"
					:placeholder="t('projektwerk', 'Keine Frist')" />
			</div>
		</div>

		<!--
			Zwei Abschlüsse (#165). Anhänge und Arbeitsschritte brauchen einen
			bereits gespeicherten Vorgang, deshalb bleibt der Dialog schlank (#100)
			und das Ausbauen geschieht im Detail. Ob man nach dem Anlegen gleich
			dorthin springt, ist jetzt eine bewusste Wahl statt eines Automatismus:

			- „Anlegen" (primär, der erwartbare Default) legt an und lässt einen
			  auf dem Board — die neue Karte wird dort kurz hervorgehoben.
			- „Anlegen und öffnen" legt an und springt direkt ins Detail (der
			  bisherige #146-Weg), für „jetzt gleich Schritte/Anhänge dran".
		-->
		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('projektwerk', 'Abbrechen') }}
			</NcButton>
			<NcButton :disabled="!canSave" @click="save(true)">
				{{ t('projektwerk', 'Anlegen und öffnen') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave" @click="save(false)">
				{{ t('projektwerk', 'Anlegen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Column, Member, Visibility } from '@/types/board'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import VisibilityChoice from '@/components/VisibilityChoice.vue'
import { fetchAssignableForNew } from '@/services/steps'
import { toIsoDay } from '@/utils/date'

/** Die Form, die `NcSelectUsers` je Option erwartet — wie im Detail-Picker. */
interface PersonOption {
	id: string
	displayName: string
	user: string
	subname?: string
}

export default defineComponent({
	name: 'CreateTicketDialog',

	components: { NcButton, NcDateTimePicker, NcDialog, NcSelectUsers, NcTextField, VisibilityChoice },

	props: {
		open: { type: Boolean, default: false },
		boardId: { type: Number, required: true },
		columns: { type: Array as PropType<Column[]>, default: () => [] },
		members: { type: Array as PropType<Member[]>, default: () => [] },
		orgInternal: { type: String, default: '' },
		orgExternal: { type: String, default: '' },
	},

	emits: ['update:open', 'create'],

	data() {
		return {
			title: '',
			description: '',
			// „Alle Beteiligten" ist die Voreinstellung, fuer alle Rollen gleich (§9).
			visibility: 'public' as Visibility,
			columnId: null as number | null,
			dueDate: null as Date | null,
			// Wer bei der aktuellen Sichtbarkeit zuständig sein darf — vom Server.
			responsibleUserId: null as string | null,
			assignable: [] as string[],
			// Zaehlt jeden Abruf durch, damit eine spaet eintreffende Antwort auf
			// eine bereits verlassene Sichtbarkeit die aktuelle nicht ueberschreibt.
			assignableAbruf: 0,
		}
	},

	computed: {
		canSave(): boolean {
			return this.title.trim() !== '' && this.columnId !== null
		},

		responsibleInputId(): string {
			return 'pw-new-responsible'
		},

		/** Die Auswahlliste, wie `NcSelectUsers` sie erwartet — Namen aus den Mitgliedern. */
		assignableOptions(): PersonOption[] {
			return this.assignable.map((userId) => ({
				id: userId,
				displayName: this.nameOf(userId),
				user: userId,
				subname: this.roleOf(userId) === 'internal' ? this.orgInternal : this.orgExternal,
			}))
		},

		responsibleOption(): PersonOption | null {
			if (this.responsibleUserId === null) {
				return null
			}

			return this.assignableOptions.find((o) => o.id === this.responsibleUserId) ?? null
		},
	},

	watch: {
		open(isOpen: boolean) {
			if (isOpen) {
				this.title = ''
				this.description = ''
				this.visibility = 'public'
				this.columnId = this.columns[0]?.id ?? null
				this.dueDate = null
				this.responsibleUserId = null
				this.loadAssignable()
			}
		},

		// Wechselt die Sichtbarkeit, ändert sich die zuweisbare Menge. Wer nicht
		// mehr dazugehört, fällt raus — sonst böte der Dialog jemanden an, den der
		// Server beim Anlegen ablehnte.
		visibility() {
			if (this.open) {
				this.loadAssignable()
			}
		},
	},

	methods: {
		t,

		nameOf(userId: string): string {
			return this.members.find((m) => m.userId === userId)?.resolvedName ?? userId
		},

		roleOf(userId: string): string {
			return this.members.find((m) => m.userId === userId)?.role ?? 'external'
		},

		async loadAssignable() {
			const abruf = ++this.assignableAbruf

			let assignable: string[]
			try {
				assignable = await fetchAssignableForNew(this.boardId, this.visibility)
			} catch {
				assignable = []
			}

			// Zwischenzeitlich kam ein neuerer Abruf dazwischen (Sichtbarkeit erneut
			// gewechselt) — dessen Antwort gilt, nicht diese veraltete.
			if (abruf !== this.assignableAbruf) {
				return
			}

			this.assignable = assignable
			if (this.responsibleUserId !== null && !this.assignable.includes(this.responsibleUserId)) {
				this.responsibleUserId = null
			}
		},

		setResponsible(option: PersonOption | PersonOption[] | null) {
			const gewaehlt = Array.isArray(option) ? (option[0] ?? null) : option
			this.responsibleUserId = gewaehlt?.id ?? null
		},

		/**
		 * @param openAfter Nach dem Anlegen direkt ins Detail springen (#165)?
		 *   „Anlegen und öffnen" ruft mit `true`, „Anlegen" mit `false`.
		 */
		save(openAfter: boolean) {
			if (!this.canSave) {
				return
			}
			this.$emit('create', {
				title: this.title.trim(),
				description: this.description.trim() === '' ? null : this.description.trim(),
				visibility: this.visibility,
				columnId: this.columnId as number,
				dueDate: toIsoDay(this.dueDate),
				responsibleUserId: this.responsibleUserId,
				openAfter,
			})
		},
	},
})
</script>
