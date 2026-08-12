<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Schritt 2 von 2: `channel` faellt, der eindeutige Schluessel zieht um.
 *
 * Getrennt von {@see Version000003Date20260812000000}, weil ein Schemaschritt
 * **vor** dem Kopieren laeuft: Wer die Quellspalte im selben Zug abwirft,
 * kopiert aus einer Spalte, die es nicht mehr gibt.
 *
 * Der neue Schluessel traegt denselben Zuschnitt wie der alte — je Person, je
 * Schalter, je Projekt genau eine Zeile. Nur der Spaltenname aendert sich.
 * Indexnamen sind in PostgreSQL schema-global und deshalb mit Tabellenpraefix
 * qualifiziert und hoechstens 30 Zeichen lang.
 */
class Version000004Date20260812000001 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Schalterspalte umbenennen (2/2): channel faellt';
	}

	#[\Override]
	public function description(): string {
		return 'Drop notify_prefs.channel and move the unique key to pref_key.';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_notify_prefs')) {
			return null;
		}

		$table = $schema->getTable('pwerk_notify_prefs');

		// Erst der Schluessel, dann die Spalte: Ein Index auf einer nicht mehr
		// vorhandenen Spalte ist auf manchen Datenbanken ein Fehler, auf
		// anderen eine Leiche.
		if ($table->hasIndex('pwerk_notify_ucb_uidx')) {
			$table->dropIndex('pwerk_notify_ucb_uidx');
		}

		if ($table->hasColumn('channel')) {
			$table->dropColumn('channel');
		}

		if (!$table->hasIndex('pwerk_notify_upb_uidx')) {
			$table->addUniqueIndex(['user_id', 'pref_key', 'board_id'], 'pwerk_notify_upb_uidx');
		}

		return $schema;
	}
}
