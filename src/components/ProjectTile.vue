<template>
	<!--
		Eine Projekt-Kachel — geteilt zwischen dem Überblick (#226) und dem
		Projekt-Verzeichnis (#276). Statuszahlen über einem echt proportionalen
		Balken, Zustandssignal rechts, „N diese Woche".

		**Ein `div[role=button]`, kein `<button>`** — dasselbe Muster wie die
		Maßnahmen-Tabelle. Im Verzeichnis trägt die Kachel einen eigenen
		Pin-Knopf, und ein Knopf darf nicht in einem Knopf stehen (§115).
	-->
	<div
		class="pw-tile"
		:class="{ 'pw-tile--pinnable': pinnable }"
		role="button"
		tabindex="0"
		:aria-label="tileAria"
		@click="$emit('open', boardId)"
		@keydown.enter="$emit('open', boardId)"
		@keydown.space.prevent="$emit('open', boardId)">
		<!--
			Der Pin. Im Verzeichnis (`pinnable`) ein anklickbarer Toggle oben
			rechts: gefüllt und golden, wenn angepinnt; leerer Umriss sonst. Kein
			gefüllter NcButton mehr — genau der wurde in @nextcloud/vue 9 als
			gedrückter Knopf blau hinterlegt (der „blaue Block", #276).
		-->
		<button
			v-if="pinnable"
			type="button"
			class="pw-tile__pinbtn"
			:class="{ 'pw-tile__pinbtn--on': pinned }"
			:aria-pressed="pinned ? 'true' : 'false'"
			:aria-label="pinned
				? t('projektwerk', 'Von der Seitenleiste lösen')
				: t('projektwerk', 'An die Seitenleiste anpinnen')"
			@click.stop="$emit('togglePin', boardId)">
			<StarIcon v-if="pinned" :size="20" />
			<StarOutlineIcon v-else :size="20" />
		</button>

		<span class="pw-tile__head">
			<span class="pw-tile__ident">
				<!--
					Im Überblick zeigt der Stern nur an (kein Toggle) und steht
					inline vor dem Namen — dieselbe Flex-Zeile wie überall, damit
					das Icon nicht NCs globales `display:flex; justify-content:center`
					erbt und zum zentrierten Block wird (siehe
					docs/rca/favoritenstern-projektkachel.md).
				-->
				<span class="pw-tile__name">
					<StarIcon v-if="!pinnable && pinned" class="pw-tile__pin" :size="14" />
					<span class="pw-tile__title">{{ title }}</span>
				</span>
				<span v-if="org" class="pw-tile__org">{{ org }}</span>
			</span>
			<span class="pw-tile__headcol">
				<span class="pw-dot" :class="'pw-dot--' + zustand">{{ zustandLabel }}</span>
				<span v-if="neuDieseWoche > 0" class="pw-tile__week" aria-hidden="true">
					<span class="pw-tile__weektri">▲</span>{{ weekText }}
				</span>
			</span>
		</span>

		<template v-if="!istLeer">
			<!-- Zahlen am Anfang ihres Segments, gleiche Proportion wie der Balken. -->
			<span class="pw-tile__nums">
				<span
					v-for="seg in segmente"
					:key="seg.key"
					class="pw-tile__num"
					:class="seg.cls"
					:style="{ flex: seg.count }">
					<i class="pw-tile__tick" />{{ seg.count }}
				</span>
			</span>

			<!-- Der echt proportionale Statusbalken; Nullwerte fehlen ganz. -->
			<span class="pw-tile__bar">
				<span
					v-for="seg in segmente"
					:key="seg.key"
					class="pw-tile__seg"
					:class="seg.cls"
					:style="{ flex: seg.count }" />
			</span>
		</template>

		<!--
			Leeres Projekt (#276): nur im Verzeichnis sichtbar, im Überblick fällt
			es durch den Filter. Statt eines leeren Balkens ein Wort.
		-->
		<span v-else class="pw-tile__empty">{{ t('projektwerk', 'Noch keine Vorgänge') }}</span>
	</div>
</template>

<script lang="ts">
import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'

/** Ein Statuseintrag der Segmente — Reihenfolge = Balken. */
const STATUS = [
	{ key: 'neu', feld: 'neu', cls: 'pw-st--neu' },
	{ key: 'offen', feld: 'offen', cls: 'pw-st--offen' },
	{ key: 'wartet', feld: 'wartet', cls: 'pw-st--wartet' },
	{ key: 'erledigt', feld: 'erledigt', cls: 'pw-st--erl' },
] as const

type Zustand = 'rot' | 'gelb' | 'grau' | 'gruen'

/**
 * Die reine Darstellung einer Projekt-Kachel. Alle Zahlen kommen als Props; die
 * Rechnung liegt weiterhin im jeweiligen Store (`overviewStore` bzw. der Join im
 * Verzeichnis). Damit sehen Überblick und Verzeichnis **dieselbe** Kachel.
 *
 * `pinnable` schaltet den Pin-Toggle (Verzeichnis) gegen die reine Anzeige
 * (Überblick). Emits: `open` und `togglePin`, je mit der Board-Kennung.
 */
export default defineComponent({
	name: 'ProjectTile',

	components: { StarIcon, StarOutlineIcon },

	props: {
		/** Kennung des Projekts. */
		boardId: { type: Number, required: true },
		/** Titel des Projekts. */
		title: { type: String, required: true },
		/** Firmenzeile (beide Seiten), oder leer. */
		org: { type: String, default: '' },
		/** Offen, neu in der Eingangsspalte. */
		neu: { type: Number, default: 0 },
		/** Offen, in Arbeit. */
		offen: { type: Number, default: 0 },
		/** Offen, wartet auf die Kundenseite. */
		wartet: { type: Number, default: 0 },
		/** Abgeschlossen mit Ergebnis erledigt. */
		erledigt: { type: Number, default: 0 },
		/** Neue Vorgänge der letzten Woche — die Marke „▲ N diese Woche". */
		neuDieseWoche: { type: Number, default: 0 },
		/** Abgeleitetes Zustandssignal. */
		zustand: { type: String as () => Zustand, default: 'gruen' },
		/** Ob der Betrachter das Projekt angepinnt hat. */
		pinned: { type: Boolean, default: false },
		/** Ob der Pin ein anklickbarer Toggle ist (Verzeichnis) statt reiner Anzeige. */
		pinnable: { type: Boolean, default: false },
	},

	emits: ['open', 'togglePin'],

	computed: {
		/** Die Segmente in Balken-Reihenfolge — nur die mit Wert > 0. */
		segmente(): Array<{ key: string, cls: string, count: number }> {
			const werte: Record<string, number> = {
				neu: this.neu,
				offen: this.offen,
				wartet: this.wartet,
				erledigt: this.erledigt,
			}

			return STATUS
				.map((s) => ({ key: s.key, cls: s.cls, count: werte[s.feld] }))
				.filter((seg) => seg.count > 0)
		},

		/** Kein Segment mit Wert — das Projekt hat noch keine Vorgänge. */
		istLeer(): boolean {
			return this.segmente.length === 0
		},

		/** Das kurze Wort zum Zustandssignal (Farbe trägt nie allein, §9). */
		zustandLabel(): string {
			switch (this.zustand) {
				case 'rot':
					return t('projektwerk', 'überfällig')
				case 'gelb':
					return t('projektwerk', 'wartet')
				case 'grau':
					return t('projektwerk', 'steht still')
				default:
					return t('projektwerk', 'läuft')
			}
		},

		/** Die sichtbare Beschriftung der Wochen-Marke (#232). */
		weekText(): string {
			return n('projektwerk', '%n diese Woche', '%n diese Woche', this.neuDieseWoche)
		},

		/** Beschriftung der ganzen Kachel für Hilfstechnik. */
		tileAria(): string {
			const kern = t('projektwerk', '{title}: {zustand}, {neu} neu, {offen} offen, {wartet} wartet, {erledigt} erledigt', {
				title: this.title,
				zustand: this.zustandLabel,
				neu: String(this.neu),
				offen: String(this.offen),
				wartet: String(this.wartet),
				erledigt: String(this.erledigt),
			})
			if (this.neuDieseWoche > 0) {
				return kern + '. ' + n('projektwerk', '%n neuer Vorgang diese Woche', '%n neue Vorgänge diese Woche', this.neuDieseWoche)
			}
			return kern
		},
	},

	methods: { t },
})
</script>
