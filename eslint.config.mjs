/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @nextcloud/eslint-config 9 hat KEINEN Default-Export. Es exportiert benannte
// Flat-Config-Arrays: recommended (Vue 3), recommendedJavascript,
// recommendedLibrary sowie die recommendedVue2*-Varianten fuer Altprojekte.
// `recommended` ist die Vue-3-Variante und bringt 39 Configs mit, darunter
// typescript-eslint, vue/recommended und die nextcloud/*-Regeln.
//
// Die Config liest .gitignore ein (nextcloud/filesystem/gitignore) und wirft
// "No .gitignore file found", wenn die Datei fehlt — sie ist also Voraussetzung,
// nicht Beiwerk.
import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		// Build-Artefakte: liegen im Repo, sind aber generiert.
		ignores: ['js/', 'css/', 'dist/', 'vendor/', 'l10n/'],
	},
]
