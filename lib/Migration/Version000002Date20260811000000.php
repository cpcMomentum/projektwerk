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
 * Kanalschalter werden projektweise — `board_id` kommt dazu.
 *
 * **Warum eine zweite Migration und keine Aenderung an der ersten.** Migration 1
 * ist auf der Entwicklungsinstanz gelaufen. Released Migrationen werden nie
 * editiert, und eine, die irgendwo schon lief, ist fuer diesen Zweck released:
 * Wer sie aendert, bekommt auf jeder Instanz, die sie schon hatte, ein anderes
 * Schema als auf einer frischen.
 *
 * **`board_id = 0` statt `NULL` fuer den globalen Wert.** Der naheliegende Weg
 * waere eine Nullspalte gewesen — er traegt hier aber nicht: In PostgreSQL wie
 * in MySQL gelten `NULL`-Werte in einem eindeutigen Index als **verschieden**.
 * Zwei globale Zeilen fuer dieselbe Person und denselben Kanal waeren damit
 * erlaubt, und welche gilt, entschiede der Zufall. Mit `0` als Sentinel greift
 * die Eindeutigkeit ueberall gleich.
 *
 * Die Aufloesung steht in `NotifyPrefMapper::isEnabled()`: Projektzeile, wenn es
 * eine gibt; sonst die globale Zeile; sonst „an".
 */
class Version000002Date20260811000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Kanalschalter je Projekt';
	}

	#[\Override]
	public function description(): string {
		return 'Add board_id to notify_prefs so notification channels can be set per project.';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_notify_prefs')) {
			return null;
		}

		$table = $schema->getTable('pwerk_notify_prefs');

		if (!$table->hasColumn('board_id')) {
			// `default => 0` ist hier mehr als Bequemlichkeit: Bestehende Zeilen
			// werden damit automatisch zu **globalen** Schaltern, und das ist
			// genau ihre bisherige Bedeutung. Ohne Vorgabewert stuenden sie auf
			// NULL und waeren weder das eine noch das andere.
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'default' => 0,
			]);
		}

		// Der alte Schluessel erlaubte je Person und Kanal genau eine Zeile —
		// mit Projekten braucht es je Person, Kanal **und** Projekt eine.
		if ($table->hasIndex('pwerk_notify_uc_uidx')) {
			$table->dropIndex('pwerk_notify_uc_uidx');
		}
		if (!$table->hasIndex('pwerk_notify_ucb_uidx')) {
			// Indexnamen sind in PostgreSQL schema-global: mit Tabellenpraefix
			// qualifiziert und <= 30 Zeichen.
			$table->addUniqueIndex(['user_id', 'channel', 'board_id'], 'pwerk_notify_ucb_uidx');
		}

		return $schema;
	}
}
