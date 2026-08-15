/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RouteRecordRaw } from 'vue-router'

import { createRouter, createWebHashHistory } from 'vue-router'
import BoardSettingsView from '@/views/BoardSettingsView.vue'
import BoardsView from '@/views/BoardsView.vue'
import BoardView from '@/views/BoardView.vue'
import MySettingsView from '@/views/MySettingsView.vue'
import OverviewView from '@/views/OverviewView.vue'
import TasksView from '@/views/TasksView.vue'

const routes: RouteRecordRaw[] = [
	// **Der Einstieg ist der Ueberblick** (#76, Axel am 2026-08-13), nicht mehr
	// die Projektliste. Die beantwortete nur „welche Projekte gibt es" — eine
	// Frage, die man sich selten stellt.
	{ path: '/', name: 'overview', component: OverviewView },
	// Die Projektliste hat damit einen eigenen Pfad bekommen. Deep-Links auf
	// `/boards/:boardId` sind davon **nicht** beruehrt: Sie waren nie `/`.
	{ path: '/boards', name: 'boards', component: BoardsView },
	{ path: '/boards/:boardId', name: 'board', component: BoardView },
	{ path: '/boards/:boardId/settings', name: 'board-settings', component: BoardSettingsView },
	{ path: '/tasks', name: 'tasks', component: TasksView },
	// Wie in WorkTime: eine eigene Seite, erreichbar unten im Seitenmenue.
	{ path: '/my-settings', name: 'my-settings', component: MySettingsView },
]

export const router = createRouter({
	history: createWebHashHistory(),
	routes,
})
