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

/** Nur abgeschlossene Vorgänge tragen einen Wert; Wiederöffnen leert ihn. */
class Version000029Date20261110000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Archiv für abgeschlossene Vorgänge (#376)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_tickets.archived_at (nullable) to hide closed tickets from the board (#376).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('pwerk_tickets')) {
			return null;
		}

		$table = $schema->getTable('pwerk_tickets');
		if ($table->hasColumn('archived_at')) {
			return null;
		}
		$table->addColumn('archived_at', Types::DATETIME, ['notnull' => false]);

		return $schema;
	}
}
