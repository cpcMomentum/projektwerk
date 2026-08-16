<template>
	<span
		v-if="state"
		class="pw-wait"
		:class="{ 'pw-wait--compact': compact, 'pw-wait--overdue': overdue }"
		:title="compact ? sentence : undefined">
		<!--
			**Auf der Karte tragen Kugeln die Personen, nicht Woerter.** Ein
			Avatar sagt in 18 Pixeln, wer gemeint ist; „wartet auf Kunde"
			brauchte dafuer eine halbe Zeile und nannte nur die Seite. Der
			ganze Satz steht im Titel-Attribut und im Ticket-Detail — die
			Information ist einen Handgriff entfernt, nicht verloren.
		-->
		<span v-if="compact && avatars.length > 0" class="pw-wait__who">
			<NcAvatar
				v-for="person in avatars"
				:key="person.userId"
				:user="person.userId"
				:displayName="person.name"
				:size="18"
				:disableMenu="true"
				:hideStatus="true" />
			<span v-if="more > 0" class="pw-wait__more">+{{ more }}</span>
		</span>

		<!--
			**Die Uhr ist das einzige farbige Zeichen der Zeile.** Sie steht
			beim Datum, weil „seit wann" eine Zeitangabe ist und die Personen
			davor keine sind.

			Farbig ist nur sie, nicht der Text: Ein Zeichen traegt das Signal,
			der Rest bleibt schwarz und damit gut lesbar. Und weil die Stufe an
			diesem einen Glyph haengt (#144), aendert sie die Zeile nicht:
			innerhalb der Frist eine ruhige `ClockOutline` in gedaempfter Farbe,
			bei Verzug die kraeftige `ClockAlertOutline` in Warnfarbe.

			**Das Wort „seit" bleibt stehen.** Ein nacktes „06.08." wird
			zweideutig, sobald Vorgaenge ein Faelligkeitsdatum bekommen: Dann
			steht dieselbe Form fuer „wartet seit" und „faellig am", also fuer
			zwei entgegengesetzte Aussagen.
		-->
		<span v-if="compact" class="pw-wait__since">
			<component :is="clockIcon" class="pw-wait__clock" :size="13" />
			{{ t('projektwerk', 'seit {date}', { date: formattedSince }) }}
		</span>

		<template v-else>
			<component :is="clockIcon" class="pw-wait__clock" :size="16" />
			{{ sentence }}
		</template>
	</span>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { WaitState } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import ClockAlertIcon from 'vue-material-design-icons/ClockAlertOutline.vue'
import ClockIcon from 'vue-material-design-icons/ClockOutline.vue'

/** Mehr Kugeln als das passen nicht auf eine Karte, ohne sie zu sprengen. */
const MAX_AVATARS = 2

/**
 * „Wartet auf …" — die Marke, die das Kernversprechen sichtbar macht.
 *
 * **Auf der Karte unten, im Detail als Satz.** Sie stand bis zum 2026-08-10
 * über dem Titel und war ein eigener Block; auf einer Karte, die einen Blick
 * verträgt, war das eine Zeile zu viel. Jetzt gilt: Oben steht, was dauerhaft
 * ist (Nummer, Sichtbarkeit, Zuständigkeit), unten, was gerade der Fall ist.
 *
 * Dass sie nach unten darf, hängt an der **Uhr**: Ein farbiges Symbol findet
 * das Auge beim Überfliegen auch dort, ein Satz nicht. Text hätte oben bleiben
 * müssen.
 *
 * **In der Kundenansicht heißt sie anders.** „Wartet auf Kunde" wäre dort eine
 * Beschreibung von außen; wer selbst gemeint ist, liest „wartet auf euch".
 */
export default defineComponent({
	name: 'WaitBadge',

	components: { ClockAlertIcon, ClockIcon, NcAvatar },

	props: {
		state: { type: Object as PropType<WaitState | null>, default: null },
		/** Aus Sicht der Kundenseite formuliert. */
		fromClientSide: { type: Boolean, default: false },
		/** Kleinere Fassung für die Karte. */
		compact: { type: Boolean, default: false },
		/**
		 * Anzeigename je Benutzerkennung.
		 *
		 * Kommt von aussen, weil der Server ihn auflöst (`resolvedName`) — im
		 * Browser nachzuschlagen bliebe ausgerechnet beim Gast stumm.
		 */
		names: { type: Object as PropType<Record<string, string>>, default: () => ({}) },
	},

	computed: {
		/**
		 * Ist die Fälligkeit (#72) gerissen? Kommt fertig gerechnet aus dem
		 * Backend (`WaitStateCalculator`), damit Karte und Detail dieselbe Stufe
		 * lesen und die Regel an genau einer Stelle steht.
		 */
		overdue(): boolean {
			return this.state?.overdue ?? false
		},

		/**
		 * Ruhig eine `ClockOutline`, bei Verzug die kräftige `ClockAlertOutline`
		 * (#144). Die Zeile behält ihre Form; nur der Glyph wechselt.
		 */
		clockIcon(): string {
			return this.overdue ? 'ClockAlertIcon' : 'ClockIcon'
		},

		/** Die ersten Kugeln, mehr passen nicht. */
		avatars(): { userId: string, name: string }[] {
			return (this.state?.userIds ?? [])
				.slice(0, MAX_AVATARS)
				.map((userId) => ({ userId, name: this.names[userId] ?? userId }))
		},

		more(): number {
			return Math.max(0, (this.state?.userIds ?? []).length - MAX_AVATARS)
		},

		/**
		 * Der ganze Satz — im Detail sichtbar, auf der Karte im Titel-Attribut.
		 *
		 * Mit Namen, wo welche bekannt sind: „Kunde" ist eine Rolle, ein Name
		 * ist jemand, den man anrufen kann.
		 */
		sentence(): string {
			const seit = this.formattedSince
			const wer = (this.state?.userIds ?? []).map((id) => this.names[id] ?? id).join(', ')

			// Mit Namen ist der Satz fuer beide Seiten derselbe: Ein Name
			// beschreibt niemanden von aussen, er benennt ihn. Die
			// Unterscheidung „Kunde" gegen „euch" braucht es nur dort, wo
			// statt einer Person eine Seite steht.
			if (wer !== '' && seit !== '') {
				return t('projektwerk', 'wartet auf {names} · seit {date}', { names: wer, date: seit })
			}
			if (seit === '') {
				// Ohne Datum bleibt die Aussage stehen — sie ist wahr, nur ohne
				// Zeitpunkt. Ein erfundenes Datum wäre schlimmer als keins.
				return this.fromClientSide
					? t('projektwerk', 'wartet auf euch')
					: t('projektwerk', 'wartet auf Kunde')
			}

			return this.fromClientSide
				? t('projektwerk', 'wartet auf euch · seit {date}', { date: seit })
				: t('projektwerk', 'wartet auf Kunde · seit {date}', { date: seit })
		},

		formattedSince(): string {
			if (!this.state?.since) {
				return ''
			}

			const datum = new Date(this.state.since)
			if (Number.isNaN(datum.getTime())) {
				return ''
			}

			return datum.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit' })
		},
	},

	methods: { t },
})
</script>
