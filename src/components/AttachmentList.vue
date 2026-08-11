<template>
	<section class="pw-detail__section">
		<h3 class="pw-col__head">
			{{ t('projektwerk', 'Anhänge') }}
		</h3>

		<div v-for="file in attachments" :key="file.id" class="pw-attach">
			<!--
				Der Link zeigt auf Nextclouds Dateiansicht, nicht auf einen
				eigenen Downloadweg. Wer die Datei sehen darf, entscheidet
				Nextcloud — genau dort, wo Dateizugriff geregelt gehoert. Ein
				eigener Weg waere ein zweiter Ort, an dem die Regel stimmen
				muesste.
			-->
			<a
				class="pw-attach__name"
				:href="fileUrl(file)"
				target="_blank"
				rel="noopener noreferrer">
				{{ file.fileName }}
			</a>

			<span class="pw-attach__meta">{{ metaFor(file) }}</span>

			<NcButton
				variant="tertiary"
				:disabled="busy"
				:ariaLabel="t('projektwerk', 'Anhang lösen: {name}', { name: file.fileName })"
				@click="ask(file)">
				<template #icon>
					<LinkOffIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<p v-if="attachments.length === 0" class="pw-detail__empty">
			{{ t('projektwerk', 'Noch keine Anhänge.') }}
		</p>

		<!--
			Ein verstecktes Dateifeld hinter einem eigenen Knopf: Das
			Plattform-Feld sieht in jedem Browser anders aus und traegt eine
			englische Beschriftung, die sich nicht aendern laesst.
		-->
		<input
			ref="picker"
			class="pw-attach__input"
			type="file"
			:disabled="busy"
			@change="upload">

		<NcButton :disabled="busy" @click="choose">
			{{ t('projektwerk', 'Datei anhängen') }}
		</NcButton>

		<!--
			**Der Satz nennt, was NICHT passiert.** „Entfernen" liest sich wie
			„wegraeumen", und genau das tut es nicht: Die Datei bleibt im
			Projektordner liegen, die App loescht nie (§5.18). Wer das erst
			hinterher merkt, hat entweder zu viel oder zu wenig aufgeraeumt.
		-->
		<NcDialog
			:open="removing !== null"
			:name="t('projektwerk', 'Anhang lösen?')"
			size="normal"
			@update:open="removing = null">
			<!--
				Die App-Klasse MUSS hier drin stehen: `NcDialog` teleportiert
				seinen Inhalt an den `body`, damit ist `.app-projektwerk` kein
				Vorfahr mehr, und unser gesamtes CSS ist darunter geschachtelt.
				Der Fehler bricht nichts — er entstellt nur, und genau deshalb
				faellt er ohne Waechter niemandem auf.
			-->
			<div class="app-projektwerk">
				<p v-if="removing !== null">
					{{ t('projektwerk', 'Der Anhang „{name}“ wird vom Vorgang gelöst.', { name: removing.fileName }) }}
				</p>
				<p class="pw-settings__hint">
					{{ t('projektwerk', 'Die Datei selbst bleibt liegen, wo sie liegt — diese App löscht keine Dateien.') }}
				</p>
			</div>

			<template #actions>
				<NcButton :disabled="busy" @click="removing = null">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy" @click="remove">
					{{ t('projektwerk', 'Lösen') }}
				</NcButton>
			</template>
		</NcDialog>
	</section>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Member } from '@/types/board'
import type { Attachment } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import { createAttachment, deleteAttachment } from '@/services/attachments'
import { showError } from '@/services/toast'

/**
 * Die Anhänge eines Vorgangs.
 *
 * **Der Ablageort IST die Sichtbarkeit** (§5.18): Ein Anhang liegt in dem
 * Projektordner, der zur Sichtbarkeit seines Vorgangs gehört, und wer ihn
 * öffnen darf, entscheidet Nextcloud. Diese Komponente zeigt deshalb nur
 * Namen und Verweis — sie liefert keine Datei aus und prüft keine Rechte.
 *
 * Für Vorgänge ohne Ablageort (intern von der Kundenseite, „Nur ich") lehnt
 * der Server das Anhängen ab. Der Knopf steht trotzdem da: Ihn wegzulassen
 * hieße, die Regel ein zweites Mal im Browser zu führen — und die Meldung des
 * Servers erklärt den Fall besser als ein fehlender Knopf.
 */
export default defineComponent({
	name: 'AttachmentList',

	components: { LinkOffIcon, NcButton, NcDialog },

	props: {
		boardId: { type: Number, required: true },
		ticketId: { type: Number, required: true },
		attachments: { type: Array as PropType<Attachment[]>, required: true },
		members: { type: Array as PropType<Member[]>, required: true },
	},

	emits: ['changed'],

	data() {
		return {
			busy: false,
			/** Der Anhang, über dessen Lösen gerade zurückgefragt wird. */
			removing: null as Attachment | null,
		}
	},

	methods: {
		t,

		/**
		 * @param userId Kennung der Person.
		 */
		nameOf(userId: string): string {
			return this.members.find((m) => m.userId === userId)?.resolvedName ?? userId
		},

		/**
		 * Der Weg zur Datei in Nextclouds Dateiansicht.
		 *
		 * Über die **Datei-ID**, nicht über den Pfad: Der Pfad in unserer
		 * Datenbank ist Anzeige und darf veraltet sein; die ID nicht. Nextcloud
		 * löst sie selbst auf und landet auch dann richtig, wenn der Ordner
		 * inzwischen woanders liegt.
		 *
		 * @param file Der Anhang.
		 */
		fileUrl(file: Attachment): string {
			return generateUrl('/f/{fileId}', { fileId: file.fileId })
		},

		/**
		 * @param file Der Anhang.
		 */
		metaFor(file: Attachment): string {
			return t('projektwerk', 'von {name}', { name: this.nameOf(file.uploadedBy) })
		},

		choose() {
			(this.$refs.picker as HTMLInputElement | undefined)?.click()
		},

		/**
		 * @param event Das Änderungsereignis des Dateifelds.
		 */
		async upload(event: Event): Promise<void> {
			const input = event.target as HTMLInputElement
			const file = input.files?.[0]

			if (file === undefined) {
				return
			}

			await this.write(() => createAttachment(this.boardId, this.ticketId, file))

			// **Auch im Fehlerfall zurücksetzen.** Sonst löst dieselbe Datei
			// beim zweiten Versuch kein `change` mehr aus, weil sich der Wert
			// des Feldes nicht geändert hat — und der Knopf wirkt kaputt.
			input.value = ''
		},

		/**
		 * @param file Der Anhang.
		 */
		ask(file: Attachment) {
			this.removing = file
		},

		async remove(): Promise<void> {
			const file = this.removing
			if (file === null) {
				return
			}

			await this.write(() => deleteAttachment(this.boardId, file.id))
			this.removing = null
		},

		/**
		 * @param run Der Schreibaufruf.
		 */
		async write(run: () => Promise<unknown>): Promise<void> {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				await run()
				this.$emit('changed')
			} catch (e) {
				// Die Meldung des Servers hat Vorrang — sie kennt den Fall.
				// „Für dieses Projekt ist noch kein Ordner hinterlegt" ist eine
				// Anleitung, „Fehlgeschlagen" ist keine.
				showError((e as { message?: string }).message ?? t('projektwerk', 'Der Anhang konnte nicht gespeichert werden'))
			} finally {
				this.busy = false
			}
		},
	},
})
</script>
