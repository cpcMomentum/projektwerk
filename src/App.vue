<template>
	<NcContent appName="projektwerk">
		<NcAppNavigation>
			<NcAppNavigationItem
				:name="t('projektwerk', 'Projekte')"
				:to="{ name: 'boards' }"
				@click="closeNavigationOnMobile">
				<template #icon>
					<FolderMultipleIcon :size="20" />
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
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'

export default {
	name: 'App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent, FolderMultipleIcon, FormatListChecksIcon },

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
