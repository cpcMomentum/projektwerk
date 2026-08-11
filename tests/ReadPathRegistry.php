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
		// „Meine Arbeitsschritte" — dieselbe Regel, andere Menge als
		// `findVisibleAcrossBoards`: Ein Schritt kann mir an einem Vorgang
		// gehoeren, an dem ich weder verantwortlich noch mitarbeitend bin.
		// Die Erwartung dazu ist `testMyStepsNeverWidensBeyondTheVisibleSet`.
		'TicketMapper::findVisibleWithMyOpenSteps',
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

		// **Zwei Lesepfade ohne Betrachter — und das ist die Erwartung.**
		//
		// Sie stehen hier wie jeder andere, aber die Matrix beantwortet fuer sie
		// eine andere Frage. „Was sieht die Kundenseite hier?" hat keinen Sinn:
		// Am Ende des Ausgangskorbs steht ein Hintergrundjob, am Ende der
		// Kanalschalter die Person selbst. Kein Board, keine Rolle, keine
		// Sichtbarkeit.
		//
		// Statt sie freizustellen, traegt die Matrix eine **Behauptung**:
		// `testTheseMappersNeverTakeAViewer` prueft, dass keine ihrer Methoden
		// einen ViewerContext annimmt. Wer spaeter eine betrachterabhaengige
		// Abfrage nachtraegt, macht den Test rot — und muss dann eine echte
		// Erwartung formulieren.
		//
		// Der Unterschied zu einer Ausnahmeliste: Eine Ausnahme sagt „hier gilt
		// die Regel nicht". Das hier sagt „hier gilt sie, und zwar so".
		'MailOutboxMapper::findRetryable',
		// `NotifyPrefMapper::isEnabled()` steht hier bewusst **nicht**: Es fragt
		// die Datenbank nicht selbst, sondern ruft `findForUser()` auf. Der
		// Lesepfad ist der eine darunter; ein zweiter Eintrag waere eine
		// Erwartung an eine Abfrage, die es nicht gibt.
		'NotifyPrefMapper::findForUser',
	];

	/**
	 * Die Mapper aus {@see MAPPER_PATHS}, deren Erwartung strukturell ist statt
	 * datenbezogen.
	 *
	 * Siehe die Begruendung bei den Eintraegen selbst. Diese Liste ist der
	 * Eingang fuer `testTheseMappersNeverTakeAViewer`.
	 *
	 * @var string[] Kurze Klassennamen aus `lib/Db/`
	 */
	public const VIEWERLESS_MAPPERS = [
		'MailOutboxMapper',
		'NotifyPrefMapper',
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
		// Die einzige Leseroute ohne Board im Pfad. Gerade deshalb steht sie
		// hier: Wo kein `BoardAccess` davor haengt, traegt die Regel im JOIN
		// allein — und ein Nichtmitglied bekommt kein 404, sondern zwei leere
		// Listen. Der Unterschied gehoert belegt.
		'task#index',
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
		// Liefert Nextcloud-Konten, keine Projektdaten — aber es ist ein
		// Lesepfad mit Rechtepruefung, und die gehoert in die Matrix.
		'memberSearch#search',
		// **Kein Board im Pfad — und trotzdem in der Matrix.** Die Route
		// liefert keine Projektdaten, sondern die eigenen Kanalschalter. Die
		// Erwartung ist deshalb eine andere als sonst und trotzdem eine echte:
		// Jeder Betrachter sieht **nur seine eigenen** Schalter, nie fremde.
		// Sie hier wegzulassen hiesse, eine Leseroute ohne Erwartung zu haben —
		// genau das, was der Vollstaendigkeitstest verhindert.
		'notifyPref#index',
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
