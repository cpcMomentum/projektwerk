<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Bis hierher änderte die Oberfläche nur das Board; die Projektzeile trug noch den Stand von Migration 13. */
class Version000028Date20261109000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'Projektname und Projekt-Archiv aus dem Board-Stand (#379)';
	}

	#[\Override]
	public function description(): string {
		return 'Single-board projects take title, description and archive state from their board; multi-board projects are not archived (#379).';
	}

	/** @param array<string, mixed> $options */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('pwerk_projects') || !$schema->hasTable('pwerk_boards')) {
			return;
		}

		$lese = $this->connection->getQueryBuilder();
		$lese->select('id', 'project_id', 'title', 'description', 'archived')
			->from('pwerk_boards')
			->where($lese->expr()->isNotNull('project_id'));
		$ergebnis = $lese->executeQuery();
		/** @var array<int, list<array{id: int, title: string, description: ?string, archived: int}>> $boardsJeProjekt */
		$boardsJeProjekt = [];
		foreach ($ergebnis->fetchAll() as $row) {
			$boardsJeProjekt[(int)$row['project_id']][] = [
				'id' => (int)$row['id'],
				'title' => (string)$row['title'],
				'description' => $row['description'] === null ? null : (string)$row['description'],
				'archived' => (int)$row['archived'],
			];
		}
		$ergebnis->closeCursor();

		$this->connection->beginTransaction();
		try {
			$einzeln = 0;
			$mehrere = 0;

			foreach ($boardsJeProjekt as $projectId => $boards) {
				if (count($boards) > 1) {
					$mehrere += $this->updateProject($projectId, ['archived' => 0]);
					continue;
				}

				$board = $boards[0];
				$einzeln += $this->updateProject($projectId, [
					'title' => $board['title'],
					'description' => $board['description'],
					'archived' => $board['archived'],
				]);
				if ($board['archived'] === 1) {
					$this->unarchiveBoard($board['id']);
				}
			}

			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}

		$output->info('Projekt-Abgleich: single-board=' . $einzeln . ', multi-board unarchived=' . $mehrere);
	}

	/** @param array{title?: string, description?: ?string, archived: int} $werte */
	private function updateProject(int $projectId, array $werte): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('pwerk_projects')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
		foreach ($werte as $spalte => $wert) {
			$typ = match (true) {
				$wert === null => IQueryBuilder::PARAM_NULL,
				is_int($wert) => IQueryBuilder::PARAM_INT,
				default => IQueryBuilder::PARAM_STR,
			};
			$qb->set($spalte, $qb->createNamedParameter($wert, $typ));
		}

		return $qb->executeStatement();
	}

	private function unarchiveBoard(int $boardId): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('pwerk_boards')
			->set('archived', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
