<template>
	<section v-if="mayChange" class="pw-detail__section pw-viscontrol">
		<h3 class="pw-col__head">
			{{ t('projektwerk', 'Sichtbarkeit') }}
		</h3>

		<!-- Ruhezustand: was gilt, und ein Weg, es zu ändern. -->
		<div v-if="stage === 'idle'" class="pw-viscontrol__now">
			<span class="pw-vis" :class="'pw-vis--' + ticket.visibility">
				<AccountMultipleIcon v-if="ticket.visibility === 'public'" :size="14" />
				<OfficeBuildingIcon v-else-if="ticket.visibility === 'internal'" :size="14" />
				<PencilIcon v-else :size="14" />
				{{ currentLabel }}
			</span>

			<!--
				Der Widerruf steht hier und nicht in einer Meldung: §10 verlangt
				„kurz widerrufbar", und `OCP.Toast` kennt kein `showUndo` — es
				hat nur success/warning/error/info/message. Eine Meldung mit
				verstecktem Klickziel wäre ein Widerruf, den niemand findet.
			-->
			<NcButton v-if="undoTo !== null" variant="tertiary" @click="undo">
				<template #icon>
					<UndoIcon :size="20" />
				</template>
				{{ t('projektwerk', 'Rückgängig') }}
			</NcButton>

			<NcButton variant="tertiary" @click="startChoosing">
				{{ t('projektwerk', 'Ändern') }}
			</NcButton>
		</div>

		<!-- Erste Stufe: auswählen. -->
		<div v-else-if="stage === 'choosing'" class="pw-viscontrol__edit">
			<VisibilityChoice
				v-model="chosen"
				:unavailable="unavailable"
				:blockedHint="blockedHint" />

			<div class="pw-viscontrol__actions">
				<NcButton @click="reset">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || chosen === ticket.visibility" @click="check">
					{{ t('projektwerk', 'Übernehmen') }}
				</NcButton>
			</div>
		</div>

		<!--
			Zweite Stufe: die Rückfrage. §9 verlangt konkrete Zahlen und Namen
			statt einer allgemeinen Warnung — eine Warnung ohne Namen liest man
			zweimal und danach nie wieder.
		-->
		<div v-else-if="stage === 'confirming'" class="pw-viscontrol__warn">
			<p class="pw-viscontrol__lead">
				<AlertIcon :size="20" />
				{{ losingLead }}
			</p>

			<ul class="pw-viscontrol__losing">
				<li v-for="userId in impact.losing" :key="userId">
					{{ nameOf(userId) }}
				</li>
			</ul>

			<p class="pw-viscontrol__note">
				{{ t('projektwerk', 'Die Beteiligten werden nicht benachrichtigt.') }}
			</p>

			<div class="pw-viscontrol__actions">
				<NcButton @click="reset">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy" @click="save">
					{{ t('projektwerk', 'Sichtbarkeit ändern') }}
				</NcButton>
			</div>
		</div>
	</section>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Member, ViewerInfo, Visibility } from '@/types/board'
import type { Ticket } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import UndoIcon from 'vue-material-design-icons/UndoVariant.vue'
import VisibilityChoice from '@/components/VisibilityChoice.vue'
import { changeVisibility, fetchVisibilityImpact } from '@/services/tickets'
import { showError } from '@/services/toast'

interface Impact {
	losing: string[]
	comments: number
	attachments: number
}

/** Wie lange der Widerruf nach einem folgenlosen Wechsel stehen bleibt. */
const UNDO_WINDOW_MS = 15000

/**
 * Die Sichtbarkeit eines bestehenden Vorgangs ändern.
 *
 * Zwei Stufen, kein zweiter Dialog: Das Ticket-Detail ist bereits ein
 * `NcModal`, und ein `NcDialog` darin legte zwei Fokusfallen übereinander. Die
 * Rückfrage ersetzt deshalb die Auswahl an Ort und Stelle.
 *
 * **Ob überhaupt zurückgefragt wird, entscheidet der Server.** Das Frontend
 * fragt vor jedem Wechsel `visibility-impact` und richtet sich nach `losing`:
 * leer heißt, niemand verliert etwas (§10: „Hochstufen ohne Rückfrage"), nicht
 * leer heißt Rückfrage. Damit muss hier niemand wissen, welche Stufe „unter"
 * welcher liegt — sonst stünde die Sichtbarkeitsregel ein zweites Mal im Code,
 * und die zweite Fassung wäre die, die niemand prüft.
 */
