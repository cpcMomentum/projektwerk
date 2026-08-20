/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Die Endpunkte der Arbeitsschritte.
 *
 * Gelesen werden Schritte **nicht** hier: Sie kommen über `ticket#show` und
 * `ticket#index` mit, aus derselben gefilterten Ticketmenge. Ein eigener
 * Leseweg wäre der zweite Ort, an dem die Sichtbarkeit stimmen müsste.
 */

import type { Step } from '@/types/ticket'

import { apiDelete, apiGet, apiPatch, apiPost } from '@/services/api'

/**
 * Wem an diesem Ticket ein Schritt gegeben werden darf.
 *
 * Kommt vom Server, weil die Antwort aus der Sichtbarkeitsregel folgt: Bei
 * einem öffentlichen Vorgang alle Mitglieder ohne Trennung, bei einem internen
 * nur die besitzende Seite, bei einem Entwurf nur die anlegende Person. Im
 * Browser nachzubauen wäre eine zweite Fassung derselben Regel.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Tickets.
 */
export async function fetchAssignable(boardId: number, ticketId: number): Promise<string[]> {
	const antwort = await apiGet<{ userIds: string[] }>(`/boards/${boardId}/tickets/${ticketId}/assignable`)

	return antwort.userIds
}

/**
 * Wer an einem **noch nicht angelegten** Vorgang zuständig sein darf (#146).
 *
 * Für den Verantwortlichen-Picker im Anlege-Dialog, bevor es eine Ticket-ID
 * gibt. Dieselbe Regel wie {@link fetchAssignable}, nur gegen einen gedachten
 * Vorgang der gewählten Sichtbarkeit — deshalb hängt die Antwort an ihr und
 * wird bei jedem Wechsel neu geholt.
 *
 * @param boardId Kennung des Projekts.
 * @param visibility Die im Dialog gewählte Sichtbarkeit.
 */
export async function fetchAssignableForNew(boardId: number, visibility: string): Promise<string[]> {
	const antwort = await apiGet<{ userIds: string[] }>(`/boards/${boardId}/assignable-new?visibility=${encodeURIComponent(visibility)}`)

	return antwort.userIds
}

/**
 * Ein neuer Arbeitsschritt am Ende der Liste.
 *
 * @param boardId Kennung des Projekts.
 * @param ticketId Kennung des Tickets.
 * @param data Titel, optionale Zuweisung und Fälligkeit.
 * @param data.title Was zu tun ist.
 * @param data.assignedUserId Wer es tut, oder null.
 * @param data.dueDate Fälligkeit als JJJJ-MM-TT, oder null.
 */
export async function createStep(boardId: number, ticketId: number, data: {
	title: string
	assignedUserId?: string | null
	dueDate?: string | null
}): Promise<Step> {
	return apiPost<Step, typeof data>(`/boards/${boardId}/tickets/${ticketId}/steps`, data)
}

/**
 * Titel, Zuweisung, Fälligkeit, erledigt.
 *
 * `assignedUserId: null` heißt **Zuweisung löschen** und muss ausdrücklich
 * mitgeschickt werden — weggelassen hieße „unverändert".
 *
 * @param boardId Kennung des Projekts.
 * @param stepId Kennung des Schritts.
 * @param changes Nur die Felder, die sich ändern sollen.
 * @param changes.title Neuer Titel.
 * @param changes.assignedUserId Neue Zuweisung, null löscht sie.
 * @param changes.dueDate Neue Fälligkeit, null löscht sie.
 * @param changes.done Erledigt ja/nein.
 */
export async function updateStep(boardId: number, stepId: number, changes: {
	title?: string
	assignedUserId?: string | null
	dueDate?: string | null
	done?: boolean
}): Promise<Step> {
	return apiPatch<Step, typeof changes>(`/boards/${boardId}/steps/${stepId}`, changes)
}

/**
 * Einen Arbeitsschritt löschen (#203) — hart, ohne Papierkorb.
 *
 * @param boardId Kennung des Projekts.
 * @param stepId Kennung des Arbeitsschritts.
 */
export async function deleteStep(boardId: number, stepId: number): Promise<Step> {
	return apiDelete<Step>(`/boards/${boardId}/steps/${stepId}`)
}
