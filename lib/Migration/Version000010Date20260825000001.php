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
 * Das Ergebnis einer **Endspalte** (#172).
 *
 * Damit die App weiß, welche Spalte eine Endspalte ist und mit welchem Ergebnis
 * — bisher waren Spalten reiner Freitext. `final_outcome` trägt beides in einem
 * Wert: `null` heißt „keine Endspalte", `done`/`discarded` heißt „Endspalte, die
 * beim Abschließen dieses Ergebnis meint". Zieht jemand eine Karte hinein, bietet
 * die App „Auch abschließen?" an (kein automatisches Schließen).
 *
 * ASCII/englisch wie {@see \OCA\Projektwerk\Db\Ticket}::OUTCOME_* — die deutschen
 * Texte stehen nur in der Anzeige.
 */
class Version000010Date20260825000001 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Endspalten-Ergebnis';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_columns.final_outcome (done/discarded, nullable) to mark end columns and their outcome (#172).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_columns')) {
			return null;
		}

		$table = $schema->getTable('pwerk_columns');

		if (!$table->hasColumn('final_outcome')) {
			$table->addColumn('final_outcome', Types::STRING, ['notnull' => false, 'length' => 16]);
		}

		return $schema;
	}
}
