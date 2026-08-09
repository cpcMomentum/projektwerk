<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests;

/**
 * Das Verzeichnis aller Lesepfade — die gemeinsame Sprache von Leak-Matrix und
 * Vollstaendigkeitstest.
 *
 * Die Liste ist **von Hand gepflegt**, und das ist der Punkt. Wuerde sie per
 * Reflexion erzeugt, waere sie immer vollstaendig und wuerde nie zubeissen: Ein
 * neuer Lesepfad stuende sofort drin, ohne dass jemand eine Erwartung dazu
 * formuliert haette. Stattdessen erzeugt der Vollstaendigkeitstest die Liste
 * mechanisch und **vergleicht** sie mit dieser — ein nicht eingetragener
 * Lesepfad laesst ihn fallen.
 *
 * Diese Datei haengt bewusst an **keiner** OCP-Klasse. Sie wird sowohl von der
 * containerfreien Unit-Suite als auch von der DB-gestuetzten Integrationssuite
 * geladen.
 */
final class ReadPathRegistry {

	/**
	 * Jede oeffentliche Lesemethode in `lib/Db/`, die die Leak-Matrix faehrt.
	 *
	 * Schluessel ist `KurzerKlassenname::methode` der **konkreten** Klasse, auch
	 * wenn die Methode geerbt ist: Jeder der vier Kinder-Mapper muss einzeln in
	 * der Matrix stehen. Eine Erwartung an `TicketChildMapper` waere eine
	 * Erwartung an niemanden.
	 *
	 * @var string[]
	 */
	public const MAPPER_PATHS = [
		// Die vier Ticket-Lesepfade. Jeder beginnt mit dem Kontext und laeuft
		// ueber TicketScope (§3.1).
		'TicketMapper::findVisibleInBoard',
		'TicketMapper::findVisible',
		'TicketMapper::findVisibleAcrossBoards',
		// Der Deep-Link kennt nur die Ticketnummer, kein Board — deshalb ohne
		// Board-Einschraenkung, aber ueber dieselbe Regel.
		'TicketMapper::findVisibleAnywhere',
		'TicketMapper::countVisibleInBoard',
		// Liest ungefiltert (§3.8) und ist deshalb der einzige Pfad, dessen
		// Erwartung lautet: fuer jeden Betrachter derselbe Wert.
		'TicketMapper::findLastPositionInColumn',

		// Board, Mitglieder, Spalten. Nicht ticketgefiltert, aber
		// betrachterabhaengig — und damit ebenso eine Frage, die die Matrix
		// beantworten muss: Was sieht ein externes Mitglied hier?
		'BoardMapper::findForViewer',
		'BoardMapper::findAllForUser',
		'MemberMapper::findForBoard',
		'ColumnMapper::findForBoard',

		// Die Kinder — je Mapper Liste und Zaehler. Der Zaehler steht hier
		// gleichberechtigt neben der Liste, weil §5.8 ihn ausdruecklich
		// einschliesst: Ein Zaehler, der Verborgenes mitzaehlt, verraet dessen
		// Existenz genauso wie eine Zeile.
		'CommentMapper::findForTickets',
		'CommentMapper::countForTickets',
		'StepMapper::findForTickets',
		'StepMapper::countForTickets',
		'AttachmentMapper::findForTickets',
		'AttachmentMapper::countForTickets',
		'TicketUserMapper::findForTickets',
		'TicketUserMapper::countForTickets',
	];

	/**
	 * Lese-Routen aus `appinfo/routes.php`, die die Matrix faehrt.
	 *
	 * Jede GET-Route steht hier oder in {@see ROUTES_WITHOUT_DATA}; der
	 * Vollstaendigkeitstest laesst jede andere fallen. Was hier steht, wird von
	 * der Leak-Matrix mit **jedem** Betrachter gefahren.
	 *
	 * Der Unterschied zu {@see MAPPER_PATHS} ist nicht bloss die Ebene: Am
	 * Endpunkt greift zusaetzlich `BoardAccess`. Ein Nichtmitglied bekommt hier
	 * 404 — und nicht bloss eine leere Menge wie beim Mapper mit selbst
	 * gebautem Kontext.
	 *
	 * @var string[] Routennamen in der Schreibweise aus routes.php
	 */
	public const ROUTE_PATHS = [
		'board#index',
		'board#show',
		'ticket#index',
		'ticket#show',
		'ticket#visibilityImpact',
		// Liefert zwar nur die Vue-Huelle, aber der Initial State darin
		// beantwortet die Frage „darfst du diesen Vorgang sehen" — und damit
		// gehoert die Route in die Matrix und nicht unter ROUTES_WITHOUT_DATA.
		'deepLink#ticket',
		// Liefert keine Schritte, sondern die Antwort auf „wem darf ich hier
		// etwas geben" — und die folgt aus der Sichtbarkeitsregel.
		'step#assignable',
	];

	/**
	 * GET-Routen, die **keine** fachlichen Daten ausliefern und deshalb nicht in
	 * der Matrix stehen.
	 *
	 * Eine Ausnahme braucht einen Grund, und der Grund steht hier — nicht in
	 * einem Commit-Kommentar. Wer eine Route hier eintraegt, ohne dass der Grund
	 * traegt, tut das sichtbar.
	 *
	 * @var array<string, string> Routenname => Begruendung
	 */
	public const ROUTES_WITHOUT_DATA = [
		'page#index' => 'Liefert die Vue-Huelle aus, keine Ticketdaten. '
			. 'Alle Daten kommen ueber die API-Routen, die einzeln registriert sind.',
	];

	/**
	 * Namenspraefixe, an denen der Vollstaendigkeitstest eine Lesemethode
	 * erkennt.
	 *
	 * Bewusst grosszuegig: Lieber eine Schreibmethode faelschlich als Lesepfad
	 * einsortieren — dann muss jemand sie eintragen oder ausnehmen — als einen
	 * Lesepfad uebersehen. Der teure Fehler ist die Luecke, nicht der Fehlalarm.
	 *
	 * @var string[]
	 */
	public const READ_METHOD_PREFIXES = ['find', 'count', 'get', 'search', 'list', 'fetch'];
}
