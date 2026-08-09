/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RouteRecordRaw } from 'vue-router'

import { createRouter, createWebHashHistory } from 'vue-router'
import BoardSettingsView from '@/views/BoardSettingsView.vue'
import BoardsView from '@/views/BoardsView.vue'
import BoardView from '@/views/BoardView.vue'
import TasksView from '@/views/TasksView.vue'

const routes: RouteRecordRaw[] = [
	{ path: '/', name: 'boards', component: BoardsView },
	{ path: '/boards/:boardId', name: 'board', component: BoardView },
	{ path: '/boards/:boardId/settings', name: 'board-settings', component: BoardSettingsView },
	{ path: '/tasks', name: 'tasks', component: TasksView },
]

export const router = createRouter({
	history: createWebHashHistory(),
	routes,
})
