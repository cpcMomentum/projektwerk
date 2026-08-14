<template>
	<NcContent appName="projektwerk">
		<!--
			**Drei feste Eintraege, und die Reihenfolge ist die Entscheidung**
			(#76, Axel am 2026-08-13): erst wo es hakt, dann was bei mir liegt,
			dann der Bestand.

			**Die Projekte bleiben EIN Eintrag.** Der Entwurf sah vor, jedes
			Projekt einzeln einzuhaengen; bei ueber zwanzig gleichzeitigen
			Projekten waere das die Liste in der Liste, nur schmaler. Damit
			entfaellt auch die Frage, wie man an archivierte kaeme — und die
			Boardliste muss weiterhin erst beim Oeffnen der Projektliste geladen
			werden, nicht bei jedem Seitenaufruf.
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
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'

export default {
	name: 'App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent, FolderMultipleIcon, FormatListChecksIcon, ViewDashboardIcon, CogIcon },

	setup() {
		return { isMobile: useIsMobile() }
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
