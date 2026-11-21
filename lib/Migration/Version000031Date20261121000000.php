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

class Version000031Date20261121000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Verknüpfte Dateien und Ordner';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_file_links: files and folders linked to a ticket at their original location.';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('pwerk_file_links')) {
			return null;
		}

		$table = $schema->createTable('pwerk_file_links');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_via', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['ticket_id', 'file_id'], 'pwerk_flinks_tkt_file_uq');

		return $schema;
	}
}
