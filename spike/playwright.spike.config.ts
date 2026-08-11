import { defineConfig, devices } from '@playwright/test'

/**
 * Eigene Konfiguration für die Spikes aus #3 — **Wegwerfcode**.
 *
 * Getrennt von `playwright.config.ts`, damit die Spikes nicht im normalen
 * Testlauf mitlaufen: Sie richten echte Konten und Freigaben ein, beantworten
 * eine Frage und sind danach erledigt. Was sie herausfinden, wandert als Absatz
 * nach `docs/nextcloud-fallstricke.md` — dort überlebt es den Branch, hier
 * nicht.
 */
export default defineConfig({
	testDir: '.',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [['list']],
	use: {
		baseURL: process.env.PWERK_E2E_URL ?? 'http://localhost:8080',
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
		locale: 'de-DE',
		...devices['Desktop Chrome'],
	},
})
