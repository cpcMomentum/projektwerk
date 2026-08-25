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
 * Das Ergebnis eines Abschlusses: erledigt oder verworfen (#171).
 *
 * Abschliessen kannte bisher nur `closed_at` — offen oder zu, ohne Vorzeichen.
 * `closed_outcome` haelt fest, **ob positiv (erledigt) oder negativ (verworfen)**
 * abgeschlossen wurde, wie Jira (Resolution), Linear (Completed/Canceled) oder
 * GitHub (completed/not planned). Kein dritter Zustand — nur ein Merkmal am
 * bestehenden Abschluss.
 *
 * Nullable, denn ein **offener** Vorgang hat kein Ergebnis, und ein vor dieser
 * Migration **geschlossener** hat keins hinterlegt: `null` heisst „ohne
 * vermerktes Ergebnis" und wird neutral als „abgeschlossen" angezeigt. Der Wert
 * ist ASCII/englisch (`done`/`discarded`), die deutschen Texte stehen nur in der
 * Anzeige — dieselbe Regel wie bei `visibility`.
 */
class Version000009Date20260825000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Abschluss-Ergebnis am Vorgang';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_tickets.closed_outcome (done/discarded, nullable) to record whether a ticket was closed positively or negatively (#171).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_tickets')) {
			return null;
		}

		$table = $schema->getTable('pwerk_tickets');

		if (!$table->hasColumn('closed_outcome')) {
			// `done` oder `discarded` — mit 16 Zeichen bequem gedeckt.
			$table->addColumn('closed_outcome', Types::STRING, ['notnull' => false, 'length' => 16]);
		}

		return $schema;
	}
}
