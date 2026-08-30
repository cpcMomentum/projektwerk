/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RouteLocationRaw, RouteRecordRaw } from 'vue-router'
import type { Board } from '@/types/board'

import { createRouter, createWebHashHistory } from 'vue-router'
import BoardSettingsView from '@/views/BoardSettingsView.vue'
import BoardsView from '@/views/BoardsView.vue'
import BoardView from '@/views/BoardView.vue'
import MySettingsView from '@/views/MySettingsView.vue'
import OverviewView from '@/views/OverviewView.vue'
import TasksView from '@/views/TasksView.vue'
import { useBoardStore } from '@/stores/boardStore'

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

/**
 * Das Gäste-Gate (#234): Wohin ein Betrachter statt auf den Überblick gehört —
 * oder `null`, wenn der Überblick ihm zusteht.
 *
 * Der Überblick ist ein **internes** Steuerungswerkzeug über alle Projekte. Wer
 * in mindestens einem Projekt intern ist (der Dienstleister), sieht ihn. Wer in
 * **allen** seinen Projekten extern ist (der Kunde), wird auf sein Board
 * geleitet — bei mehreren auf die Projektliste, aus der er wählt. Ohne jedes
 * Projekt bleibt es der Überblick (leer): Es gibt kein Board, auf das man
 * umleiten könnte, und ein interner Erstnutzer legt dort sein erstes Projekt an.
 *
 * **Eine reine Funktion**, damit die Entscheidung ohne Router und Store prüfbar
 * ist. Die Regel ist dieselbe wie im Menü (`internalSomewhere`), an genau einem
 * Ort formuliert.
 *
 * @param boards Die sichtbaren Projekte des Betrachters, je mit eigener Rolle.
 * @return Das Umleitungsziel, oder `null` für „Überblick zeigen".
 */
export function gateTarget(boards: Board[]): RouteLocationRaw | null {
	if (boards.length === 0 || boards.some((board) => board.viewerRole === 'internal')) {
		return null
	}

	if (boards.length === 1) {
		return { name: 'board', params: { boardId: String(boards[0].id) } }
	}

	return { name: 'boards' }
}

/**
 * Nur der Überblick (`/`) ist betroffen (#234); jede andere Route läuft
 * unberührt durch. Die Boardliste wird über `ensureBoards()` sichergestellt —
 * derselbe Ladevorgang, den auch der Rahmen anstößt, kein zweiter Abruf.
 *
 * **Fail-open:** Konnte die Liste nicht geladen werden (`loaded` bleibt falsch),
 * zeigt sich der Überblick. Die Zahlen dort sind ohnehin sichtbarkeits-sicher;
 * das Gate ist Verteidigung in der Tiefe, kein Sperrriegel, der einen internen
 * Nutzer bei einem Netzflackern aussperren dürfte.
 */
router.beforeEach(async (to) => {
	if (to.name !== 'overview') {
		return true
	}

	const store = useBoardStore()
	await store.ensureBoards()
	if (!store.loaded) {
		return true
	}

	return gateTarget(store.boards) ?? true
})
