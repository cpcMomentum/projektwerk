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
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * #246 PR 0 — Projekt-Dach über den Boards (reiner Spike).
 *
 * Legt `pwerk_projects` an und hängt jedem Board über `project_id` ein Projekt
 * an. **Backfill: je Board genau ein Projekt** — deterministisch, weil heute
 * 1 Board = 1 Projekt. Die Engagement-Felder (Owner, Organisationen, Ordner,
 * Chat, Zähler, Archiv, Titel/Beschreibung) werden dabei aus dem Board
 * **kopiert**; sie **leben vorerst weiter am Board** und werden von der App
 * unverändert dort gelesen. Erst spätere PRs verschieben die Herkunft.
 *
 * **Kein Lesepfad ändert sich** — `TicketScope`, die Mapper und die Controller
 * bleiben unberührt, die Leak-Matrix ist per Konstruktion grün. Das ist der
 * Sinn dieser Stufe: Migration und Backfill auf der Produktivinstanz beweisen,
 * ohne die Sichtbarkeit anzufassen. Rücknahme = die ungenutzten Spalten fallen
 * lassen.
 *
 * Der Backfill läuft **idempotent** (nur Boards ohne `project_id`), ein zweiter
 * Lauf legt nichts doppelt an.
 */
class Version000013Date20260901020000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'Projekt-Dach anlegen (#246 PR 0)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_projects and boards.project_id; backfill one project per board (fields copied, still read from the board). No read path changes (#246).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_projects')) {
			$projects = $schema->createTable('pwerk_projects');
			$projects->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			// Spaltentypen 1:1 wie `pwerk_boards` (Migration 1) — die Felder
			// werden von dort kopiert und wandern später ganz hierher.
			$projects->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$projects->addColumn('description', Types::TEXT, ['notnull' => false]);
			$projects->addColumn('owner_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$projects->addColumn('org_internal', Types::STRING, ['notnull' => false, 'length' => 128]);
			$projects->addColumn('org_external', Types::STRING, ['notnull' => false, 'length' => 128]);
			$projects->addColumn('folder_public_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$projects->addColumn('folder_public_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$projects->addColumn('folder_internal_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$projects->addColumn('folder_internal_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$projects->addColumn('chat_url', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$projects->addColumn('ticket_counter', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$projects->addColumn('archived', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$projects->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$projects->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$projects->setPrimaryKey(['id']);
			$projects->addIndex(['owner_user_id'], 'pwerk_projects_owner_idx');
		}

		if ($schema->hasTable('pwerk_boards')) {
			$boards = $schema->getTable('pwerk_boards');
			if (!$boards->hasColumn('project_id')) {
				// Nullable — im selben Migrationslauf über den Backfill gefüllt.
				// NOT NULL käme erst, wenn kein Board mehr ohne Projekt ist.
				$boards->addColumn('project_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			}
			if (!$boards->hasIndex('pwerk_boards_project_idx')) {
				$boards->addIndex(['project_id'], 'pwerk_boards_project_idx');
			}
		}

		return $schema;
	}

	/**
	 * Backfill: je Board ohne Projekt eines anlegen und verknüpfen.
	 *
	 * @param IOutput $output Fortschrittsausgabe.
	 * @param Closure $schemaClosure Liefert den Schema-Wrapper.
	 * @param array<string, mixed> $options Optionen des Migrationslaufs.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_projects') || !$schema->hasTable('pwerk_boards')) {
			return;
		}
		if (!$schema->getTable('pwerk_boards')->hasColumn('project_id')) {
			return;
		}

		$lese = $this->connection->getQueryBuilder();
		$lese->select('*')
			->from('pwerk_boards')
			->where($lese->expr()->isNull('project_id'));
		$ergebnis = $lese->executeQuery();
		$boards = $ergebnis->fetchAll();
		$ergebnis->closeCursor();

		if ($boards === []) {
			return;
		}

		// **Der Backfill läuft atomar.** Insert (Projekt) und Update (Board →
		// Projekt) je Board gehören zusammen; ohne Transaktion ließe ein Abbruch
		// zwischen beiden ein Board ohne `project_id` zurück, und der Neulauf
		// legte ein zweites, verwaistes Projekt an. Bricht hier etwas ab, macht
		// der Rollback alles rückgängig — der nächste Lauf beginnt sauber.
		$this->connection->beginTransaction();
		try {
			foreach ($boards as $board) {
				$projektId = $this->projektAusBoard($board);

				$verknuepfen = $this->connection->getQueryBuilder();
				$verknuepfen->update('pwerk_boards')
					->set('project_id', $verknuepfen->createNamedParameter($projektId, IQueryBuilder::PARAM_INT))
					->where($verknuepfen->expr()->eq('id', $verknuepfen->createNamedParameter((int)$board['id'], IQueryBuilder::PARAM_INT)));
				$verknuepfen->executeStatement();
			}
			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}

		$output->info('pwerk_projects: ' . count($boards) . ' Projekt(e) aus Boards angelegt und verknüpft');
	}

	/**
	 * Ein Projekt aus einer Board-Zeile anlegen und dessen Id zurückgeben.
	 *
	 * @param array<string, mixed> $board Die rohe Board-Zeile.
	 */
	private function projektAusBoard(array $board): int {
		$ein = $this->connection->getQueryBuilder();
		$ein->insert('pwerk_projects')->values([
			'title' => $ein->createNamedParameter((string)$board['title'], IQueryBuilder::PARAM_STR),
			'description' => $ein->createNamedParameter($board['description'], $board['description'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'owner_user_id' => $ein->createNamedParameter((string)$board['owner_user_id'], IQueryBuilder::PARAM_STR),
			'org_internal' => $ein->createNamedParameter($board['org_internal'], $board['org_internal'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'org_external' => $ein->createNamedParameter($board['org_external'], $board['org_external'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'folder_public_id' => $this->intOderNull($ein, $board['folder_public_id']),
			'folder_public_path' => $ein->createNamedParameter($board['folder_public_path'], $board['folder_public_path'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'folder_internal_id' => $this->intOderNull($ein, $board['folder_internal_id']),
			'folder_internal_path' => $ein->createNamedParameter($board['folder_internal_path'], $board['folder_internal_path'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'chat_url' => $ein->createNamedParameter($board['chat_url'], $board['chat_url'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'ticket_counter' => $ein->createNamedParameter((int)$board['ticket_counter'], IQueryBuilder::PARAM_INT),
			'archived' => $ein->createNamedParameter((int)$board['archived'], IQueryBuilder::PARAM_INT),
			'created_at' => $ein->createNamedParameter((string)$board['created_at'], IQueryBuilder::PARAM_STR),
			'updated_at' => $ein->createNamedParameter((string)$board['updated_at'], IQueryBuilder::PARAM_STR),
		]);
		$ein->executeStatement();

		return $ein->getLastInsertId();
	}

	/**
	 * Einen nullable Integer-Wert als Parameter — `null` bleibt `null`.
	 *
	 * @param IQueryBuilder $qb Der Query Builder.
	 * @param mixed $wert Der rohe Spaltenwert.
	 */
	private function intOderNull(IQueryBuilder $qb, mixed $wert): string {
		return $wert === null
			? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
			: $qb->createNamedParameter((int)$wert, IQueryBuilder::PARAM_INT);
	}
}
