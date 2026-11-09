/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { resolve } from 'path'
import { defineConfig, mergeConfig } from 'vite'
import base from './vite.config.ts'

// Eigenes kleines Bundle für die Vorschaukarte: Talk und Text sollen nicht die ganze App laden.
export default defineConfig((env) => mergeConfig(base(env), {
	build: {
		emptyOutDir: false,
		lib: {
			entry: resolve(import.meta.dirname, 'src/reference.ts'),
			name: 'projektwerkReference',
			formats: ['iife'],
			fileName: () => 'js/projektwerk-reference.js',
		},
		rollupOptions: {
			output: {
				assetFileNames: (assetInfo: { name?: string }) => (assetInfo.name?.endsWith('.css') ? 'css/projektwerk-reference.css' : 'css/[name][extname]'),
			},
		},
	},
}))
