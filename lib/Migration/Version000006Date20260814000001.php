<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Die Fälligkeit ans Ticket (#72).
 *
 * **Warum.** Heute traegt nur der Arbeitsschritt eine Faelligkeit
 * (`pwerk_steps.due_date`), das Ticket keine. Das traegt nicht: Es gibt Themen,
 * die zu einem Datum fertig sein muessen, unabhaengig davon, ob jemand sie in
 * Schritte zerlegt hat. Ein Board, auf dem niemand Schritte anlegt, haette sonst
 * ueberhaupt kein Datum, und eine aus den Schritten abgeleitete Ticket-Faelligkeit
 * haette genau diese Projekte leer ausgehen lassen.
 *
 * **Keine Verdopplung.** Ticket und Schritt behaupten Verschiedenes: das Ticket
 * „bis wann ist die Sache fertig" (Zusage an die Gegenseite), der Schritt „bis
 * wann ist mein Teil fertig" (Zusage einer Person). Ein Ticket zum 30.09. mit
 * einem Schritt zum 15.09. ist normal, nicht widerspruechlich. Es braucht deshalb
 * keine Ableitung und keine Vorrangregel.
 *
 * **Die Faelligkeit teilt die Sichtbarkeit des Tickets** — kein eigenes Feld
 * noetig. Setzen darf sie, wer das Ticket sieht, wie Titel und Beschreibung.
 *
 * `Types::DATE` wie am Schritt: ein Datum ohne Uhrzeit. „Ueberfaellig" ist damit
 * ein Vergleich von Tagen, nicht von Zeitpunkten, und kippt nicht mit der
 * Zeitzone.
 *
 * **Eigene Migration statt Migration 5 erweitern.** `responsible_role` (#114)
 * und `due_date` liegen bewusst getrennt, je Issue eine, damit die Hausregel
 * „released Migrationen nie editieren" nicht am Sonderfall aufweicht.
 */
class Version000006Date20260814000001 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Faelligkeit am Ticket';
	}

	#[\Override]
	public function description(): string {
		return 'Add tickets.due_date so a ticket can carry a deadline independent of its steps (#72).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_tickets')) {
			return null;
		}

		$table = $schema->getTable('pwerk_tickets');

		if (!$table->hasColumn('due_date')) {
			$table->addColumn('due_date', Types::DATE, ['notnull' => false]);
		}

		return $schema;
	}
}
