<template>
	<NcContent app-name="projektwerk">
		<NcAppNavigation>
			<NcAppNavigationItem :name="t('projektwerk', 'Meine Aufgaben')" :to="{ name: 'tasks' }">
				<template #icon><FormatListChecksIcon :size="20" /></template>
			</NcAppNavigationItem>
		</NcAppNavigation>
		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent } from '@nextcloud/vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'

export default {
	name: 'App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent, FormatListChecksIcon },
	methods: { t },
}
</script>
