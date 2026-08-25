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
 * Das Ziel-Repository der GitHub-Überführung (#12, Stufe 1).
 *
 * `github_enabled` liegt seit der Ur-Migration am Board — der Schalter „ist
 * dieses Projekt ein Softwareprojekt". Wohin ein überführter Vorgang als Issue
 * wandert, stand bisher nirgends: genau diese Spalte. `owner/repo`, nullable,
 * denn ein aktiviertes Board ohne gesetztes Repo ist ein zulässiger
 * Zwischenzustand — die Überführungs-Aktion bleibt dann schlicht aus, bis eine
 * verwaltende Person das Repo einträgt.
 *
 * **Kein Secret hier.** Der GitHub-Token liegt pro Person verschlüsselt in
 * Nextclouds `ICredentialsManager`, nicht am Board. Das Repo ist eine reine
 * Adresse, vergleichbar mit `chat_url`.
 */
class Version000008Date20260819000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Ziel-Repository der GitHub-Überführung';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_boards.github_repo so a board can target a GitHub repository for issue transfer (#12).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_boards')) {
			return null;
		}

		$table = $schema->getTable('pwerk_boards');

		if (!$table->hasColumn('github_repo')) {
			// `owner/repo` ist mit 255 Zeichen bequem gedeckt: GitHub begrenzt
			// den Kontonamen auf 39 und den Repo-Namen auf 100 Zeichen.
			$table->addColumn('github_repo', Types::STRING, ['notnull' => false, 'length' => 255]);
		}

		return $schema;
	}
}
