import { t } from '@nextcloud/l10n'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router.ts'
import { deepLinkTarget } from './services/deepLink.ts'
import { showError } from './services/toast.ts'

import './css/app.css'

/**
 * Kam der Aufruf über einen Deep-Link, wird die Hash-Route hier gesetzt.
 *
 * **Vor dem Mounten**, damit die Boardliste nicht kurz aufblitzt, und mit
 * `replace` statt `push`: Der Deep-Link ist kein Schritt in der Geschichte des
 * Browsers, sondern der Einstieg. Ein „Zurück" soll dorthin führen, wo die
 * Person herkam — in die E-Mail —, nicht auf eine leere Zwischenstufe.
 */
function applyDeepLink(): void {
	const target = deepLinkTarget()
	if (target === null) {
		return
	}

	if (target.available && target.boardId !== undefined) {
		router.replace({
			name: 'board',
			params: { boardId: String(target.boardId) },
			query: { ticket: String(target.ticketId) },
		})

		return
	}

	// Eine Meldung für drei Fälle — nicht sichtbar, nicht mehr da, fremdes
	// Projekt. Sie zu unterscheiden hieße zu beantworten, was die
	// Sichtbarkeitsregel verbirgt, und zwar auf Zuruf einer Zahl im Link.
	showError(t('projektwerk', 'Dieser Vorgang steht Ihnen nicht zur Verfügung. Fragen Sie die Projektleitung, falls Sie ihn erwartet haben.'))
	router.replace({ name: 'boards' })
}

document.addEventListener('DOMContentLoaded', () => {
	const app = createApp(App)
	app.use(createPinia())
	app.use(router)
	applyDeepLink()
	app.mount('.app-projektwerk')
})
