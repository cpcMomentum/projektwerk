<template>
	<section class="pw-abschnitt">
		<div class="pw-abschnitt__kopf">
			<h3>{{ t('projektwerk', 'Kommentare') }}</h3>
			<span v-if="comments.length > 0" class="pw-abschnitt__zaehler">{{ comments.length }}</span>
		</div>

		<article
			v-for="comment in comments"
			:key="comment.id"
			class="pw-comment"
			:data-comment-id="comment.id">
			<NcAvatar
				:user="comment.authorUserId"
				:displayName="nameOf(comment.authorUserId)"
				:size="32"
				:disableMenu="true"
				:hideStatus="true" />

			<div class="pw-comment__body">
				<header class="pw-comment__head">
					<span class="pw-comment__name">{{ nameOf(comment.authorUserId) }}</span>
					<NcDateTime
						v-if="comment.createdAt"
						class="pw-comment__time"
						:timestamp="new Date(comment.createdAt)"
						relativeTime="short" />
					<!--
						Nur wenn wirklich nachtraeglich geaendert: Beim Anlegen
						setzt der Server beide Zeitpunkte gleich, damit genau
						diese Unterscheidung ohne zweites Feld moeglich ist.
					-->
					<span v-if="edited(comment)" class="pw-comment__edited">
						{{ t('projektwerk', 'bearbeitet') }}
					</span>
				</header>

				<!--
					Der Text als Markdown, aber `interactive` bleibt aus: Sonst
					baute NcRichText aus einem hineinkopierten Dateilink eine
					Vorschaukachel. Die App verwaltet ihre eigenen Anhaenge —
					einen hingeschriebenen Link verwaltet sie nicht, und sie soll
					auch nicht so tun.

					`useExtendedMarkdown` schaltet `remark-gfm` dazu. Ohne das
					landet eine eingefuegte Tabelle als Reihe von Strichen im
					Text — Markdown, das nicht rendert, ist schlechter als kein
					Markdown, weil niemand sieht, woran es lag. Die
					Syntaxhervorhebung kommt mit derselben Fahne und wird
					dynamisch nachgeladen, kostet also nichts, solange niemand
					einen Codeblock schreibt.
				-->
				<NcRichText
					v-if="editing !== comment.id"
					class="pw-comment__text"
					:text="comment.body"
					:useMarkdown="true"
					:useExtendedMarkdown="true"
					:interactive="false" />

				<!--
					Geaendert wird an Ort und Stelle, nicht im Dialog: Das
					Ticket-Detail ist bereits ein NcModal, ein NcDialog darin
					legte zwei Fokusfallen uebereinander (dieselbe Ueberlegung
					wie in VisibilityControl).
				-->
				<div v-else class="pw-comment__edit">
					<NcTextArea
						v-model="draft"
						:label="t('projektwerk', 'Kommentar ändern')"
						:disabled="busy"
						resize="vertical" />
					<div class="pw-comment__actions">
						<NcButton :disabled="busy" @click="cancel">
							{{ t('projektwerk', 'Abbrechen') }}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="busy || draft.trim() === ''"
							@click="saveEdit(comment)">
							{{ t('projektwerk', 'Speichern') }}
						</NcButton>
					</div>
				</div>

				<!--
					Aendern und Loeschen stehen nur am eigenen Beitrag. Der
					Server lehnt fremde ohnehin mit 403 ab — hier geht es darum,
					gar nicht erst anzubieten, was er danach verweigert.
				-->
				<div v-if="isMine(comment) && editing !== comment.id && removing !== comment.id" class="pw-comment__actions">
					<NcButton variant="tertiary" :disabled="busy" @click="startEdit(comment)">
						{{ t('projektwerk', 'Ändern') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="busy" @click="removing = comment.id">
						{{ t('projektwerk', 'Löschen') }}
					</NcButton>
				</div>

				<div v-if="removing === comment.id" class="pw-comment__confirm">
					<p>{{ t('projektwerk', 'Dieser Kommentar wird endgültig entfernt. Es gibt keinen Papierkorb.') }}</p>
					<div class="pw-comment__actions">
						<NcButton :disabled="busy" @click="removing = null">
							{{ t('projektwerk', 'Abbrechen') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="remove(comment)">
							{{ t('projektwerk', 'Löschen') }}
						</NcButton>
					</div>
				</div>
			</div>
		</article>

		<p v-if="comments.length === 0" class="pw-detail__empty">
			{{ t('projektwerk', 'Noch keine Kommentare.') }}
		</p>

		<!--
			Eigene Klasse statt `pw-comment pw-comment--new`: Die Eingabe ist kein
			Kommentar, sie sieht nur darunter aus. Als Modifikator musste sie das
			`display: flex` der Karte wieder ausschalten — und jeder Test, der
			„die Kommentare" zaehlt, haette sie mitgezaehlt.
		-->
		<!--
			**Das Feld ruht zweizeilig** und waechst erst beim Hineinklicken
			(#99); die Knopfzeile erscheint mit ihm. Vorher standen 86 px
			Eingabeflaeche und ein Knopf dauerhaft unter jedem Vorgang, auch unter
			denen, in die nie jemand schreibt.

			Kein Fallstrick beim Verlassen: Der Knopf verschwindet nur, solange
			das Feld leer ist — und dann war er ohnehin gesperrt, ein Klick
			darauf haette nichts getan.
		-->
		<div
			class="pw-comment-new"
			:class="{ 'pw-comment-new--aktiv': fokusImFeld || newBody !== '' }"
			@focusin="fokusImFeld = true"
			@focusout="fokusImFeld = false">
			<NcTextArea
				v-model="newBody"
				:label="t('projektwerk', 'Neuer Kommentar')"
				:disabled="busy"
				resize="vertical" />
			<div v-if="fokusImFeld || newBody !== ''" class="pw-comment__actions">
				<NcButton
					variant="primary"
					:disabled="busy || newBody.trim() === ''"
					@click="add">
					{{ t('projektwerk', 'Kommentieren') }}
				</NcButton>
			</div>
		</div>
	</section>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Member, ViewerInfo } from '@/types/board'
import type { Comment } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { createComment, deleteComment, updateComment } from '@/services/comments'
import { showError } from '@/services/toast'

/**
 * Der Kommentarverlauf eines Vorgangs.
 *
 * **Die Reihenfolge kommt vom Server** — älteste zuerst, ein Gespräch und keine
 * Liste. Hier wird nicht nachsortiert; die Sortierung steht im `CommentMapper`
 * an einer Stelle.
 *
 * **Kommentare haben keine eigene Sichtbarkeit.** Sie erben sie vollständig vom
 * Ticket, deshalb steht in dieser Datei keine einzige Bedingung darauf. Wer den
 * Vorgang sieht, sieht seine Kommentare — und wer ihn nicht sieht, bekommt gar
 * nicht erst dieses Overlay.
 *
 * Was hier **nicht** passiert: Ein Link im Text wird nicht geprüft. Ob eine
 * verlinkte Datei aufgeht, entscheiden Nextclouds Freigaben, nicht die
 * Sichtbarkeit des Vorgangs. Das ist der bewusst offengelegte Punkt aus dem
 * Akzeptanzkriterium zu #9 und keine Lücke, die hier zu schließen wäre — ein
 * Filter im Browser wäre eine zweite Sichtbarkeitsregel, und umgehbar.
 */
export default defineComponent({
	name: 'CommentList',

	components: { NcAvatar, NcButton, NcDateTime, NcRichText, NcTextArea },

	props: {
		boardId: { type: Number, required: true },
		ticketId: { type: Number, required: true },
		comments: { type: Array as PropType<Comment[]>, default: () => [] },
		/** Nur zur Anzeige der Namen. */
		members: { type: Array as PropType<Member[]>, default: () => [] },
		/** Wer gerade schaut — entscheidet, wo Ändern und Löschen erscheinen. */
		viewer: { type: Object as PropType<ViewerInfo | null>, default: null },
	},

	emits: ['changed'],

	data() {
		return {
			busy: false,
			newBody: '',
			/**
			 * Der Fokus steht im neuen Kommentarfeld oder auf seinem Knopf.
			 *
			 * `focusin`/`focusout` am Umschlag statt `focus` am Feld: Nur so
			 * bleibt die Knopfzeile stehen, waehrend man vom Textfeld auf den
			 * Knopf tabbt.
			 */
			fokusImFeld: false,
			/** Kennung des Kommentars, der gerade geändert wird. */
			editing: null as number | null,
			/** Kennung des Kommentars, für den die Rückfrage steht. */
			removing: null as number | null,
			draft: '',
			/**
			 * Wohin der Fokus nach einem Schreibvorgang gehört.
			 *
			 * CSS-Auswahl statt `ref`, weil das Ziel den Neuaufbau überleben
			 * muss: Der Elternteil lädt nach jedem Schreiben neu, und die
			 * Elemente, auf die ein `ref` zeigte, sind danach andere.
			 */
			fokusZiel: null as string | null,
		}
	},

	watch: {
		// Beim Wechsel des Vorgangs alles Angefangene fallen lassen: Sonst
		// stünde der Entwurf zum Kommentar des vorigen Tickets unter dem neuen.
		ticketId() {
			this.newBody = ''
			this.cancel()
			this.removing = null
			this.fokusZiel = null
		},

		/**
		 * Den Fokus nach dem Neuaufbau der Liste wieder setzen.
		 *
		 * **Warum das nötig ist.** Wer über die Tastatur schreibt, drückt auf
		 * „Kommentieren" — und genau dann leert sich das Feld, der Knopf wird
		 * dadurch deaktiviert und nimmt den Fokus mit auf den `body`. Danach
		 * fängt man das Tabben von vorn an. Dieselbe Falle wie beim Ausklappen
		 * der älteren Erledigten in `BoardView`; Tastatur und Screenreader sind
		 * Abnahmekriterium, nicht Nachrüstung.
		 *
		 * Der Elternteil lädt nach jedem Schreiben neu und ersetzt die Liste.
		 * Deshalb hängt die Wiederherstellung an dieser Ersetzung und nicht am
		 * Schreibaufruf — vorher stünde das Ziel noch gar nicht im Dokument.
		 */
		comments() {
			const ziel = this.fokusZiel
			if (ziel === null) {
				return
			}
			this.fokusZiel = null
			this.$nextTick(() => {
				const el = this.$el?.querySelector?.(ziel)
				if (el instanceof HTMLElement) {
					el.focus()
				}
			})
		},
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
		 * @param comment Der Kommentar.
		 */
		isMine(comment: Comment): boolean {
			return this.viewer !== null && comment.authorUserId === this.viewer.userId
		},

		/**
		 * @param comment Der Kommentar.
		 */
		edited(comment: Comment): boolean {
			return comment.updatedAt !== null
				&& comment.createdAt !== null
				&& comment.updatedAt !== comment.createdAt
		},

		/**
		 * @param comment Der Kommentar.
		 */
		startEdit(comment: Comment) {
			this.editing = comment.id
			this.draft = comment.body
			this.removing = null
		},

		cancel() {
			this.editing = null
			this.draft = ''
		},

		/**
		 * @param run Der Schreibaufruf.
		 * @param fallback Meldung, wenn der Server keine eigene mitgibt.
		 */
		async write(run: () => Promise<unknown>, fallback: string): Promise<void> {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				await run()
				this.$emit('changed')
			} catch (e) {
				showError((e as { message?: string }).message ?? fallback)
			} finally {
				this.busy = false
			}
		},

		add() {
			const body = this.newBody.trim()
			if (body === '') {
				return
			}

			return this.write(
				async () => {
					await createComment(this.boardId, this.ticketId, body)
					this.newBody = ''
					// Zurück ins Eingabefeld, nicht auf den `body`: Wer eben
					// geschrieben hat, schreibt oft gleich weiter.
					this.fokusZiel = '.pw-comment-new textarea'
				},
				t('projektwerk', 'Kommentar konnte nicht gespeichert werden'),
			)
		},

		/**
		 * @param comment Der Kommentar.
		 */
		saveEdit(comment: Comment) {
			const body = this.draft.trim()
			if (body === '') {
				return
			}

			return this.write(
				async () => {
					await updateComment(this.boardId, comment.id, body)
					this.cancel()
					// Zurück auf „Ändern" desselben Kommentars — dort stand der
					// Fokus, bevor das Eingabefeld ihn übernahm.
					this.fokusZiel = `[data-comment-id="${comment.id}"] .pw-comment__actions button`
				},
				t('projektwerk', 'Ändern fehlgeschlagen'),
			)
		},

		/**
		 * @param comment Der Kommentar.
		 */
		remove(comment: Comment) {
			return this.write(
				async () => {
					await deleteComment(this.boardId, comment.id)
					this.removing = null
					// Der Kommentar, auf dem der Fokus stand, ist weg. Das
					// Eingabefeld ist die nächstliegende Stelle, die bleibt.
					this.fokusZiel = '.pw-comment-new textarea'
				},
				t('projektwerk', 'Löschen fehlgeschlagen'),
			)
		},
	},
})
</script>
