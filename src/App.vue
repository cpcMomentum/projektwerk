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
			<NcAppNavigationItem
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
				<NcAppNavigationItem
					:name="t('projektwerk', 'Meine Einstellungen')"
					:to="{ name: 'my-settings' }"
					@click="closeNavigationOnMobile">
					<template #icon>
						<CogIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script>
// Bewusst `t` statt `translate as t`: die l10n-Extraktionsskripte erkennen nur
// den Alias-freien Import, ein umbenannter Import bleibt fuer sie unsichtbar.
import { emit } from '@nextcloud/event-bus'
import { t } from '@nextcloud/l10n'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
import { useBoardStore } from '@/stores/boardStore'

export default {
	name: 'App',
	components: { NcContent, NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem, NcAppContent, FolderMultipleIcon, FormatListChecksIcon, StarIcon, ViewDashboardIcon, CogIcon },

	setup() {
		return { isMobile: useIsMobile(), store: useBoardStore() }
	},

	created() {
		// Einmal beim Mounten der App, fuer den Pin-Abschnitt (#115). Nicht bei
		// jedem Ansichtswechsel — der Rahmen bleibt stehen.
		this.store.loadBoards()
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
	},
}
</script>
