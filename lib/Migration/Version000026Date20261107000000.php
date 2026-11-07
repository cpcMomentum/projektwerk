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
 * Abhängigkeiten zwischen Vorgängen desselben Boards: `ticket_id` wartet auf `depends_on_id`.
 */
class Version000026Date20261107000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Abhängigkeiten zwischen Vorgängen (#346)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_ticket_deps: a ticket can depend on other tickets of the same board (#346).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('pwerk_ticket_deps')) {
			return null;
		}

		$table = $schema->createTable('pwerk_ticket_deps');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('depends_on_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_via', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['ticket_id', 'depends_on_id'], 'pwerk_tdeps_pair_uidx');
		$table->addIndex(['depends_on_id'], 'pwerk_tdeps_dep_idx');

		return $schema;
	}
}
