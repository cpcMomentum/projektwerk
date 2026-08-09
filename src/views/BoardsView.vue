<template>
	<div class="pw-view">
		<div class="pw-view__head">
			<h2>{{ t('projektwerk', 'Projekte') }}</h2>
		</div>

		<div v-if="store.loading" class="pw-boards">
			<div v-for="n in 3" :key="n" class="pw-skel">
				<i /><i /><i />
			</div>
		</div>

		<NcEmptyContent
			v-else-if="store.boards.length === 0"
			:name="t('projektwerk', 'Noch kein Projekt')"
			:description="t('projektwerk', 'Sobald Sie zu einem Projekt gehören, steht es hier.')">
			<template #icon>
				<FolderMultipleIcon :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="pw-boards">
			<button
				v-for="board in store.boards"
				:key="board.id"
				type="button"
				class="pw-boardcard"
				@click="open(board.id)">
				<span class="pw-boardcard__title">{{ board.title }}</span>
				<!--
					Beide Firmennamen, nicht nur der des Kunden: Traege nur die
					Gegenseite einen, waere die eigene stumm „der Normalfall".
				-->
				<span v-if="store.orgLine(board)" class="pw-boardcard__org">{{ store.orgLine(board) }}</span>
			</button>
		</div>
	</div>
</template>

<script lang="ts">
import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import { useBoardStore } from '@/stores/boardStore'

export default defineComponent({
	name: 'BoardsView',

	components: { NcEmptyContent, FolderMultipleIcon },

	setup() {
		return { store: useBoardStore() }
	},

	mounted() {
		this.store.loadBoards()
	},

	methods: {
		t,

		open(boardId: number) {
			this.$router.push({ name: 'board', params: { boardId: String(boardId) } })
		},
	},
})
</script>
