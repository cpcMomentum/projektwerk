import { defineConfig, devices } from '@playwright/test'

/**
 * E2E-Tests gegen eine echte Nextcloud (#80).
 *
 * **Warum das neben der Leak-Matrix existiert.** Die Matrix prueft die
 * Sichtbarkeitsregel auf Datenbankebene — dort, wo sie steht. Diese Tests
 * pruefen dieselbe Zusage von aussen, im Browser, dort wo der Kunde sie
 * erlebt. Eine Regel, die im JOIN stimmt und in der Oberflaeche trotzdem
 * durchscheint, waere gebrochen; die Matrix allein saehe das nicht.
 *
 * **Adressen.** In der CI laeuft Nextcloud unter `php -S` ohne Rewrite, die
 * App also unter `/index.php/apps/projektwerk/`. Lokal hat die Dev-Instanz
 * ein Rewrite und kaeme auch ohne aus — wir nehmen trotzdem ueberall die
 * `index.php`-Form, weil ein Test, der lokal einen anderen Weg nimmt als in
 * der CI, genau dort schweigt, wo er reden muesste.
 */

const BASIS = process.env.PWERK_E2E_URL ?? 'http://localhost:8080'

export default defineConfig({
	testDir: './tests/e2e',

	// Der Testlauf faehrt einen echten Server mit echter Datenbank. Parallel
	// laufende Arbeiter wuerden sich beim Anlegen von Boards gegenseitig in die
	// Quere kommen — und `php -S` in der CI hat ohnehin nur wenige Arbeiter.
	fullyParallel: false,
	workers: 1,

	// Kein `retries` in der CI. Ein Test, der beim zweiten Versuch gruen wird,
	// hat einen echten Fehler gefunden und ihn zugedeckt; genau die Klasse von
	// Befund wollen wir sehen.
	retries: 0,
	forbidOnly: !!process.env.CI,

	reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],

	use: {
		baseURL: BASIS,
		// Nextcloud unter `php -S` ist langsam genug, dass der Standard von 5 s
		// gelegentlich zuschlaegt, ohne dass etwas kaputt ist.
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
		// Selbst signierte Zertifikate spielen hier keine Rolle (HTTP), aber die
		// Sprache schon: Die Tests lesen deutsche Beschriftungen.
		locale: 'de-DE',
	},

	projects: [
		// Meldet beide Rollen an und legt die Sitzungen ab. Als eigenes Projekt
		// mit `dependencies` statt als `globalSetup` — der empfohlene Weg, weil
		// es dieselben Fixtures, Reports und Traces bekommt wie ein Test.
		{ name: 'anmeldung', testMatch: /.*\.setup\.ts/ },

		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
			dependencies: ['anmeldung'],
		},
	],
})
