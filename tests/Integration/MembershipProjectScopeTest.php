<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
use OCP\DB\Exception as DbException;
use OCP\Server;

/**
 * **Mitgliedschaft gilt projektweit** (#246 PR 3).
 *
 * Seit PR 2 verbindet {@see \OCA\Projektwerk\Access\TicketScope} über
 * `project_id`; PR 3 zieht die Konsequenz für die Mitgliederdaten:
 *
 * 1. **Eine Mitgliedschaft trägt durch jedes Board des Projekts.** Wer über ein
 *    Board Mitglied ist, erscheint in der Mitgliederliste **jedes** Boards
 *    desselben Projekts — {@see MemberMapper::findForBoard()} filtert über
 *    `project_id`, nicht `board_id`.
 * 2. **Eine Rolle je Projekt, strukturell.** Der eindeutige Index
 *    `(project_id, user_id)` (Migration 16) weist eine zweite Mitgliedschaft
 *    derselben Person unter demselben Projekt ab — auch wenn sie an ein anderes
 *    Board gehängt wird. Damit kann keine per-Board widersprüchliche Rolle
 *    entstehen, die den `internal`-Zweig der Sichtbarkeit auseinanderlaufen
 *    ließe.
 *
 * Bis PR 5 hat jedes Projekt genau ein Board; dieser Test stellt die
 * Zwei-Board-Lage deshalb selbst her (die UI kann sie noch nicht).
 */
final class MembershipProjectScopeTest extends IntegrationTestCase {

	private const ANNA = 'mps-anna';

	private int $projectId;
	private int $boardAId;
	private int $boardBId;

	protected function setUp(): void {
		parent::setUp();

		$now = new \DateTime('2026-09-02 12:00:00');
		$projects = Server::get(ProjectMapper::class);
		$boards = Server::get(BoardMapper::class);
		$members = Server::get(MemberMapper::class);

		$project = new Project();
		$project->setTitle('Mitgliedschaft-Projektweit');
		$project->setOwnerUserId(self::ANNA);
		$project->setArchived(0);
		$project->setOrgInternal('cpcMomentum');
		$project->setOrgExternal('Mueller Elektrotechnik');
		$project->setTicketCounter(0);
		$project->setCreatedAt($now);
		$project->setUpdatedAt($now);
		$this->projectId = (int)$projects->insert($project)->getId();

		$this->boardAId = $this->insertBoard($boards, 'Board A', $now);
		$this->boardBId = $this->insertBoard($boards, 'Board B', $now);

		// Genau eine Mitgliedszeile, an Board A gehängt. Ihr Geltungsbereich ist
		// das Projekt.
		$member = new Member();
		$member->setBoardId($this->boardAId);
		$member->setProjectId($this->projectId);
		$member->setUserId(self::ANNA);
		$member->setRole(ViewerContext::ROLE_INTERNAL);
		$member->setIsManager(1);
		$member->setDisplayName(null);
		$member->setAddedBy(self::ANNA);
		$member->setAddedAt($now);
		$members->insert($member);
	}

	private function insertBoard(BoardMapper $boards, string $title, \DateTime $now): int {
		$board = new Board();
		$board->setTitle($title);
		$board->setProjectId($this->projectId);
		$board->setOwnerUserId(self::ANNA);
		$board->setArchived(0);
		$board->setOrgInternal('cpcMomentum');
		$board->setOrgExternal('Mueller Elektrotechnik');
		$board->setCreatedAt($now);
		$board->setUpdatedAt($now);

		return (int)$boards->insert($board)->getId();
	}

	/**
	 * **Die Mitgliederliste eines jeden Boards zeigt alle Projektmitglieder.**
	 *
	 * Anna ist an Board A gehängt; die Mitgliederliste von Board **B** führt sie
	 * trotzdem — die Zugehörigkeit hängt am Projekt, nicht am Board.
	 */
	public function testMemberListingIsProjectScopedAcrossBoards(): void {
		$members = Server::get(MemberMapper::class);
		$context = Server::get(BoardAccess::class)->contextFor(self::ANNA, $this->boardBId);

		$listed = $members->findForBoard($context);

		$this->assertCount(1, $listed, 'Board B führt genau ein Projektmitglied.');
		$this->assertSame(self::ANNA, (string)$listed[0]->getUserId());
		$this->assertSame($this->projectId, (int)$listed[0]->getProjectId());
	}

	/**
	 * **Zwei Mitgliedschaften derselben Person im selben Projekt sind unmöglich.**
	 *
	 * Der Versuch, Anna ein zweites Mal — an Board B — ins selbe Projekt zu
	 * setzen, verletzt den eindeutigen Index `(project_id, user_id)`.
	 */
	public function testASecondMembershipInTheSameProjectIsRejected(): void {
		$members = Server::get(MemberMapper::class);

		$doppelt = new Member();
		$doppelt->setBoardId($this->boardBId);
		$doppelt->setProjectId($this->projectId);
		$doppelt->setUserId(self::ANNA);
		$doppelt->setRole(ViewerContext::ROLE_EXTERNAL);
		$doppelt->setIsManager(0);
		$doppelt->setDisplayName(null);
		$doppelt->setAddedBy(self::ANNA);
		$doppelt->setAddedAt(new \DateTime('2026-09-02 12:00:00'));

		try {
			$members->insert($doppelt);
			$this->fail('Eine zweite Mitgliedschaft im selben Projekt hätte abgewiesen werden müssen.');
		} catch (DbException $e) {
			$this->assertSame(
				DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION,
				$e->getReason(),
				'Erwartet war eine Verletzung des eindeutigen (project_id, user_id)-Index.',
			);
		}
	}
}
