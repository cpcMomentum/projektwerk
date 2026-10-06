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
 * #351 — der Projektordner: der Oberordner, in dem die beiden Vorgangs-Ordner liegen.
 *
 * Wie die Vorgangs-Ordner über die Datei-ID verankert, der Pfad nur zur Anzeige.
 * Rein additiv, beide Spalten nullable, kein Backfill.
 */
class Version000022Date20261007000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Projektordner (#351)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_projects.folder_root_id and folder_root_path (nullable) for the project folder (#351).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_projects')) {
			return null;
		}
		$projects = $schema->getTable('pwerk_projects');
		$added = false;

		if (!$projects->hasColumn('folder_root_id')) {
			$projects->addColumn('folder_root_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$added = true;
		}
		if (!$projects->hasColumn('folder_root_path')) {
			$projects->addColumn('folder_root_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$added = true;
		}

		return $added ? $schema : null;
	}
}
