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
	},
})
