/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * „Meine Aufgaben" — der einzige Leseweg ohne Projekt im Pfad.
 *
 * Gerade deshalb steht hier nur **eine** Funktion: Die Ansicht bekommt beide
 * Abschnitte und die Herkunftszeilen in einer Antwort. Zwei Aufrufe wären zwei
 * Zeitpunkte, und die Zeile könnte auf ein Board zeigen, das der zweite Aufruf
 * nicht mehr kennt.
 *
 * Ein Nichtmitglied bekommt hier **kein** 404, sondern leere Listen — es gibt
 * nichts zu verbergen, und „Du hast keine Aufgaben" ist wahr.
 */

import type { TaskList } from '@/types/task'

import { apiGet } from '@/services/api'

/**
 * Beide Abschnitte und die Herkunftszeilen in einer Antwort.
 */
export async function fetchTasks(): Promise<TaskList> {
	return apiGet<TaskList>('/tasks')
}
