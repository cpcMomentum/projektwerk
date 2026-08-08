<template>
	<NcContent appName="projektwerk">
		<NcAppNavigation>
			<NcAppNavigationItem :name="t('projektwerk', 'Projekte')" :to="{ name: 'boards' }">
				<template #icon>
					<FolderMultipleIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('projektwerk', 'Meine Aufgaben')" :to="{ name: 'tasks' }">
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
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'

export default {
	name: 'App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent, FolderMultipleIcon, FormatListChecksIcon },
	methods: { t },
}
</script>
