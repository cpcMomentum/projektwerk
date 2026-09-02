<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * #246 PR 5 — Ordner und Chat aufs Projekt nachziehen.
 *
 * Ab jetzt sind die beiden Projektordner und der Chat-Link **Sache des
 * Projekts**: Alle Boards eines Projekts legen in denselben Austausch- und
 * Intern-Ordner ab ({@see \OCA\Projektwerk\Service\ProjectFolderService::folderIdFor()}),
 * und die Einstellungen schreiben sie am Projekt.
 *
 * Migration 13 hatte diese Felder beim Anlegen des Projekt-Dachs schon aus dem
 * Board gespiegelt; seither zählte aber weiter das **Board**, und ein Board, das
 * seine Ordner erst nach Migration 13 gesetzt hat, hätte am Projekt noch die
 * alten (oder leeren) Werte. Dieser Abgleich schließt die Lücke: Er kopiert
 * Ordner-IDs, Ordner-Pfade und Chat-Link jedes Boards auf sein Projekt.
 *
 * **Geschwister-Boards ohne eigene Ordner/Chat bleiben außen vor.** Seit PR 5a
 * kann ein Projekt mehrere Boards haben ({@see \OCA\Projektwerk\Service\BoardService::createInProject()}),
 * und ein neu angelegtes Geschwister-Board startet mit lauter `NULL`-Werten in
 * diesen fünf Spalten. Ein blindes „letztes Board gewinnt" würde auf einem
 * Projekt mit Geschwister-Boards die schon gepflegten Werte mit `NULL`
 * überschreiben, sobald das Geschwister-Board zufällig zuletzt verarbeitet
 * wird. Deshalb: Boards ganz ohne eigene Werte werden übersprungen, und unter
 * mehreren Boards mit Werten gewinnt das zuletzt geänderte (`updated_at`).
 * Idempotent (setzt absolute Werte), transaktional.
 */
class Version000018Date20260902060000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'folders and chat to project (#246 PR 5)';
	}

	#[\Override]
	public function description(): string {
		return 'Realign pwerk_projects folder ids/paths and chat_url from the board; folders and chat are project-level now (#246).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// Keine Schema-Änderung: Die Spalten stehen seit Migration 13 am Projekt.
		return null;
	}

	/**
	 * Bestand: Ordner und Chat jedes Boards auf sein Projekt kopieren.
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

		$lese = $this->connection->getQueryBuilder();
		$lese->select(
			'project_id',
			'folder_public_id',
			'folder_public_path',
			'folder_internal_id',
			'folder_internal_path',
			'chat_url',
			'updated_at',
		)
			->from('pwerk_boards')
			->where($lese->expr()->isNotNull('project_id'))
			// Zuletzt geändert zuletzt verarbeitet, damit unter mehreren Boards
			// desselben Projekts das mit dem neuesten Stand gewinnt.
			->orderBy('updated_at', 'ASC');
		$ergebnis = $lese->executeQuery();
		$boards = $ergebnis->fetchAll();
		$ergebnis->closeCursor();

		// Geschwister-Boards, die nie eigene Ordner/Chat bekommen haben (leer seit
		// ihrer Anlage), dürfen die schon gepflegten Projekt-Werte nicht mit
		// `NULL` überschreiben.
		$boards = array_filter(
			$boards,
			static fn (array $board): bool => $board['folder_public_id'] !== null
				|| $board['folder_public_path'] !== null
				|| $board['folder_internal_id'] !== null
				|| $board['folder_internal_path'] !== null
				|| $board['chat_url'] !== null,
		);

		if ($boards === []) {
			return;
		}

		$this->connection->beginTransaction();
		try {
			$gesetzt = 0;
			foreach ($boards as $board) {
				$qb = $this->connection->getQueryBuilder();
				$qb->update('pwerk_projects')
					->set('folder_public_id', $this->intOderNull($qb, $board['folder_public_id']))
					->set('folder_public_path', $this->stringOderNull($qb, $board['folder_public_path']))
					->set('folder_internal_id', $this->intOderNull($qb, $board['folder_internal_id']))
					->set('folder_internal_path', $this->stringOderNull($qb, $board['folder_internal_path']))
					->set('chat_url', $this->stringOderNull($qb, $board['chat_url']))
					->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$board['project_id'], IQueryBuilder::PARAM_INT)));
				$gesetzt += $qb->executeStatement();
			}
			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}

		$output->info('pwerk_projects: Ordner und Chat auf ' . $gesetzt . ' Projekt(en) aus dem Board nachgezogen');
	}

	/**
	 * `NULL` bleibt `NULL`, sonst ein gebundener Integer.
	 */
	private function intOderNull(IQueryBuilder $qb, mixed $value): IParameter|string {
		return $value === null
			? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
			: $qb->createNamedParameter((int)$value, IQueryBuilder::PARAM_INT);
	}

	/**
	 * `NULL` bleibt `NULL`, sonst ein gebundener String.
	 */
	private function stringOderNull(IQueryBuilder $qb, mixed $value): IParameter|string {
		return $value === null
			? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
			: $qb->createNamedParameter((string)$value, IQueryBuilder::PARAM_STR);
	}
}
