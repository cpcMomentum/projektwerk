<template>
	<NcContent appName="projektwerk">
		<!--
			**Drei feste Eintraege, und die Reihenfolge ist die Entscheidung**
			(#76, Axel am 2026-08-13): erst wo es hakt, dann was bei mir liegt,
			dann der Bestand.

			**Die Projekte bleiben EIN Eintrag.** Der Entwurf sah vor, jedes
			Projekt einzeln einzuhaengen; bei ueber zwanzig gleichzeitigen
			Projekten waere das die Liste in der Liste, nur schmaler. Damit
			entfaellt auch die Frage, wie man an archivierte kaeme.

			**Seit #115 laedt der Rahmen die Boardliste dennoch einmal** — fuer
			den Pin-Abschnitt darunter. Das ist kein Widerspruch zum Satz oben:
			Geladen wird beim **Mounten der App**, also einmal je Seitenaufruf im
			Browser, nicht bei jedem Wechsel der Ansicht (der Rahmen bleibt
			stehen). Die volle Projektliste bleibt der Projekte-Seite.
		-->
		<NcAppNavigation>
			<!--
				**Der Überblick ist ein internes Werkzeug** (#234). Ein Betrachter,
				der in allen seinen Projekten extern ist (der Kunde), sieht den
				Eintrag nicht — der Router leitet ihn ohnehin auf sein Board um,
				und ein Menüpunkt, der nur zurückwirft, wäre Rauschen. Optimistisch
				gezeigt, solange die Liste noch lädt: Der interne Normalfall
				bekommt kein Aufblitzen, und der Kunde landet durch das Gate gar
				nicht erst hier.
			-->
			<NcAppNavigationItem
				v-if="!store.loaded || store.internalSomewhere"
				:name="t('projektwerk', 'Überblick')"
				:to="{ name: 'overview' }"
				@click="closeNavigationOnMobile">
				<template #icon>
					<ViewDashboardIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('projektwerk', 'Meine Aufgaben')"
				:to="{ name: 'tasks' }"
				@click="closeNavigationOnMobile">
				<template #icon>
					<FormatListChecksIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('projektwerk', 'Projekte')"
				:to="{ name: 'boards' }"
				@click="closeNavigationOnMobile">
				<template #icon>
					<FolderMultipleIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<!--
				**Die angepinnten Projekte** (#115) — die persönliche Auswahl unter
				den drei festen Punkten. Nichts angepinnt, kein Abschnitt: Die
				Leiste sieht dann aus wie zuvor. Die Liste ist die Teilmenge der
				ohnehin geladenen Boards, also die Schnittmenge aus „gepinnt" und
				„sichtbar" — ein Projekt, aus dem man herausfällt, verschwindet von
				selbst.
			-->
			<template v-if="store.pinnedBoards.length > 0">
				<NcAppNavigationCaption :name="t('projektwerk', 'Angepinnt')" />
				<NcAppNavigationItem
					v-for="board in store.pinnedBoards"
					:key="board.id"
					:name="board.title"
					:to="{ name: 'board', params: { boardId: board.id } }"
					@click="closeNavigationOnMobile">
					<template #icon>
						<StarIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>

			<!--
				Unten im Seitenmenue, wie in WorkTime. **Eine volle Seite, kein
				Ausklapp**: Der erste Anlauf haengte die Liste in das
				Einstellungs-Popover des Seitenmenues — bei zwei Projekten ging
				das, bei zwanzig ist eine handbreite Spalte der falsche Ort fuer
				eine Tabelle, in der man vergleichen will.
			-->
			<template #footer>
				<!--
					Antwort-Postfach (#286): Instanz-Einstellung, nur für
					Administratoren. Der Eintrag blendet sich per `OC.isUserAdmin()`
					aus, wo er ohnehin ins Leere liefe — die Sperre selbst sitzt
					serverseitig an den Endpunkten.
				-->
				<NcAppNavigationItem
					v-if="isAdmin"
					:name="t('projektwerk', 'Antworten per E-Mail')"
					:to="{ name: 'reply-mailbox' }"
					@click="closeNavigationOnMobile">
					<template #icon>
						<EmailIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('projektwerk', 'Meine Einstellungen')"
					:to="{ name: 'my-settings' }"
					@click="closeNavigationOnMobile">
					<template #icon>
						<CogIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<!--
					Dauerhafter Zugang zu den Neuerungen (#329): öffnet das
					„Was ist neu?"-Fenster im Archiv-Modus (alle bisherigen Punkte).
					Kein Router-Ziel, nur ein Knopf. Gäste sehen ihn nicht —
					sie bekommen keine Produktmeldungen.
				-->
				<NcAppNavigationItem
					v-if="!isGuest"
					:name="t('projektwerk', 'Neuerungen')"
					@click="openWhatsNew">
					<template #icon>
						<BullhornOutlineIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<router-view />
		</NcAppContent>

		<!--
			„Was ist neu?"-Fenster (#315). Prueft sich beim Mounten selbst: Es
			oeffnet nur, wenn der Server Eintraege liefert (fuer Gaeste nie), und
			blockiert nie. NcModal teleportiert ohnehin an den `body`, die
			Platzierung hier ist darum nur die logische Heimat.
		-->
		<WhatsNewDialog ref="whatsNew" />
	</NcContent>
</template>

<script>
// Bewusst `t` statt `translate as t`: die l10n-Extraktionsskripte erkennen nur
// den Alias-freien Import, ein umbenannter Import bleibt fuer sie unsichtbar.
import { emit } from '@nextcloud/event-bus'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import BullhornOutlineIcon from 'vue-material-design-icons/BullhornOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import EmailIcon from 'vue-material-design-icons/EmailOutline.vue'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
import WhatsNewDialog from '@/components/WhatsNewDialog.vue'
import { useBoardStore } from '@/stores/boardStore'

export default {
	name: 'App',
	components: { NcContent, NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem, NcAppContent, BullhornOutlineIcon, FolderMultipleIcon, FormatListChecksIcon, StarIcon, ViewDashboardIcon, CogIcon, EmailIcon, WhatsNewDialog },

	setup() {
		// Gäste bekommen keine Produktmeldungen (#329): blendet den
		// „Neuerungen"-Eintrag aus. Fehlt der Zustand (alter Cache), zeigen wir
		// ihn — der `all`-Endpunkt liefert Gästen ohnehin nichts.
		return { isMobile: useIsMobile(), store: useBoardStore(), isGuest: loadState('projektwerk', 'isGuest', false) }
	},

	computed: {
		/**
		 * Ob die angemeldete Person Instanz-Administrator ist — steuert allein
		 * die Sichtbarkeit des Menueeintrags „Antworten per E-Mail" (#286). Aus
		 * der Nextcloud-Laufzeit (`OC.isUserAdmin()`), nicht aus einem eigenen
		 * Server-Signal: Ein `IGroupManager`-Aufruf in `lib/` verstiesse gegen
		 * die „keine Admin-Ausnahme"-Invariante (Architektur-Test). Der Schutz
		 * der Daten sitzt ohnehin serverseitig an den Endpunkten; hier geht es
		 * nur darum, keinen toten Eintrag zu zeigen.
		 *
		 * Reines JS (diese Datei ist kein `lang="ts"`): keine Typannotationen.
		 */
		isAdmin() {
			const oc = globalThis.OC
			return !!(oc && typeof oc.isUserAdmin === 'function' && oc.isUserAdmin())
		},
	},

	created() {
		// Einmal beim Mounten der App, fuer den Pin-Abschnitt (#115). Nicht bei
		// jedem Ansichtswechsel — der Rahmen bleibt stehen. `ensureBoards` statt
		// `loadBoards`, damit sich dieser Abruf und das Gaeste-Gate im Router
		// (#234) denselben Ladevorgang teilen, statt zweimal zu holen.
		this.store.ensureBoards()
	},

	methods: {
		t,

		/**
		 * Auf dem Handy nach der Auswahl zuklappen.
		 *
		 * Die Navigation liegt dort **ueber** dem Inhalt und nimmt fast den
		 * ganzen Schirm ein; wer einen Eintrag waehlt, will dorthin — und
		 * musste sie bisher von Hand wieder schliessen. Auf breiten Schirmen
		 * steht sie dauerhaft daneben und darf bleiben.
		 *
		 * Geschlossen wird ueber den Ereignisbus, auf dem `NcAppNavigation`
		 * ohnehin lauscht (`subscribe('toggle-navigation')`) — das ist ihr
		 * eigener Weg und kein Griff in ihren Zustand.
		 */
		closeNavigationOnMobile() {
			if (this.isMobile) {
				emit('toggle-navigation', { open: false })
			}
		},

		/**
		 * Das „Was ist neu?"-Fenster im Archiv-Modus öffnen (#329) — alle
		 * bisherigen Neuerungen. Über die Template-Referenz auf den Dialog, der
		 * `openArchive` per `defineExpose` bereitstellt.
		 */
		openWhatsNew() {
			this.closeNavigationOnMobile()
			this.$refs.whatsNew?.openArchive()
		},
	},
}
</script>
