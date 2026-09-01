<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Migration\Version000013Date20260901020000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;

/**
 * Der Backfill aus #246 PR 0: je Board genau ein Projekt, Felder kopiert.
 *
 * Die Migration selbst läuft im Test-Setup auf der leeren Tabelle (nichts zu
 * tun). Hier wird ein Board **direkt** eingefügt und der Backfill danach noch
 * einmal ausgelöst — so prüft der Test, dass die richtigen Felder kopiert und
 * die Verknüpfung gesetzt wird, statt sich auf den leeren Erstlauf zu verlassen.
 */
class ProjectBackfillTest extends IntegrationTestCase {

	public function testBackfillLegtProjektAnUndVerknuepft(): void {
		$boardId = $this->boardEinfuegen('Backfill-Board', 'lm-anna', 'cpcMomentum', 'Kunde GmbH', 7);

		$this->backfillAusloesen();

		$board = $this->zeile('pwerk_boards', $boardId);
		$this->assertNotNull($board['project_id'], 'Das Board muss nach dem Backfill ein Projekt tragen.');

		$projekt = $this->zeile('pwerk_projects', (int)$board['project_id']);
		$this->assertSame('Backfill-Board', $projekt['title']);
		$this->assertSame('lm-anna', $projekt['owner_user_id']);
		$this->assertSame('cpcMomentum', $projekt['org_internal']);
		$this->assertSame('Kunde GmbH', $projekt['org_external']);
		$this->assertSame(7, (int)$projekt['ticket_counter'], 'Der Zähler wird mitkopiert (Nummernraum).');
	}

	public function testBackfillIstIdempotent(): void {
		$boardId = $this->boardEinfuegen('Einmal-Board', 'lm-anna', 'cpcMomentum', 'Kunde GmbH', 0);

		$this->backfillAusloesen();
		$this->backfillAusloesen();

		$this->assertSame(
			1,
			$this->anzahlProjekteFuerBoard($boardId),
			'Ein zweiter Lauf darf kein zweites Projekt anlegen.',
		);
	}

	private function backfillAusloesen(): void {
		$migration = new Version000013Date20260901020000($this->db);
		$migration->postSchemaChange($this->output(), $this->schemaVorhanden(), []);
	}

	/**
	 * Ein Board mit den Pflichtfeldern anlegen, `project_id` bleibt null.
	 */
	private function boardEinfuegen(string $titel, string $owner, string $orgIntern, string $orgExtern, int $counter): int {
		$jetzt = (new \DateTime())->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('pwerk_boards')->values([
			'title' => $qb->createNamedParameter($titel),
			'owner_user_id' => $qb->createNamedParameter($owner),
			'org_internal' => $qb->createNamedParameter($orgIntern),
			'org_external' => $qb->createNamedParameter($orgExtern),
			'ticket_counter' => $qb->createNamedParameter($counter, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($jetzt),
			'updated_at' => $qb->createNamedParameter($jetzt),
		]);
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/**
	 * @param string $tabelle Tabellenname.
	 * @param int $id Primärschlüssel.
	 * @return array<string, mixed>
	 */
	private function zeile(string $tabelle, int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($tabelle)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$zeile = $qb->executeQuery()->fetch();

		return $zeile === false ? [] : $zeile;
	}

	/**
	 * @param int $boardId Kennung des Boards.
	 */
	private function anzahlProjekteFuerBoard(int $boardId): int {
		$board = $this->zeile('pwerk_boards', $boardId);
		if ($board['project_id'] === null) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('pwerk_projects')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$board['project_id'], IQueryBuilder::PARAM_INT)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	private function output(): IOutput {
		return $this->createMock(IOutput::class);
	}

	/**
	 * Ein Schema-Closure, dessen Prüfungen (Tabellen/Spalte vorhanden) alle
	 * zutreffen — die echten Tabellen existieren nach dem Setup ohnehin.
	 */
	private function schemaVorhanden(): \Closure {
		$tabelle = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
		$tabelle->method('hasColumn')->willReturn(true);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($tabelle);

		return static fn (): ISchemaWrapper => $schema;
	}
}
