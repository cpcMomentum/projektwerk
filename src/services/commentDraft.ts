// Kommentare lösen Benachrichtigungen aus, deshalb wird der Entwurf nur aufbewahrt, nie gesendet.

const PREFIX = 'projektwerk:kommentar-entwurf'

/**
 * @param userId Das angemeldete Konto.
 * @param ticketId Der Vorgang.
 */
function key(userId: string, ticketId: number): string {
	return `${PREFIX}:${userId}:${ticketId}`
}

/**
 * @param userId Das angemeldete Konto.
 * @param ticketId Der Vorgang.
 */
export function loadCommentDraft(userId: string, ticketId: number): string {
	try {
		return window.localStorage.getItem(key(userId, ticketId)) ?? ''
	} catch {
		return ''
	}
}

/**
 * @param userId Das angemeldete Konto.
 * @param ticketId Der Vorgang.
 * @param body Der bisher getippte Text; leer löscht den Entwurf.
 */
export function saveCommentDraft(userId: string, ticketId: number, body: string): void {
	try {
		if (body.trim() === '') {
			window.localStorage.removeItem(key(userId, ticketId))
		} else {
			window.localStorage.setItem(key(userId, ticketId), body)
		}
	} catch {
		// Privates Fenster oder gesperrte Website-Daten: dann eben ohne Entwurf.
	}
}
