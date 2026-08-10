import type { Browser } from '@playwright/test'

import { Api } from './api.ts'
import { INTERN, KUNDE } from './rollen.ts'

/**
 * Das Szenario, gegen das die Tests laufen: ein Projekt mit beiden Seiten und
 * je einem Vorgang pro Sichtbarkeitsstufe.
 *
 * Aufgebaut von der Dienstleisterseite, weil nur sie interne Vorgaenge anlegen
 * kann — und weil genau das die Frage ist, die die Tests stellen: Was davon
 * kommt bei der Kundenseite an.
 */

export interface Projekt {
	boardId: number
	/**
	 * Der Boardtitel — der einzige Anker, den die Kundenseite unabhaengig vom
	 * Stand der Vorgaenge sieht. Wer stattdessen einen Vorgang als Anker nimmt,
	 * koppelt seinen Test still an den Test darueber.
	 */
	titel: string
	/** Spalten-IDs in Reihenfolge, aus den Standardspalten des neuen Boards. */
	spalten: { id: number, title: string }[]
	/** Der Vorgang, den beide Seiten sehen. */
	oeffentlich: { id: number, number: number, title: string }
	/** Der interne Vorgang — sein Titel darf bei der Kundenseite nirgends auftauchen. */
	intern: { id: number, number: number, title: string }
}

/**
 * Eine Marke, die in keinem anderen Text der App vorkommt.
 *
 * Sie ist der eigentliche Trick der Leck-Gegenprobe: Danach laesst sich im
 * ganzen ausgelieferten DOM suchen, statt einzelne Stellen abzuklappern. Was
 * durchscheint, scheint irgendwo durch — auch an einer Stelle, an die wir beim
 * Schreiben des Tests nicht gedacht haben.
 */
export function marke(): string {
	return `ZZINTERN${Date.now().toString(36).toUpperCase()}`
}

export async function projektAufbauen(browser: Browser, geheimwort: string): Promise<Projekt> {
	const kontext = await browser.newContext({ storageState: INTERN.sitzung })

	try {
		const api = await Api.fuer(kontext.request)

		// **Die Marke darf nicht in den Boardtitel.** Den sieht die Kundenseite
		// zu Recht — sie ist ja Mitglied. Stuende die Marke dort, faende die
		// Gegenprobe sie im DOM und meldete ein Leck, das keines ist. Der erste
		// Lauf dieses Tests ist genau darueber gestolpert.
		const titel = `E2E-Projekt ${Date.now().toString(36)}`
		const board = await api.boardAnlegen(titel, 'E2E Dienstleister', 'E2E Kunde')
		const boardId = Number(board.id)

		await api.mitgliedHinzufuegen(boardId, KUNDE.uid, 'external')

		const ansicht = await api.boardZeigen(boardId)
		const spalten = (ansicht.columns ?? []).map((s: any) => ({ id: Number(s.id), title: String(s.title) }))
		if (spalten.length < 2) {
			throw new Error(`Board ${boardId} hat ${spalten.length} Spalten, erwartet mindestens 2`)
		}

		const oeffentlich = await api.ticketAnlegen(boardId, {
			title: 'Kundensichtbarer Vorgang',
			columnId: spalten[0].id,
			visibility: 'public',
		})

		const intern = await api.ticketAnlegen(boardId, {
			title: `Interner Vorgang ${geheimwort}`,
			columnId: spalten[0].id,
			visibility: 'internal',
			description: `Beschreibung mit ${geheimwort}`,
		})

		return {
			boardId,
			titel,
			spalten,
			oeffentlich: { id: Number(oeffentlich.id), number: Number(oeffentlich.number), title: String(oeffentlich.title) },
			intern: { id: Number(intern.id), number: Number(intern.number), title: String(intern.title) },
		}
	} finally {
		await kontext.close()
	}
}

/**
 * Archiviert das Testboard.
 *
 * Ohne das sammeln sich auf der Dev-Instanz mit jedem Lauf Boards an, bis die
 * Projektliste unbrauchbar ist — und dann laesst man die Tests nicht mehr
 * laufen. Geloescht wird nicht: Die App loescht nie (§5.18), und ein Test darf
 * sich dafuer keine Ausnahme bauen.
 */
export async function projektAufraeumen(browser: Browser, boardId: number): Promise<void> {
	const kontext = await browser.newContext({ storageState: INTERN.sitzung })

	try {
		const api = await Api.fuer(kontext.request)
		await api.boardArchivieren(boardId)
	} finally {
		await kontext.close()
	}
}
