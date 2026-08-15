/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Board-Endpunkte, getippt. Jeder Aufruf geht durch `api.ts` und damit
 * durch den Nicht-JSON-Wächter — es gibt hier bewusst keinen direkten
 * axios-Zugriff.
 */

import type { Board, BoardDetail } from '@/types/board'

import { apiGet, apiPut } from '@/services/api'

/**
 * Alle Projekte, in denen die angemeldete Person Mitglied ist.
 *
 * Ein Nichtmitglied bekommt eine leere Liste, keinen Fehler — es gibt nichts
 * zu verbergen, wo nichts ist.
 *
 * @param includeArchived Archivierte Projekte erscheinen nur, wo man sie ausdrücklich sehen will.
 */
export async function fetchBoards(includeArchived = false): Promise<Board[]> {
	return apiGet<Board[]>(`/boards${includeArchived ? '?includeArchived=1' : ''}`)
}

/**
 * Ein Projekt an- oder abpinnen (#115) — eine rein persönliche Einstellung.
 *
 * @param boardId Kennung des Projekts.
 * @param pinned Angepinnt ja/nein.
 */
export async function setBoardPin(boardId: number, pinned: boolean): Promise<{ pinned: boolean }> {
	return apiPut<{ pinned: boolean }, { pinned: boolean }>(`/boards/${boardId}/pin`, { pinned })
}

/**
 * Ein Projekt mit Mitgliedern, Spalten und der eigenen Rolle.
 *
 * Wirft mit Status 404, wenn die Person nicht Mitglied ist — dieselbe Antwort
 * wie für ein Projekt, das es nicht gibt. Das ist Absicht: Ein 403 verriete,
 * dass es dieses Projekt gibt.
 *
 * @param boardId Kennung des Projekts.
 */
export async function fetchBoard(boardId: number): Promise<BoardDetail> {
	return apiGet<BoardDetail>(`/boards/${boardId}`)
}
