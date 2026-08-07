<template>
	<NcContent appName="projektwerk">
		<NcAppNavigation>
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
import { NcAppContent, NcAppNavigation, NcAppNavigationItem, NcContent } from '@nextcloud/vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'

export default {
	name: 'App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent, FormatListChecksIcon },
	methods: { t },
}
</script>
