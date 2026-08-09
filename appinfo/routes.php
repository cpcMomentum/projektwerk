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

		['name' => 'ticket#index', 'url' => '/api/v1/boards/{boardId}/tickets', 'verb' => 'GET'],
		['name' => 'ticket#show', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}', 'verb' => 'GET'],
		['name' => 'ticket#create', 'url' => '/api/v1/boards/{boardId}/tickets', 'verb' => 'POST'],
		['name' => 'ticket#update', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}', 'verb' => 'PATCH'],
		// Verschieben und Sichtbarkeit sind eigene Wege, keine Felder im
		// PATCH: Beide haben eigene Regeln (Nachbar-IDs bzw. die besitzende
		// Seite), und ein Sammel-Update waere die Stelle, an der die Regel beim
		// naechsten Feld vergessen wird.
		['name' => 'ticket#move', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/move', 'verb' => 'POST'],
		['name' => 'ticket#visibilityImpact', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/visibility-impact', 'verb' => 'GET'],
		['name' => 'ticket#visibility', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/visibility', 'verb' => 'PUT'],

		// Arbeitsschritte. Gelesen werden sie ueber ticket#index und
		// ticket#show, aus derselben gefilterten Ticketmenge — deshalb stehen
		// hier nur Schreibwege. Ausnahme mit Grund: `assignable` beantwortet
		// „wem darf ich hier etwas geben", und diese Frage folgt aus der
		// Sichtbarkeitsregel; sie steht deshalb in der Leak-Matrix.
		['name' => 'step#assignable', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/assignable', 'verb' => 'GET'],
		['name' => 'step#create', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/steps', 'verb' => 'POST'],
		['name' => 'step#update', 'url' => '/api/v1/boards/{boardId}/steps/{stepId}', 'verb' => 'PATCH'],

		// Board-Einstellungen. Ausschliesslich Schreibwege — gelesen wird ueber
		// board#show, das Board, Mitglieder und Spalten in einem Zug liefert und
		// in der Leak-Matrix registriert ist.
		['name' => 'settings#createBoard', 'url' => '/api/v1/boards', 'verb' => 'POST'],
		['name' => 'settings#updateBoard', 'url' => '/api/v1/boards/{boardId}', 'verb' => 'PATCH'],
		['name' => 'settings#archiveBoard', 'url' => '/api/v1/boards/{boardId}/archived', 'verb' => 'PUT'],

		['name' => 'settings#createColumn', 'url' => '/api/v1/boards/{boardId}/columns', 'verb' => 'POST'],
		['name' => 'settings#renameColumn', 'url' => '/api/v1/boards/{boardId}/columns/{columnId}', 'verb' => 'PATCH'],
		['name' => 'settings#reorderColumns', 'url' => '/api/v1/boards/{boardId}/columns/order', 'verb' => 'PUT'],

		// Personenweise, keine Gruppen (§8): Die Rolle haengt an der
		// Mitgliedschaft, nicht am Nextcloud-Konto.
		['name' => 'settings#addMember', 'url' => '/api/v1/boards/{boardId}/members', 'verb' => 'POST'],
		['name' => 'settings#updateMember', 'url' => '/api/v1/boards/{boardId}/members/{userId}', 'verb' => 'PATCH'],

		// Deep-Links aus E-Mail und Glocke duerfen NIEMALS ein '#' enthalten —
		// ein Fragment erreicht den Server nie und geht beim Login-Umweg
		// verloren, also genau bei den Gaesten, die den Link am dringendsten
		// brauchen. Deshalb diese Route: fragmentfrei, mit der Kennung im
		// **Pfad** (kein '@' in Pfad oder Query, sonst verwirft Nextclouds
		// Login-Controller das Ruecksprungziel stillschweigend).
		//
		// Sie liefert dieselbe Huelle wie page#index und legt das Ziel in den
		// Initial State — kein Redirect auf die Hash-Route, der wuerde die
		// Anmeldung ein zweites Mal durchlaufen und das Fragment am selben
		// Punkt wieder verlieren.
		['name' => 'deepLink#ticket', 'url' => '/t/{ticketId}', 'verb' => 'GET'],
	],
];
