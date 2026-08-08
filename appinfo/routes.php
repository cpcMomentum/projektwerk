<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Jede GET-Route hier muss in tests/ReadPathRegistry.php stehen —
		// entweder in ROUTE_PATHS mit einer Erwartung je Betrachter in der
		// Leak-Matrix, oder in ROUTES_WITHOUT_DATA mit Begruendung. Der
		// Vollstaendigkeitstest laesst jede nicht registrierte Route fallen.
		['name' => 'board#index', 'url' => '/api/v1/boards', 'verb' => 'GET'],
		['name' => 'board#show', 'url' => '/api/v1/boards/{boardId}', 'verb' => 'GET'],

		// Hinweis: Deep-Links aus E-Mail und Glocke duerfen NIEMALS ein '#'
		// enthalten — ein Fragment erreicht den Server nie und geht beim
		// Login-Umweg verloren, also genau bei den Gaesten, die den Link am
		// dringendsten brauchen. Dafuer kommt eine fragmentfreie Server-Route
		// mit Rechtepruefung und Weiterleitung, nicht die Hash-Route direkt.
	],
];
