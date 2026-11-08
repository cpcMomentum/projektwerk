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
 * Verlauf je Vorgang: wer wann was geändert hat, mit Herkunft und der Sichtbarkeit im Moment der Änderung.
 */
class Version000027Date20261107120000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Verlauf je Vorgang (#397)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_ticket_log: one row per change of a ticket, with actor, origin and the visibility at that moment (#397).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('pwerk_ticket_log')) {
			return null;
		}

		$table = $schema->createTable('pwerk_ticket_log');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('actor_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('via', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('old_value', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('new_value', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('visibility', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['ticket_id'], 'pwerk_tlog_ticket_idx');

		return $schema;
	}
}
