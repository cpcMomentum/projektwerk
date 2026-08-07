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
		include: ['src/**/*.{spec,test}.{ts,js}', 'tests/ci/**/*.{spec,test}.{ts,js}'],
	},
})
