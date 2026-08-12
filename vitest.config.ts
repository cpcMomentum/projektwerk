import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	resolve: {
		alias: {
			'@': resolve(import.meta.dirname, 'src'),
		},
	},
	test: {
		environment: 'happy-dom',
		// tests/ci/ enthaelt Waechter fuer die CI selbst (z. B. den
		// Issue-Schliesser), die keinen Platz unter src/ haben.
		//
		// **Die Liste ist abschliessend, und das ist wichtig.** Unter
		// tests/e2e/ liegen Playwright-Specs, die ebenfalls `*.spec.ts` heissen
		// (#80). Vitest wuerde sie beim Standardmuster einsammeln und an
		// `import { test } from '@playwright/test'` scheitern — mit einem
		// Fehlerbild, das nach kaputtem Testcode aussieht und keines ist. Wer
		// hier ein Muster ergaenzt, prueft bitte, dass tests/e2e/ draussen
		// bleibt.
		include: ['src/**/*.{spec,test}.{ts,js}', 'tests/ci/**/*.{spec,test}.{ts,js}'],
		/*
		 * `@nextcloud/vue` wird mitverarbeitet statt als fertiges Paket geladen.
		 *
		 * Einige seiner Komponenten — `NcRadioGroup`, `NcCheckboxRadioSwitch`,
		 * `NcSelectUsers` — bringen eine eigene `.css` mit und importieren sie
		 * aus dem JavaScript heraus. Ausserhalb der Vite-Verarbeitung landet
		 * dieser Import bei Node, und Node kennt die Endung nicht: „Unknown file
		 * extension .css", noch bevor ein einziger Test laeuft.
		 *
		 * Das Fehlerbild ist irrefuehrend — es sieht nach kaputtem Testcode aus
		 * und ist eine Frage der Aufloesung. Aufgefallen mit #99, als die ersten
		 * dieser Komponenten in Gebrauch kamen.
		 */
		server: {
			deps: {
				inline: ['@nextcloud/vue'],
			},
		},
	},
})
