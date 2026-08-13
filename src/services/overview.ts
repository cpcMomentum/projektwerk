/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Der Überblick — der zweite Leseweg ohne Projekt im Pfad (#76).
 *
 * Wie bei „Meine Aufgaben" nur **eine** Funktion: Beide Abschnitte, die
 * Herkunftszeilen und die Namen kommen in einer Antwort. Zwei Aufrufe wären
 * zwei Zeitpunkte, und die Zeile könnte auf ein Projekt zeigen, das der zweite
 * Aufruf nicht mehr kennt.
 *
 * Ein Nichtmitglied bekommt hier **kein** 404, sondern leere Listen — es gibt
 * nichts zu verbergen, und „bei dir hakt gerade nichts" ist wahr.
 */

import type { OverviewData } from '@/types/overview'

import { apiGet } from '@/services/api'

/**
 * Alles Sichtbare über alle Projekte, mit Wartezustand und Namen.
 */
export async function fetchOverview(): Promise<OverviewData> {
	return apiGet<OverviewData>('/overview')
}
