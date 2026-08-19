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
		['name' => 'board#setPin', 'url' => '/api/v1/boards/{boardId}/pin', 'verb' => 'PUT'],

		// „Meine Aufgaben" — die einzige GET-Route ohne Board im Pfad. Sie
		// gehoert zu allen Projekten, und die Rolle bildet `TicketScope` je
		// Board ueber den Verbund auf `pwerk_members`. Steht in der
		// Leak-Matrix wie jede andere Leseroute.
		['name' => 'task#index', 'url' => '/api/v1/tasks', 'verb' => 'GET'],
		// Der Einstieg (#76). Wie `task#index` ohne Board im Pfad — die Ansicht
		// gehoert zu allen Projekten, nicht zu einem.
		['name' => 'overview#index', 'url' => '/api/v1/overview', 'verb' => 'GET'],

		['name' => 'ticket#index', 'url' => '/api/v1/boards/{boardId}/tickets', 'verb' => 'GET'],
		['name' => 'ticket#show', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}', 'verb' => 'GET'],
		['name' => 'ticket#create', 'url' => '/api/v1/boards/{boardId}/tickets', 'verb' => 'POST'],
		['name' => 'ticket#update', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}', 'verb' => 'PATCH'],
		['name' => 'ticket#read', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/read', 'verb' => 'POST'],
		// Verschieben und Sichtbarkeit sind eigene Wege, keine Felder im
		// PATCH: Beide haben eigene Regeln (Nachbar-IDs bzw. die besitzende
		// Seite), und ein Sammel-Update waere die Stelle, an der die Regel beim
		// naechsten Feld vergessen wird.
		// Weiches Loeschen: setzt `deleted_at`, das TicketScope aus jeder
		// Abfrage nimmt. Kein Papierkorb in der App — Wiederherstellen per occ.
		['name' => 'ticket#destroy', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}', 'verb' => 'DELETE'],
		// Wiederherstellen (#167, Undo): das Gegenstueck zu destroy. Setzt
		// `deleted_at` zurueck auf null; ohne `version`, weil idempotent.
		['name' => 'ticket#restore', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/restore', 'verb' => 'POST'],
		['name' => 'ticket#move', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/move', 'verb' => 'POST'],
		['name' => 'ticket#visibility', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/visibility', 'verb' => 'PUT'],

		// Arbeitsschritte. Gelesen werden sie ueber ticket#index und
		// ticket#show, aus derselben gefilterten Ticketmenge — deshalb stehen
		// hier nur Schreibwege. Ausnahme mit Grund: `assignable` beantwortet
		// „wem darf ich hier etwas geben", und diese Frage folgt aus der
		// Sichtbarkeitsregel; sie steht deshalb in der Leak-Matrix.
		['name' => 'step#assignable', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/assignable', 'verb' => 'GET'],
		// Derselbe Picker fuer einen noch nicht angelegten Vorgang (#146): board-,
		// nicht ticketgebunden, `visibility` als Abfrageparameter. Folgt derselben
		// Sichtbarkeitsregel und steht deshalb ebenfalls in der Leak-Matrix.
		['name' => 'step#assignableForNew', 'url' => '/api/v1/boards/{boardId}/assignable-new', 'verb' => 'GET'],
		['name' => 'step#create', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/steps', 'verb' => 'POST'],
		['name' => 'step#update', 'url' => '/api/v1/boards/{boardId}/steps/{stepId}', 'verb' => 'PATCH'],

		// Kommentare. Wie die Arbeitsschritte nur Schreibwege — gelesen werden
		// sie ueber ticket#show aus der gefilterten Ticketmenge. Sie haben keine
		// eigene Sichtbarkeit, sondern erben die des Tickets vollstaendig.
		// Aendern und Loeschen kann nur die verfassende Person, ohne Ausnahme
		// fuer Eigentuemer oder Verwalter.
		['name' => 'comment#create', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/comments', 'verb' => 'POST'],
		['name' => 'comment#update', 'url' => '/api/v1/boards/{boardId}/comments/{commentId}', 'verb' => 'PATCH'],
		// Hart, kein weiches Loeschen: `pwerk_comments` hat kein `deleted_at`,
		// und ein Verlauf mit unsichtbaren Luecken waere schlechter als einer
		// ohne.
		['name' => 'comment#destroy', 'url' => '/api/v1/boards/{boardId}/comments/{commentId}', 'verb' => 'DELETE'],

		// Anhaenge. Zwei Schreibwege und **kein Leseweg**: Die Liste kommt ueber
		// ticket#show mit, und die Datei selbst holt der Browser bei Nextcloud.
		// Ein eigener Downloadweg waere ein zweiter Ort, an dem der
		// Dateizugriff stimmen muesste.
		['name' => 'attachment#create', 'url' => '/api/v1/boards/{boardId}/tickets/{ticketId}/attachments', 'verb' => 'POST'],
		// „destroy" loest die Verknuepfung — die Datei bleibt liegen, die App
		// loescht nie (§5.18).
		['name' => 'attachment#destroy', 'url' => '/api/v1/boards/{boardId}/attachments/{attachmentId}', 'verb' => 'DELETE'],

		// **Die eigenen Kanalschalter — ohne Board im Pfad.** Die Grenze ist die
		// Benutzerkennung aus der Sitzung; ein `boardId` davor taeuschte eine
		// Rechtepruefung vor, die es hier nicht braucht.
		['name' => 'notifyPref#index', 'url' => '/api/v1/notify-prefs', 'verb' => 'GET'],
		['name' => 'notifyPref#update', 'url' => '/api/v1/notify-prefs', 'verb' => 'PUT'],
		['name' => 'notifyPref#clearOverrides', 'url' => '/api/v1/notify-prefs/overrides', 'verb' => 'DELETE'],

		// Der eigene Ordner fuer private Anhaenge (#184, Phase B) — ebenfalls
		// user-scoped, ohne Board im Pfad.
		['name' => 'privateFolder#index', 'url' => '/api/v1/my/private-folder', 'verb' => 'GET'],
		['name' => 'privateFolder#update', 'url' => '/api/v1/my/private-folder', 'verb' => 'PUT'],

		// Board-Einstellungen. Ausschliesslich Schreibwege — gelesen wird ueber
		// board#show, das Board, Mitglieder und Spalten in einem Zug liefert und
		// in der Leak-Matrix registriert ist.
		['name' => 'settings#createBoard', 'url' => '/api/v1/boards', 'verb' => 'POST'],
		['name' => 'settings#updateBoard', 'url' => '/api/v1/boards/{boardId}', 'verb' => 'PATCH'],
		['name' => 'settings#archiveBoard', 'url' => '/api/v1/boards/{boardId}/archived', 'verb' => 'PUT'],

		['name' => 'settings#createColumn', 'url' => '/api/v1/boards/{boardId}/columns', 'verb' => 'POST'],
		['name' => 'settings#renameColumn', 'url' => '/api/v1/boards/{boardId}/columns/{columnId}', 'verb' => 'PATCH'],
		['name' => 'settings#reorderColumns', 'url' => '/api/v1/boards/{boardId}/columns/order', 'verb' => 'PUT'],
		// Entfernen heisst hier verschieben: Die Zielspalte ist Pflicht, und
		// alle Vorgaenge wandern dorthin — auch die, die der Loeschende nicht
		// sehen darf. Eine Rueckfrage mit Zahl koennte sonst nur zwischen
		// „verraet Verborgenes" und „loescht mehr als angekuendigt" waehlen.
		['name' => 'settings#deleteColumn', 'url' => '/api/v1/boards/{boardId}/columns/{columnId}', 'verb' => 'DELETE'],

		// Konten suchen, um sie hinzuzufuegen. Eigener Endpunkt statt
		// Nextclouds Personensuche — die liefert in Gast-Sitzungen eine leere
		// Liste, und ein Sucher mit dieser Eigenschaft im Frontend waere
		// irgendwann an eine Stelle kopiert, wo Gaeste hinkommen.
		['name' => 'memberSearch#search', 'url' => '/api/v1/boards/{boardId}/member-search', 'verb' => 'GET'],

		// Personenweise, keine Gruppen (§8): Die Rolle haengt an der
		// Mitgliedschaft, nicht am Nextcloud-Konto.
		['name' => 'settings#addMember', 'url' => '/api/v1/boards/{boardId}/members', 'verb' => 'POST'],
		['name' => 'settings#updateMember', 'url' => '/api/v1/boards/{boardId}/members/{userId}', 'verb' => 'PATCH'],
		// Entfernen (§5.29): erst die bezifferte Vorschau (GET), dann das
		// Entfernen selbst (DELETE). Die Vorschau nennt, wie viele private
		// Vorgaenge der Person dabei geloescht wuerden.
		['name' => 'settings#memberRemovalImpact', 'url' => '/api/v1/boards/{boardId}/members/{userId}/removal-impact', 'verb' => 'GET'],
		['name' => 'settings#removeMember', 'url' => '/api/v1/boards/{boardId}/members/{userId}', 'verb' => 'DELETE'],

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