export default defineComponent({
	name: 'VisibilityControl',

	components: {
		AccountMultipleIcon,
		AlertIcon,
		NcButton,
		OfficeBuildingIcon,
		PencilIcon,
		UndoIcon,
		VisibilityChoice,
	},

	props: {
		ticket: { type: Object as PropType<Ticket>, required: true },
		viewer: { type: Object as PropType<ViewerInfo | null>, default: null },
		/** Nur zur Anzeige der Namen — die Rechnung selbst macht der Server. */
		members: { type: Array as PropType<Member[]>, default: () => [] },
	},

	emits: ['changed'],

	data() {
		return {
			stage: 'idle' as 'idle' | 'choosing' | 'confirming',
			chosen: 'public' as Visibility,
			impact: { losing: [], comments: 0, attachments: 0 } as Impact,
			busy: false,
			/** Der Stand vor dem letzten folgenlosen Wechsel, solange widerrufbar. */
			undoTo: null as Visibility | null,
			undoTimer: null as ReturnType<typeof setTimeout> | null,
		}
	},

	computed: {
		/**
		 * §7, wörtlich: „Ändern darf die Sichtbarkeit nur die Seite, der das
		 * Ticket gehört."
		 *
		 * Das ist die **Schreib**regel, nicht die Sichtbarkeitsregel — deshalb
		 * darf sie hier stehen. Sie entscheidet nur, ob ein Knopf angeboten
		 * wird; abgewiesen wird weiterhin auf dem Server, und der 403 landet
		 * unten in der Fehlerbehandlung.
		 *
		 * Bewusst **nicht** an die Kennzeichnung gekoppelt: Die zeigt §9 nur
		 * internen Betrachtern. Wäre der Knopf daran gebunden, könnte die
		 * Kundenseite die Sichtbarkeit ihrer eigenen Vorgänge nie ändern.
		 */
		mayChange(): boolean {
			return this.viewer !== null && this.ticket.creatorRole === this.viewer.role
		},

		/** §7: Auf „Nur ich" herunterstufen kann allein die anlegende Person. */
		unavailable(): Visibility[] {
			return this.viewer !== null && this.ticket.creatorUserId === this.viewer.userId
				? []
				: ['private']
		},

		currentLabel(): string {
			return this.labelFor(this.ticket.visibility)
		},

		/**
		 * Warum „Nur ich" hier nicht wählbar ist.
		 *
		 * Steht im Skript und nicht als Literal im Aufruf, weil der Text ein
		 * Anführungszeichen trägt — im Attribut würde es die Zeichenkette der
		 * Vorlage beenden.
		 */
		blockedHint(): string {
			return t('projektwerk', 'Auf „Nur ich" herunterstufen kann nur die anlegende Person')
		},

		/**
		 * Der Satz aus §9, mit eingesetzten Zahlen.
		 *
		 * Ein einziges Literal je Fall statt einer Verkettung: Die
		 * Übersetzungswerkzeuge lesen die Aufrufe statisch aus und fänden einen
		 * zusammengesetzten String nicht.
		 */
		losingLead(): string {
			const comments = this.impact.comments
			const attachments = this.impact.attachments

			if (comments === 0 && attachments === 0) {
				return t('projektwerk', 'Folgende Personen verlieren den Zugriff auf diesen Vorgang:')
			}
			if (attachments === 0) {
				return t('projektwerk', 'Folgende Personen verlieren den Zugriff auf diesen Vorgang und seine {comments} Kommentare:', { comments })
			}
			if (comments === 0) {
				return t('projektwerk', 'Folgende Personen verlieren den Zugriff auf diesen Vorgang und seine {attachments} Anhänge:', { attachments })
			}
			return t('projektwerk', 'Folgende Personen verlieren den Zugriff auf diesen Vorgang, seine {comments} Kommentare und {attachments} Anhänge:', { comments, attachments })
		},
	},

	watch: {
		// Ein anderer Vorgang im selben Overlay: Auswahl, Rückfrage und ein noch
		// offener Widerruf gehören zum alten Ticket und dürfen nicht stehen
		// bleiben — ein „Rückgängig" hier setzte sonst das falsche Ticket zurück.
		'ticket.id': {
			immediate: true,
			handler() {
				this.reset()
				this.clearUndo()
			},
		},
	},

	beforeUnmount() {
		this.clearUndo()
	},

	methods: {
		t,

		/**
		 * @param visibility Die Stufe.
		 */
		labelFor(visibility: Visibility): string {
			if (visibility === 'public') {
				return t('projektwerk', 'Alle Beteiligten')
			}
			if (visibility === 'internal') {
				return t('projektwerk', 'Intern')
			}
			return t('projektwerk', 'Nur ich')
		},

		/**
		 * Der Name für dieses Board, sonst die Kennung.
		 *
		 * Reine Anzeige: Wer den Zugriff verliert, hat der Server entschieden.
		 *
		 * @param userId Kennung der Person.
		 */
		nameOf(userId: string): string {
			return this.members.find((m) => m.userId === userId)?.resolvedName ?? userId
		},

		startChoosing(): void {
			this.chosen = this.ticket.visibility
			this.stage = 'choosing'
		},

		reset(): void {
			this.stage = 'idle'
			this.impact = { losing: [], comments: 0, attachments: 0 }
		},

		clearUndo(): void {
			if (this.undoTimer !== null) {
				clearTimeout(this.undoTimer)
				this.undoTimer = null
			}
			this.undoTo = null
		},

		/**
		 * Nachfragen, was der Wechsel kostet — und danach erst entscheiden, ob
		 * überhaupt zurückgefragt wird.
		 */
		async check(): Promise<void> {
			if (this.busy || this.chosen === this.ticket.visibility) {
				return
			}

			this.busy = true
			try {
				this.impact = await fetchVisibilityImpact(this.ticket.boardId, this.ticket.id, this.chosen)
			} catch (e) {
				this.fail(e, t('projektwerk', 'Sichtbarkeit konnte nicht geprüft werden'))
				this.busy = false

				return
			}
			this.busy = false

			if (this.impact.losing.length === 0) {
				// Niemand verliert etwas: durchführen und kurz widerrufbar halten.
				await this.save()

				return
			}

			this.stage = 'confirming'
		},

		async save(): Promise<void> {
			const previous = this.ticket.visibility
			const undoable = this.impact.losing.length === 0

			this.busy = true
			try {
				const updated = await changeVisibility(
					this.ticket.boardId,
					this.ticket.id,
					this.ticket.version,
					this.chosen,
				)
				this.$emit('changed', updated)
				this.reset()

				if (undoable) {
					this.armUndo(previous)
				} else {
					this.clearUndo()
				}
			} catch (e) {
				this.fail(e, t('projektwerk', 'Sichtbarkeit konnte nicht geändert werden'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param previous Der Stand, zu dem der Widerruf zurückführt.
		 */
		armUndo(previous: Visibility): void {
			this.clearUndo()
			this.undoTo = previous
			this.undoTimer = setTimeout(() => this.clearUndo(), UNDO_WINDOW_MS)
		},

		/**
		 * Zurück auf den Stand von eben.
		 *
		 * Ohne Rückfrage, obwohl es der Richtung nach ein Herunterstufen sein
		 * kann: Der Widerruf stellt den Zustand wieder her, den die Person
		 * Sekunden zuvor selbst hatte. Eine Rückfrage nach Namen, die genau
		 * dadurch wieder ausgesperrt werden, wäre eine Warnung vor dem
		 * Rückgängigmachen einer Warnung.
		 */
		async undo(): Promise<void> {
			const target = this.undoTo
			if (target === null || this.busy) {
				return
			}

			this.busy = true
			try {
				const updated = await changeVisibility(
					this.ticket.boardId,
					this.ticket.id,
					this.ticket.version,
					target,
				)
				this.$emit('changed', updated)
				this.clearUndo()
			} catch (e) {
				this.fail(e, t('projektwerk', 'Rückgängig machen fehlgeschlagen'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param e Was der Aufruf geworfen hat.
		 * @param fallback Meldung, wenn der Server keine eigene mitgibt.
		 */
		fail(e: unknown, fallback: string): void {
			const error = e as { status?: number, message?: string }

			// 409 heißt: jemand anders war schneller. Der Stand im Overlay ist
			// veraltet, und ein zweiter Versuch mit derselben `version` scheiterte
			// genauso — deshalb der Hinweis aufs Neuladen statt einer Wiederholung.
			if (error.status === 409) {
				showError(t('projektwerk', 'Der Vorgang wurde zwischenzeitlich geändert. Bitte neu laden.'))
				this.reset()

				return
			}

			showError(error.message ?? fallback)
		},
	},
})
</script>
