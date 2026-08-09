<template>
	<span v-if="state" class="pw-wait" :class="{ 'pw-wait--compact': compact }">
		<ClockAlertIcon :size="compact ? 13 : 16" />
		{{ text }}
	</span>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { WaitState } from '@/types/ticket'

import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import ClockAlertIcon from 'vue-material-design-icons/ClockAlertOutline.vue'

/**
 * „Wartet auf Kunde" — die Marke, die das Kernversprechen sichtbar macht.
 *
 * **Sie steht über dem Titel, nicht unten bei den Zeichen.** Der Zustand ist
 * kein Zusatzmerkmal wie eine Kommentarzahl, sondern die Antwort auf die Frage,
 * ob diese Karte einen gerade etwas angeht. Beim Überfliegen einer Spalte will
 * man sie überspringen können, ohne den Titel gelesen zu haben — und die
 * Zeichenzeile unten liest man erst, wenn man an der Karte schon hängengeblieben
 * ist.
 *
 * **In der Kundenansicht heißt sie anders.** „Wartet auf Kunde" wäre dort eine
 * Beschreibung von außen; wer selbst gemeint ist, liest „wartet auf euch". Es
 * ist dieselbe Tatsache, aber die Sprache derer, die sie angeht.
 */
export default defineComponent({
	name: 'WaitBadge',

	components: { ClockAlertIcon },

	props: {
		state: { type: Object as PropType<WaitState | null>, default: null },
		/** Aus Sicht der Kundenseite formuliert. */
		fromClientSide: { type: Boolean, default: false },
		/** Kleinere Fassung für die Karte. */
		compact: { type: Boolean, default: false },
	},

	computed: {
		text(): string {
			const seit = this.formattedSince

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
