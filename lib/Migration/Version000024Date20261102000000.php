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
 * Herkunft über KI-Werkzeug: `created_via` am Neuangelegten, `actor_via` an der vorgemerkten Mail.
 */
class Version000024Date20261102000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Herkunft über KI-Werkzeug (#389)';
	}

	#[\Override]
	public function description(): string {
		return 'Add nullable created_via to tickets, comments, steps, attachments and actor_via to the mail outbox (#389).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$added = false;

		if ($schema->hasTable('pwerk_tickets')) {
			$table = $schema->getTable('pwerk_tickets');
			if (!$table->hasColumn('created_via')) {
				$table->addColumn('created_via', Types::STRING, ['notnull' => false, 'length' => 16]);
				$added = true;
			}
		}

		if ($schema->hasTable('pwerk_comments')) {
			$table = $schema->getTable('pwerk_comments');
			if (!$table->hasColumn('created_via')) {
				$table->addColumn('created_via', Types::STRING, ['notnull' => false, 'length' => 16]);
				$added = true;
			}
		}

		if ($schema->hasTable('pwerk_steps')) {
			$table = $schema->getTable('pwerk_steps');
			if (!$table->hasColumn('created_via')) {
				$table->addColumn('created_via', Types::STRING, ['notnull' => false, 'length' => 16]);
				$added = true;
			}
		}

		if ($schema->hasTable('pwerk_attachments')) {
			$table = $schema->getTable('pwerk_attachments');
			if (!$table->hasColumn('created_via')) {
				$table->addColumn('created_via', Types::STRING, ['notnull' => false, 'length' => 16]);
				$added = true;
			}
		}

		if ($schema->hasTable('pwerk_mail_outbox')) {
			$table = $schema->getTable('pwerk_mail_outbox');
			if (!$table->hasColumn('actor_via')) {
				$table->addColumn('actor_via', Types::STRING, ['notnull' => false, 'length' => 16]);
				$added = true;
			}
		}

		return $added ? $schema : null;
	}
}
