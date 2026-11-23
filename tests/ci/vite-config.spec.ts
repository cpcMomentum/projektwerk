import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import viteConfig from '../../vite.config.ts'

const ROOT = resolve(import.meta.dirname, '../..')

function define(): Record<string, unknown> {
	const config = typeof viteConfig === 'function'
		? viteConfig({ mode: 'production', command: 'build', isSsrBuild: false, isPreview: false })
		: viteConfig
	return (config as { define: Record<string, unknown> }).define
}

describe('vite.config: Werte für @nextcloud/vue', () => {
	it('appName ist die App-ID aus appinfo/info.xml', () => {
		const id = readFileSync(resolve(ROOT, 'appinfo/info.xml'), 'utf8').match(/<id>([^<]+)<\/id>/)?.[1]

		expect(define().appName).toBe(JSON.stringify(id))
	})

	it('appVersion ist die Version aus appinfo/info.xml', () => {
		const version = readFileSync(resolve(ROOT, 'appinfo/info.xml'), 'utf8').match(/<version>([^<]+)<\/version>/)?.[1]

		expect(define().appVersion).toBe(JSON.stringify(version))
	})
})
