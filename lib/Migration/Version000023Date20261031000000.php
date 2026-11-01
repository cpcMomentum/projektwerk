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

/**
 * Markiert auf Bestandsboards ohne jede Endspalte die Vorgabespalten als Endspalten.
 *
 * Boards mit selbst gesetzter Endspalte bleiben unberührt; Titel de/en, weil
 * `BoardService::insertDefaultColumns()` sie in der Sprache des Anlegenden speichert.
 */
class Version000023Date20261031000000 extends SimpleMigrationStep {

	// Eigene Kopie statt BoardService-Verweis: eine gelaufene Migration darf sich nicht mitändern.
	private const TITLE_OUTCOMES = [
		'Erledigt' => 'done',
		'Done' => 'done',
		'Verworfen' => 'discarded',
		'Discarded' => 'discarded',
	];

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'Endspalten für Bestandsboards (#393)';
	}

	#[\Override]
	public function description(): string {
		return 'Mark the default columns Erledigt/Done and Verworfen/Discarded as end columns on boards without any end column (#393).';
	}

	/**
	 * @param IOutput $output Fortschrittsausgabe.
	 * @param Closure $schemaClosure Liefert den Schema-Wrapper.
	 * @param array<string, mixed> $options Optionen des Migrationslaufs.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('pwerk_columns')
			|| !$schema->getTable('pwerk_columns')->hasColumn('final_outcome')) {
			return;
		}

		$lese = $this->connection->getQueryBuilder();
		$lese->select('id', 'board_id', 'title', 'final_outcome')
			->from('pwerk_columns')
			->orderBy('board_id')
			->addOrderBy('position')
			->addOrderBy('id');
		$ergebnis = $lese->executeQuery();
		/** @var array<int, list<array{id: int, title: string, final_outcome: ?string}>> $spaltenJeBoard */
		$spaltenJeBoard = [];
		foreach ($ergebnis->fetchAll() as $row) {
			$spaltenJeBoard[(int)$row['board_id']][] = [
				'id' => (int)$row['id'],
				'title' => (string)$row['title'],
				'final_outcome' => $row['final_outcome'],
			];
		}
		$ergebnis->closeCursor();

		$this->connection->beginTransaction();
		try {
			$boardsSet = 0;
			$columnsSet = 0;

			foreach ($spaltenJeBoard as $spalten) {
				foreach ($spalten as $spalte) {
					if ($spalte['final_outcome'] !== null && $spalte['final_outcome'] !== '') {
						continue 2;
					}
				}

				$vergeben = [];
				foreach ($spalten as $spalte) {
					$outcome = self::TITLE_OUTCOMES[trim($spalte['title'])] ?? null;
					if ($outcome === null || isset($vergeben[$outcome])) {
						continue;
					}
					$vergeben[$outcome] = true;
					$columnsSet += $this->setOutcome($spalte['id'], $outcome);
				}
				if ($vergeben !== []) {
					$boardsSet++;
				}
			}

			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}

		$output->info('Endspalten-Backfill: boards=' . $boardsSet . ', columns=' . $columnsSet);
	}

	/** @return int Zahl der geänderten Zeilen. */
	private function setOutcome(int $columnId, string $outcome): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('pwerk_columns')
			->set('final_outcome', $qb->createNamedParameter($outcome))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($columnId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('final_outcome'));

		return $qb->executeStatement();
	}
}
