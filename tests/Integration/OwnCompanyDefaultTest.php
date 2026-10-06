<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\OwnCompanySettings;
use OCP\Server;

/**
 * Ein neues Projekt ohne eigene Firma übernimmt die der Instanz (#352).
 */
class OwnCompanyDefaultTest extends IntegrationTestCase {

	private OwnCompanySettings $settings;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = Server::get(OwnCompanySettings::class);
	}

	protected function tearDown(): void {
		// Der App-Config-Cache überlebt das Zurückrollen; ausdrücklich entfernen.
		$this->settings->set(null);

		parent::tearDown();
	}

	public function testANewProjectWithoutCompanyTakesTheInstanceCompany(): void {
		$this->settings->set('cpcMomentum GmbH');

		$board = Server::get(BoardService::class)->create('oc-anna', 'Ohne Firma', null, '  ', 'Kunde AG');

		$this->assertSame('cpcMomentum GmbH', $board->getOrgInternal());
		$this->assertSame('cpcMomentum GmbH', $this->column('pwerk_projects', 'org_internal', 'id', (int)$board->getProjectId()));
		$this->assertSame(
			'cpcMomentum GmbH',
			$this->column('pwerk_members', 'company', 'board_id', (int)$board->getId()),
			'Die Firma des Erstellers folgt der eigenen Firma (#309).',
		);
	}

	public function testAnExplicitCompanyWins(): void {
		$this->settings->set('cpcMomentum GmbH');

		$board = Server::get(BoardService::class)->create('oc-anna', 'Mit Firma', null, 'Partner GmbH');

		$this->assertSame('Partner GmbH', $board->getOrgInternal());
	}

	private function column(string $table, string $column, string $key, int $id): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)->from($table)->where($qb->expr()->eq($key, $qb->createNamedParameter($id)));
		$value = $qb->executeQuery()->fetchOne();

		return $value === false ? null : $value;
	}

	public function testWithoutInstanceCompanyNothingIsInvented(): void {
		$board = Server::get(BoardService::class)->create('oc-anna', 'Ganz ohne', null, null);

		$this->assertNull($board->getOrgInternal());
	}
}
