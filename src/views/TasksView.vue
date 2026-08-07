<template>
	<div class="tasks-view">
		<h2>{{ t('projektwerk', 'Meine Aufgaben') }}</h2>
		<NcEmptyContent :name="t('projektwerk', 'Zurzeit wartet nichts auf Sie.')">
			<template #icon><FormatListChecksIcon :size="20" /></template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'

export default {
	name: 'TasksView',
	components: { NcEmptyContent, FormatListChecksIcon },
	methods: { t },
}
</script>

<style scoped>
/* Pflicht fuer jede View in NcAppContent, sonst klebt der Inhalt am Rand
   bzw. wird vom Sidebar-Toggle ueberdeckt. */
.tasks-view {
	padding: 20px;
	padding-left: 50px;
}
</style>
