/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RouteRecordRaw } from 'vue-router'

import { createRouter, createWebHashHistory } from 'vue-router'
import TasksView from '@/views/TasksView.vue'

const routes: RouteRecordRaw[] = [
	{ path: '/', name: 'tasks', component: TasksView },
]

export const router = createRouter({
	history: createWebHashHistory(),
	routes,
})
