<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Service\MemberService;
use OCP\Server;

/**
 * Die eigene Rolle je Board fürs Gäste-Gate (#234).
 *
 * `board#index` hängt an jedes Projekt die Rolle des Betrachters, damit der
 * Browser einen überall externen Kunden vom Überblick auf sein Board leiten
 * kann. Diese Suite sichert die Datengrundlage dafür: die richtige Rolle je
 * Board, nur die **eigenen** Mitgliedschaften, und eine leere Liste, wo keine
 * ist.
 *
 * Direkt am `MemberMapper` geseedet statt über {@see MemberService::add()}:
 * jener verlangt existierende Konten, hier zählt allein die Zeile in
 * `pwerk_members`. Jeder Fall nutzt eigene Kennungen; die Transaktion der
 * Basisklasse räumt ohnehin ab.
 */
class MemberRolesTest extends IntegrationTestCase {

	private MemberMapper $members;
	private MemberService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->members = Server::get(MemberMapper::class);
		$this->service = Server::get(MemberService::class);
	}

	/**
	 * Eine Mitgliedschaft roh in die Tabelle setzen.
	 *
	 * @param int $boardId Board-Kennung.
	 * @param string $userId Konto.
	 * @param string $role `internal`/`external`.
	 */
	private function seed(int $boardId, string $userId, string $role): void {
		$member = new Member();
		$member->setBoardId($boardId);
		$member->setUserId($userId);
		$member->setRole($role);
		$member->setIsManager(0);
		$member->setDisplayName(null);
		$member->setAddedBy('seed');
		$member->setAddedAt(new \DateTime());
		$this->members->insert($member);
	}

	public function testRoleIsReportedPerBoard(): void {
		$this->seed(90001, 'roles-mixed', 'external');
		$this->seed(90002, 'roles-mixed', 'internal');
		// Fremde Mitgliedschaft am selben Board — darf nicht in der Antwort
		// dieser Person auftauchen.
		$this->seed(90001, 'roles-other', 'internal');

		$roles = $this->service->rolesForUserBoards('roles-mixed');

		$this->assertSame(
			[90001 => 'external', 90002 => 'internal'],
			$roles,
		);
	}

	public function testInternalOnlyMemberSeesInternalEverywhere(): void {
		$this->seed(90003, 'roles-int', 'internal');

		$this->assertSame([90003 => 'internal'], $this->service->rolesForUserBoards('roles-int'));
	}

	public function testAStrangerToEveryBoardGetsAnEmptyList(): void {
		$this->seed(90004, 'roles-someone', 'internal');

		$this->assertSame([], $this->service->rolesForUserBoards('roles-nobody'));
	}
}
