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

class Version000030Date20261113000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Projekt-Protokoll';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_project_log for member, role and board changes per project.';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('pwerk_project_log')) {
			return null;
		}

		$table = $schema->createTable('pwerk_project_log');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('project_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('actor_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('via', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('target_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('old_value', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('new_value', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['project_id', 'created_at'], 'pwerk_projlog_proj_idx');

		return $schema;
	}
}
