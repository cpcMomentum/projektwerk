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
			<div
				v-for="board in store.boards"
				:key="board.id"
				class="pw-boardcard">
				<button
					type="button"
					class="pw-boardcard__open"
					@click="open(board.id)">
					<span class="pw-boardcard__title">{{ board.title }}</span>
					<!--
						Beide Firmennamen, nicht nur der des Kunden: Traege nur die
						Gegenseite einen, waere die eigene stumm „der Normalfall".
					-->
					<span v-if="store.orgLine(board)" class="pw-boardcard__org">{{ store.orgLine(board) }}</span>
				</button>

				<!--
					Der Stern pinnt das Projekt in die Seitenleiste (#115). Gefuellt
					und farbig, wenn angepinnt; leer und ruhig sonst. `pressed`
					sagt Screenreadern den Zustand.
				-->
				<NcButton
					variant="tertiary"
					class="pw-boardcard__pin"
					:class="{ 'pw-boardcard__pin--on': board.pinned }"
					:pressed="board.pinned === true"
					:ariaLabel="board.pinned
						? t('projektwerk', 'Von der Seitenleiste lösen')
						: t('projektwerk', 'An die Seitenleiste anpinnen')"
					@click="store.togglePin(board.id)">
					<template #icon>
						<StarIcon v-if="board.pinned" :size="20" />
						<StarOutlineIcon v-else :size="20" />
					</template>
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script lang="ts">
import { t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import { useBoardStore } from '@/stores/boardStore'

export default defineComponent({
	name: 'BoardsView',

	components: { NcButton, NcEmptyContent, FolderMultipleIcon, StarIcon, StarOutlineIcon },

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
