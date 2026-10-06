<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
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
	 * Ein Projekt mit so vielen Boards wie angegeben; liefert [Projekt-ID, Board-IDs].
	 *
	 * @return array{0: int, 1: list<int>}
	 */
	private function project(int $boardCount = 1): array {
		$now = new \DateTime();
		$project = new Project();
		$project->setTitle('Rollen');
		$project->setOwnerUserId('seed');
		$project->setArchived(0);
		$project->setTicketCounter(0);
		$project->setCreatedAt($now);
		$project->setUpdatedAt($now);
		$projectId = (int)Server::get(ProjectMapper::class)->insert($project)->getId();

		$boardIds = [];
		for ($i = 0; $i < $boardCount; $i++) {
			$board = new Board();
			$board->setTitle('Rollen ' . $i);
			$board->setProjectId($projectId);
			$board->setOwnerUserId('seed');
			$board->setArchived(0);
			$board->setCreatedAt($now);
			$board->setUpdatedAt($now);
			$boardIds[] = (int)Server::get(BoardMapper::class)->insert($board)->getId();
		}

		return [$projectId, $boardIds];
	}

	/**
	 * Eine Mitgliedschaft roh in die Tabelle setzen — wie im Betrieb an das erste
	 * Board gebunden, gültig für das ganze Projekt.
	 *
	 * @param array{0: int, 1: list<int>} $project
	 */
	private function seed(array $project, string $userId, string $role): void {
		$member = new Member();
		$member->setBoardId($project[1][0]);
		$member->setProjectId($project[0]);
		$member->setUserId($userId);
		$member->setRole($role);
		$member->setIsManager(0);
		$member->setDisplayName(null);
		$member->setAddedBy('seed');
		$member->setAddedAt(new \DateTime());
		$this->members->insert($member);
	}

	public function testRoleIsReportedPerBoard(): void {
		$first = $this->project();
		$second = $this->project();
		$this->seed($first, 'roles-mixed', 'external');
		$this->seed($second, 'roles-mixed', 'internal');
		// Fremde Mitgliedschaft im selben Projekt — darf nicht in der Antwort
		// dieser Person auftauchen.
		$this->seed($first, 'roles-other', 'internal');

		$this->assertSame(
			[$first[1][0] => 'external', $second[1][0] => 'internal'],
			$this->service->rolesForUserBoards('roles-mixed'),
		);
	}

	/**
	 * Die Mitgliedschaft hängt an einem Board, gilt aber für jedes Board des Projekts (#357).
	 */
	public function testEveryBoardOfTheProjectCarriesTheRole(): void {
		$project = $this->project(3);
		$this->seed($project, 'roles-siblings', 'external');

		$this->assertSame(
			array_fill_keys($project[1], 'external'),
			$this->service->rolesForUserBoards('roles-siblings'),
		);
	}

	public function testInternalOnlyMemberSeesInternalEverywhere(): void {
		$project = $this->project();
		$this->seed($project, 'roles-int', 'internal');

		$this->assertSame([$project[1][0] => 'internal'], $this->service->rolesForUserBoards('roles-int'));
	}

	public function testAStrangerToEveryBoardGetsAnEmptyList(): void {
		$this->seed($this->project(), 'roles-someone', 'internal');

		$this->assertSame([], $this->service->rolesForUserBoards('roles-nobody'));
	}
}
