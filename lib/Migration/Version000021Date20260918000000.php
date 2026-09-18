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
 * #309 Phase 1 — Firmenmodell, additiver Teil.
 *
 * Legt drei nullable Anzeige-Spalten an und füllt sie so, dass die Oberfläche
 * danach **exakt** wie vorher aussieht (Parität). Die Firma einer Person kam
 * bisher aus ihrer Rolle (`internal → org_internal`, `external → org_external`)
 * mit den Werten am Projekt; künftig steht sie je Person in `members.company`,
 * der Kunde je Projekt in `projects.customer` (Anzeige-Kopie am Board wie beim
 * bestehenden `org`-Paar).
 *
 * **Autorität ist `pwerk_projects`** — seit #246 trägt das Projekt die
 * maßgeblichen `org_internal`/`org_external`, das Board nur eine Anzeige-Kopie.
 * Der Backfill speist sich deshalb ausschließlich aus dem Projekt; die Frage
 * „welches `org_external` gewinnt bei mehreren Boards" stellt sich nicht.
 *
 * **Rein additiv, kein Drop.** Die alten `org`-Spalten bleiben; sie werden erst
 * in Phase 5 (#309) entfernt, nachdem die Anzeige-Parität in Produktion belegt
 * ist — ein `dropColumn` baut auf SQLite die von der Leak-Matrix überwachte
 * `pwerk_boards` neu und darf nie im selben Lauf wie der Backfill stehen.
 *
 * **Kein neuer Lesepfad, keine Serialisierung** — das ist Phase 2. Die Spalten
 * sind hier nur befüllt, nicht ausgeliefert; QBMapper ignoriert die noch nicht
 * in den Entities deklarierten Spalten.
 *
 * Backfill je Zeile in PHP (kein `set(col, <subquery>)` — `set()` quotiert sein
 * zweites Argument als Spaltennamen, und `createFunction()` bricht auf
 * PostgreSQL bei Schlüsselwörtern, §Datenbank/#60). Transaktional und
 * idempotent (nur wo die Zielspalte noch NULL ist).
 */
class Version000021Date20260918000000 extends SimpleMigrationStep {

	private const LEN = 128;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'Firmenmodell: company/customer (#309 Phase 1)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_members.company, pwerk_projects.customer, pwerk_boards.customer (nullable) and backfill from pwerk_projects for display parity; no drop, no serialization (#309).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$added = false;

		// Literale Tabellen-/Spaltennamen (kein Schleifen-Variablenzugriff):
		// der Entity↔Migration-Wächter (EntitySchemaTest) parst genau
		// `getTable('…')` + `addColumn('…', Types::…)` und übersähe dynamische
		// Namen — dann meldete er das Entity als „zu breit".
		if ($schema->hasTable('pwerk_members')) {
			$members = $schema->getTable('pwerk_members');
			if (!$members->hasColumn('company')) {
				$members->addColumn('company', Types::STRING, ['notnull' => false, 'length' => self::LEN]);
				$added = true;
			}
		}
		if ($schema->hasTable('pwerk_projects')) {
			$projects = $schema->getTable('pwerk_projects');
			if (!$projects->hasColumn('customer')) {
				$projects->addColumn('customer', Types::STRING, ['notnull' => false, 'length' => self::LEN]);
				$added = true;
			}
		}
		if ($schema->hasTable('pwerk_boards')) {
			$boards = $schema->getTable('pwerk_boards');
			if (!$boards->hasColumn('customer')) {
				$boards->addColumn('customer', Types::STRING, ['notnull' => false, 'length' => self::LEN]);
				$added = true;
			}
		}

		return $added ? $schema : null;
	}

	/**
	 * Backfill für die Anzeige-Parität — alle Werte aus dem Projekt (Autorität).
	 *
	 * @param IOutput $output Fortschrittsausgabe.
	 * @param Closure $schemaClosure Liefert den Schema-Wrapper.
	 * @param array<string, mixed> $options Optionen des Migrationslaufs.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (['pwerk_projects', 'pwerk_boards', 'pwerk_members'] as $needed) {
			if (!$schema->hasTable($needed)) {
				return;
			}
		}
		if (!$schema->getTable('pwerk_members')->hasColumn('company')
			|| !$schema->getTable('pwerk_projects')->hasColumn('customer')
			|| !$schema->getTable('pwerk_boards')->hasColumn('customer')) {
			return;
		}

		// Autoritative Firmen je Projekt einlesen.
		$lese = $this->connection->getQueryBuilder();
		$lese->select('id', 'org_internal', 'org_external')->from('pwerk_projects');
		$ergebnis = $lese->executeQuery();
		/** @var array<int, array{internal: ?string, external: ?string}> $projectOrg */
		$projectOrg = [];
		foreach ($ergebnis->fetchAll() as $row) {
			$projectOrg[(int)$row['id']] = [
				'internal' => $row['org_internal'],
				'external' => $row['org_external'],
			];
		}
		$ergebnis->closeCursor();

		if ($projectOrg === []) {
			return;
		}

		// Board → Projekt (für Mitglieder ohne gesetzte project_id die zweite Quelle).
		$lese = $this->connection->getQueryBuilder();
		$lese->select('id', 'project_id')->from('pwerk_boards')
			->where($lese->expr()->isNotNull('project_id'));
		$ergebnis = $lese->executeQuery();
		/** @var array<int, int> $boardProject */
		$boardProject = [];
		foreach ($ergebnis->fetchAll() as $row) {
			$boardProject[(int)$row['id']] = (int)$row['project_id'];
		}
		$ergebnis->closeCursor();

		$this->connection->beginTransaction();
		try {
			$projectsSet = 0;
			$boardsSet = 0;
			$membersSet = 0;

			// projects.customer = org_external
			foreach ($projectOrg as $projectId => $org) {
				if ($org['external'] === null || $org['external'] === '') {
					continue;
				}
				$projectsSet += $this->setValue('pwerk_projects', 'customer', $org['external'],
					['id' => $projectId]);
			}

			// boards.customer = org_external des Projekts
			foreach ($boardProject as $boardId => $projectId) {
				$external = $projectOrg[$projectId]['external'] ?? null;
				if ($external === null || $external === '') {
					continue;
				}
				$boardsSet += $this->setValue('pwerk_boards', 'customer', $external,
					['id' => $boardId]);
			}

			// members.company = role==='external' ? org_external : org_internal
			$lese = $this->connection->getQueryBuilder();
			$lese->select('id', 'board_id', 'project_id', 'role')->from('pwerk_members');
			$ergebnis = $lese->executeQuery();
			foreach ($ergebnis->fetchAll() as $row) {
				$projectId = $row['project_id'] !== null
					? (int)$row['project_id']
					: ($boardProject[(int)$row['board_id']] ?? null);
				if ($projectId === null || !isset($projectOrg[$projectId])) {
					continue;
				}
				$firma = $row['role'] === 'external'
					? $projectOrg[$projectId]['external']
					: $projectOrg[$projectId]['internal'];
				if ($firma === null || $firma === '') {
					continue;
				}
				$membersSet += $this->setValue('pwerk_members', 'company', $firma,
					['id' => (int)$row['id']]);
			}
			$ergebnis->closeCursor();

			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}

		$output->info('Firmenmodell-Backfill: projects.customer=' . $projectsSet
			. ', boards.customer=' . $boardsSet . ', members.company=' . $membersSet);
	}

	/**
	 * Setzt einen String-Wert in genau der Zeile mit `id`, aber nur solange die
	 * Zielspalte noch NULL ist (idempotenter Wiederholungslauf).
	 *
	 * @param string $table Tabellenname.
	 * @param string $column Zielspalte.
	 * @param string $value Neuer Wert.
	 * @param array{id: int} $where Zeilenauswahl.
	 * @return int Zahl der geänderten Zeilen.
	 */
	private function setValue(string $table, string $column, string $value, array $where): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter($value))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($where['id'], IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull($column));

		return $qb->executeStatement();
	}
}
