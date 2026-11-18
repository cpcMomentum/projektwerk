<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;
use OCA\Projektwerk\Service\NotifyPrefService;
use OCP\Server;

/**
 * Projekt-Schalter entstehen nur dort, wo die Person Mitglied ist.
 */
class NotifyPrefScopeTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;
	private NotifyPrefService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
		$this->service = Server::get(NotifyPrefService::class);
	}

	public function testASwitchForTheOwnProjectIsStored(): void {
		$key = NotifyPref::EVENTS[0];

		$this->assertNotNull($this->service->set(LeakMatrixFixture::ANNA, $key, $this->fixture->boardId, false));

		$this->assertSame(
			[$this->fixture->boardId => [$key => false]],
			$this->service->forUser(LeakMatrixFixture::ANNA)['boards'],
		);
	}

	public function testAForeignProjectAndAMissingOneLeaveNoRowAndLookTheSame(): void {
		$key = NotifyPref::EVENTS[0];
		$vorher = count(Server::get(NotifyPrefMapper::class)->findForUser(LeakMatrixFixture::ANNA));

		$fremd = $this->service->set(LeakMatrixFixture::ANNA, $key, $this->fixture->otherBoardId, false);
		$fehlt = $this->service->set(LeakMatrixFixture::ANNA, $key, 2_000_000_000, false);

		$this->assertNull($fremd);
		$this->assertNull($fehlt);
		$this->assertCount($vorher, Server::get(NotifyPrefMapper::class)->findForUser(LeakMatrixFixture::ANNA));
	}

	public function testTheGeneralSwitchNeedsNoProject(): void {
		$this->assertNotNull($this->service->set(LeakMatrixFixture::FREMD, NotifyPref::EVENTS[0], NotifyPrefMapper::GLOBAL_SCOPE, false));
	}
}
