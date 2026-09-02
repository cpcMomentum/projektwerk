<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\DB\Exception as DbException;
use OCP\Server;

/**
 * **Vorgangsnummern laufen projektweit** (#246 PR 4a).
 *
 * Vor #246 zählte jedes Board für sich. Seit die Nummer am Projekt hängt
 * ({@see ProjectMapper::claimTicketNumber()}), teilen sich alle Boards eines
 * Projekts **eine** lückenlose Reihe, und der eindeutige Index
 * `(project_id, number)` (Migration 17) macht daraus eine Datenbank-Garantie:
 * kein zweiter Vorgang desselben Projekts mit derselben Nummer — und damit kein
 * doppelter Dateiname und kein doppelter Direktlink.
 *
 * Bis PR 5 hat jedes Projekt genau ein Board; dieser Test stellt die
 * Zwei-Board-Lage selbst her, weil sie genau die ist, für die die
 * projektweite Nummer gemacht ist.
 */
final class TicketNumberingProjectTest extends IntegrationTestCase {

	private const ANNA = 'tnp-anna';

	private int $projectId;
	private int $boardAId;
	private int $boardBId;
	private int $columnAId;
	private int $columnBId;

	protected function setUp(): void {
		parent::setUp();

		$now = new \DateTime('2026-09-02 12:00:00');
		$projects = Server::get(ProjectMapper::class);
		$boards = Server::get(BoardMapper::class);
		$members = Server::get(MemberMapper::class);
		$columns = Server::get(ColumnMapper::class);

		$project = new Project();
		$project->setTitle('Nummern-Projektweit');
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

		$this->columnAId = $this->insertColumn($columns, $this->boardAId, $now);
		$this->columnBId = $this->insertColumn($columns, $this->boardBId, $now);
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

	private function insertColumn(ColumnMapper $columns, int $boardId, \DateTime $now): int {
		$column = new Column();
		$column->setBoardId($boardId);
		$column->setTitle('Offen');
		$column->setPosition(0);
		$column->setColor('#0082c9');

		return (int)$columns->insert($column)->getId();
	}

	/**
	 * **Die Reihe läuft über beide Boards hinweg fortlaufend.**
	 *
	 * Ein Zug auf Board A, einer auf Board B, wieder Board A: 1, 2, 3 — die
	 * Nummer kommt aus dem gemeinsamen Projekt, nicht aus dem jeweiligen Board.
	 */
	public function testNumbersRunProjectWideAcrossBoards(): void {
		$access = Server::get(BoardAccess::class);
		$projects = Server::get(ProjectMapper::class);

		$ctxA = $access->contextFor(self::ANNA, $this->boardAId);
		$ctxB = $access->contextFor(self::ANNA, $this->boardBId);

		$this->assertSame(1, $projects->claimTicketNumber($ctxA), 'Erster Vorgang, Board A.');
		$this->assertSame(2, $projects->claimTicketNumber($ctxB), 'Zweiter Vorgang, Board B — dieselbe Reihe.');
		$this->assertSame(3, $projects->claimTicketNumber($ctxA), 'Dritter Vorgang, wieder Board A.');
	}

	/**
	 * **Zwei Vorgänge desselben Projekts mit derselben Nummer sind unmöglich** —
	 * auch über die Boardgrenze hinweg.
	 *
	 * Board A und Board B gehören zu einem Projekt; ein Vorgang mit Nummer 5 auf
	 * jedem von ihnen verletzt den eindeutigen Index `(project_id, number)`.
	 */
	public function testTheSameNumberTwiceInAProjectIsRejected(): void {
		$tickets = Server::get(TicketMapper::class);

		$tickets->insert($this->ticket($this->boardAId, $this->columnAId, 5));

		try {
			$tickets->insert($this->ticket($this->boardBId, $this->columnBId, 5));
			$this->fail('Dieselbe Nummer im selben Projekt hätte abgewiesen werden müssen.');
		} catch (DbException $e) {
			$this->assertSame(
				DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION,
				$e->getReason(),
				'Erwartet war eine Verletzung des eindeutigen (project_id, number)-Index.',
			);
		}
	}

	private function ticket(int $boardId, int $columnId, int $number): Ticket {
		$now = new \DateTime('2026-09-02 12:00:00');
		$ticket = new Ticket();
		$ticket->setBoardId($boardId);
		$ticket->setProjectId($this->projectId);
		$ticket->setColumnId($columnId);
		$ticket->setNumber($number);
		$ticket->setTitle('Vorgang ' . $number);
		$ticket->setVisibility(TicketScope::VISIBILITY_PUBLIC);
		$ticket->setCreatorUserId(self::ANNA);
		$ticket->setCreatorRole(ViewerContext::ROLE_INTERNAL);
		$ticket->setResponsibleUserId(self::ANNA);
		$ticket->setPosition($number * 65536);
		$ticket->setVersion(1);
		$ticket->setCreatedAt($now);
		$ticket->setUpdatedAt($now);

		return $ticket;
	}
}
