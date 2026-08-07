<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// REST-Routen folgen, z.B.:
		// ['name' => 'board#index',   'url' => '/api/v1/boards',              'verb' => 'GET'],
		// ['name' => 'board#create',  'url' => '/api/v1/boards',              'verb' => 'POST'],
		// ['name' => 'ticket#index',  'url' => '/api/v1/boards/{boardId}/tickets', 'verb' => 'GET'],

		// Hinweis: Deep-Links aus E-Mail und Glocke duerfen NIEMALS ein '#'
		// enthalten — ein Fragment erreicht den Server nie und geht beim
		// Login-Umweg verloren, also genau bei den Gaesten, die den Link am
		// dringendsten brauchen. Dafuer kommt eine fragmentfreie Server-Route
		// mit Rechtepruefung und Weiterleitung, nicht die Hash-Route direkt.
	],
];
