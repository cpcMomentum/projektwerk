<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Migration\Version000028Date20261109000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;

class ProjectStateRepairTest extends IntegrationTestCase {

	public function testEinBoardGibtNameUndBeschreibungAnsProjekt(): void {
		$projekt = $this->projekt('Alter Name', 'Alte Beschreibung', 0);
		$this->board($projekt, 'Neuer Name', 'Neue Beschreibung', 0);

		$this->reparieren();

		$this->assertSame(['Neuer Name', 'Neue Beschreibung', 0], $this->projektStand($projekt));
	}

	public function testZurueckgeholtesEinzelboardMachtDasProjektSichtbar(): void {
		$projekt = $this->projekt('P', null, 1);
		$this->board($projekt, 'P', null, 0);

		$this->reparieren();

		$this->assertSame(0, $this->projektStand($projekt)[2]);
	}

	public function testArchiviertesEinzelboardWirdZumArchiviertenProjekt(): void {
		$projekt = $this->projekt('P', null, 0);
		$board = $this->board($projekt, 'P', null, 1);

		$this->reparieren();

		$this->assertSame(1, $this->projektStand($projekt)[2]);
		$this->assertSame(0, $this->boardArchiviert($board), 'Sonst bliebe das Projekt nach dem Zurückholen unsichtbar.');
	}

	public function testMehrereBoardsHebenNurDasProjektArchivAuf(): void {
		$projekt = $this->projekt('Projekt', 'Text', 1);
		$a = $this->board($projekt, 'A', null, 1);
		$b = $this->board($projekt, 'B', null, 0);

		$this->reparieren();

		$this->assertSame(['Projekt', 'Text', 0], $this->projektStand($projekt));
		$this->assertSame(1, $this->boardArchiviert($a));
		$this->assertSame(0, $this->boardArchiviert($b));
	}

	private function reparieren(): void {
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		(new Version000028Date20261109000000($this->db))
			->postSchemaChange($this->createStub(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	private function projekt(string $titel, ?string $beschreibung, int $archiviert): int {
		$now = (new \DateTime())->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('pwerk_projects')->values([
			'title' => $qb->createNamedParameter($titel),
			'description' => $qb->createNamedParameter($beschreibung, $beschreibung === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'owner_user_id' => $qb->createNamedParameter('lm-anna'),
			'ticket_counter' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'archived' => $qb->createNamedParameter($archiviert, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	private function board(int $projekt, string $titel, ?string $beschreibung, int $archiviert): int {
		$now = (new \DateTime())->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('pwerk_boards')->values([
			'project_id' => $qb->createNamedParameter($projekt, IQueryBuilder::PARAM_INT),
			'title' => $qb->createNamedParameter($titel),
			'description' => $qb->createNamedParameter($beschreibung, $beschreibung === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
			'owner_user_id' => $qb->createNamedParameter('lm-anna'),
			'ticket_counter' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'archived' => $qb->createNamedParameter($archiviert, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/** @return array{0: string, 1: ?string, 2: int} */
	private function projektStand(int $projekt): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('title', 'description', 'archived')->from('pwerk_projects')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($projekt, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();

		return [(string)$row['title'], $row['description'] === null ? null : (string)$row['description'], (int)$row['archived']];
	}

	private function boardArchiviert(int $board): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('archived')->from('pwerk_boards')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($board, IQueryBuilder::PARAM_INT)));

		return (int)$qb->executeQuery()->fetchOne();
	}
}
