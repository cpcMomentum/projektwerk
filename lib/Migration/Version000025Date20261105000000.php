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
 * Kennung je Anlage: Eine wiederholte Anfrage mit derselben Kennung legt nicht ein zweites Mal an.
 */
class Version000025Date20261105000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Kennung je Anlage (#400)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_idem so a repeated create with the same Idempotency-Key returns the first result instead of a duplicate (#400).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('pwerk_idem')) {
			return null;
		}

		$table = $schema->createTable('pwerk_idem');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('idem_key', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('response', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		// Der Index ist die eigentliche Sperre: Zwei gleichzeitige Anfragen mit derselben Kennung, nur eine kommt durch.
		$table->addUniqueIndex(['user_id', 'idem_key'], 'pwerk_idem_uk_uidx');
		$table->addIndex(['created_at'], 'pwerk_idem_created_idx');

		return $schema;
	}
}
