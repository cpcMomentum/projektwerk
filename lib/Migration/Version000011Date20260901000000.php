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
 * Beschreibung und Ergebnis am Arbeitsschritt (#247).
 *
 * Der Schritt trug bisher nur einen Titel. `description` hält fest, was zu tun
 * ist; `result` nimmt auf, was dabei herauskam. Beide sind `TEXT` (wie
 * `pwerk_tickets.description`) — kein `VARCHAR(255)`: Dass die Beschreibung
 * „eine Zeile" ist, erzwingt das einzeilige Eingabefeld, nicht eine Längengrenze
 * in der Datenbank. So kann ein eingefügter Absatz keinen 500er auf Postgres
 * auslösen.
 *
 * Beide erben die Sichtbarkeit des Vorgangs. Ein Schritt wird nie eigenständig
 * abgefragt, sondern immer über die gefilterte Ticketmenge (`findForTickets`) —
 * deshalb braucht keines der beiden Felder eine eigene Sichtbarkeitsregel.
 */
class Version000011Date20260901000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Schritt: Beschreibung und Ergebnis';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_steps.description (one line) and result (multiline); both inherit the ticket visibility (#247).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_steps')) {
			return null;
		}

		$table = $schema->getTable('pwerk_steps');

		if (!$table->hasColumn('description')) {
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		}

		if (!$table->hasColumn('result')) {
			$table->addColumn('result', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
