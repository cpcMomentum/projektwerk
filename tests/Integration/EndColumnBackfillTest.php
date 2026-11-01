<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Migration\Version000023Date20261031000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;

class EndColumnBackfillTest extends IntegrationTestCase {

	private const VORGABE_DE = ['Eingegangen', 'Bestätigt', 'Eingeplant', 'In Arbeit', 'Erledigt', 'Verworfen'];

	public function testVorgabeBoardBekommtBeideEndspalten(): void {
		$spalten = $this->boardMitSpalten(self::VORGABE_DE);

		$this->backfillAusloesen();

		$this->assertSame([null, null, null, null, 'done', 'discarded'], $this->outcomes($spalten));
	}

	public function testEnglischesBoardBekommtBeideEndspalten(): void {
		$spalten = $this->boardMitSpalten(['Received', 'In progress', 'Done', 'Discarded']);

		$this->backfillAusloesen();

		$this->assertSame([null, null, 'done', 'discarded'], $this->outcomes($spalten));
	}

	public function testBoardMitGesetzterEndspalteBleibtUnberuehrt(): void {
		$spalten = $this->boardMitSpalten(self::VORGABE_DE, [3 => 'done']);

		$this->backfillAusloesen();

		$this->assertSame([null, null, null, 'done', null, null], $this->outcomes($spalten),
			'Eine selbst gesetzte Endspalte ist eine Entscheidung, die der Backfill nicht ergänzt.');
	}

	public function testUmbenannteSpaltenBleibenUnmarkiert(): void {
		$spalten = $this->boardMitSpalten(['Offen', 'Fertig', 'Abgelehnt']);

		$this->backfillAusloesen();

		$this->assertSame([null, null, null], $this->outcomes($spalten));
	}

	public function testDoppelterTitelNurDieErsteVonLinks(): void {
		$spalten = $this->boardMitSpalten(['Erledigt', 'Erledigt', 'Verworfen']);

		$this->backfillAusloesen();

		$this->assertSame(['done', null, 'discarded'], $this->outcomes($spalten));
	}

	public function testBackfillIstIdempotent(): void {
		$spalten = $this->boardMitSpalten(self::VORGABE_DE);

		$this->backfillAusloesen();
		$this->backfillAusloesen();

		$this->assertSame([null, null, null, null, 'done', 'discarded'], $this->outcomes($spalten));
	}

	private function backfillAusloesen(): void {
		$migration = new Version000023Date20261031000000($this->db);
		$migration->postSchemaChange($this->createStub(IOutput::class), $this->schemaVorhanden(), []);
	}

	/**
	 * @param list<string> $titel Spaltentitel von links nach rechts.
	 * @param array<int, string> $vorbelegt Position → bereits gesetztes Ergebnis.
	 * @return list<int> Spalten-Ids in Positionsreihenfolge.
	 */
	private function boardMitSpalten(array $titel, array $vorbelegt = []): array {
		$now = (new \DateTime())->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('pwerk_boards')->values([
			'title' => $qb->createNamedParameter('Endspalten-' . uniqid('', true)),
			'owner_user_id' => $qb->createNamedParameter('lm-anna'),
			'ticket_counter' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		$boardId = $qb->getLastInsertId();

		$ids = [];
		foreach ($titel as $position => $spaltenTitel) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('pwerk_columns')->values([
				'board_id' => $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT),
				'title' => $qb->createNamedParameter($spaltenTitel),
				'position' => $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT),
				'final_outcome' => isset($vorbelegt[$position])
					? $qb->createNamedParameter($vorbelegt[$position])
					: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			]);
			$qb->executeStatement();
			$ids[] = $qb->getLastInsertId();
		}

		return $ids;
	}

	/**
	 * @param list<int> $spaltenIds
	 * @return list<?string>
	 */
	private function outcomes(array $spaltenIds): array {
		$ergebnis = [];
		foreach ($spaltenIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('final_outcome')->from('pwerk_columns')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$wert = $qb->executeQuery()->fetchOne();
			$ergebnis[] = $wert === false ? null : $wert;
		}

		return $ergebnis;
	}

	private function schemaVorhanden(): \Closure {
		$tabelle = $this->createStub(\Doctrine\DBAL\Schema\Table::class);
		$tabelle->method('hasColumn')->willReturn(true);

		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($tabelle);

		return static fn (): ISchemaWrapper => $schema;
	}
}
