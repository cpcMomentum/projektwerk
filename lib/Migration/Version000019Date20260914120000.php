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
 * #281 — Mitglieder dürfen Boards im Projekt anlegen.
 *
 * Zwei Spalten, rein additiv:
 *
 * - `pwerk_projects.member_boards_allowed` — der Projekt-Schalter: ist er an,
 *   darf jedes Mitglied (intern wie extern) ein weiteres Board im Projekt
 *   anlegen. `SMALLINT` 0/1 mit Default 0, **nicht** `Types::BOOLEAN` mit
 *   `notnull` (erzeugt Schema-Fehler; PARAM_BOOL schreibt auf PostgreSQL 'f'
 *   statt 0).
 * - `pwerk_boards.created_by` — wer das Board angelegt hat. Trägt das
 *   board-scopes **Ersteller-Recht** (Spalten pflegen, umbenennen, archivieren
 *   für genau dieses Board), abgeleitet in
 *   {@see \OCA\Projektwerk\Access\ViewerContext::$isBoardCreator}. Nullable:
 *   Altbestand hat keinen vermerkten Ersteller — dort richtet nur der Manager
 *   ein (unverändertes Verhalten).
 */
class Version000019Date20260914120000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'member board creation flag and board creator (#281)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_projects.member_boards_allowed and pwerk_boards.created_by for member-created boards (#281).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('pwerk_projects')) {
			$projects = $schema->getTable('pwerk_projects');
			if (!$projects->hasColumn('member_boards_allowed')) {
				$projects->addColumn('member_boards_allowed', Types::SMALLINT, [
					'notnull' => true,
					'default' => 0,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('pwerk_boards')) {
			$boards = $schema->getTable('pwerk_boards');
			if (!$boards->hasColumn('created_by')) {
				$boards->addColumn('created_by', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
