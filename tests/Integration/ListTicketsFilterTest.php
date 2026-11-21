<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\Tools\ListTickets;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\TicketService;
use OCP\Server;

class ListTicketsFilterTest extends IntegrationTestCase {

	private const UID = 'admin';

	private int $boardA;
	private int $boardB;
	private string $marker;

	protected function setUp(): void {
		parent::setUp();

		$this->marker = 'Filter-' . bin2hex(random_bytes(3));
		$this->boardA = (int)Server::get(BoardService::class)->create(self::UID, 'Projekt A ' . $this->marker)->getId();
		$this->boardB = (int)Server::get(BoardService::class)->create(self::UID, 'Projekt B ' . $this->marker)->getId();
	}

	public function testOverdueAcrossProjectsWithProjectAndBoard(): void {
		$a = $this->ticket($this->boardA, 'A', '2020-01-01');
		$b = $this->ticket($this->boardB, 'B', '2020-01-02');
		$this->ticket($this->boardB, 'nicht fällig', '2999-01-01');

		$rows = $this->mine(['overdue' => true]);

		$this->assertSame([(int)$a->getId(), (int)$b->getId()], $this->ids($rows));
		$this->assertSame('Projekt A ' . $this->marker, $rows[0]['boardTitle']);
		$this->assertNotNull($rows[0]['projectId']);
		$this->assertTrue($rows[0]['overdue']);
	}

	public function testDueBeforeIsInclusive(): void {
		$on = $this->ticket($this->boardA, 'am Tag', '2030-11-27');
		$this->ticket($this->boardA, 'danach', '2030-11-28');
		$this->ticket($this->boardA, 'ohne Frist', null);

		$this->assertSame([(int)$on->getId()], $this->ids($this->mine(['due_before' => '2030-11-27'])));
	}

	public function testResponsibleFilterWithMe(): void {
		$mine = $this->ticket($this->boardA, 'meins', null, self::UID);
		$this->ticket($this->boardA, 'niemandes', null);

		$this->assertSame([(int)$mine->getId()], $this->ids($this->mine(['responsible_user_id' => 'me'])));
		$this->assertSame([(int)$mine->getId()], $this->ids($this->mine(['responsible_user_id' => self::UID])));
	}

	public function testANewCommentCountsAsAChange(): void {
		$quiet = $this->ticket($this->boardA, 'still', null);
		$commented = $this->ticket($this->boardA, 'kommentiert', null);
		foreach ([$quiet, $commented] as $ticket) {
			$ticket->setUpdatedAt(new \DateTime('-3 days'));
			Server::get(TicketMapper::class)->update($ticket);
		}
		Server::get(CommentService::class)->create($this->viewer($this->boardA), (int)$commented->getId(), 'Rückfrage');

		$rows = $this->mine(['changed_since' => (new \DateTime('-1 day'))->format(\DateTime::ATOM)]);

		$this->assertSame([(int)$commented->getId()], $this->ids($rows));
	}

	public function testClosedOverdueTicketsAreHiddenByDefault(): void {
		$ticket = $this->ticket($this->boardA, 'erledigt', '2020-01-01');
		Server::get(TicketService::class)->update($this->viewer($this->boardA), (int)$ticket->getId(), (int)$ticket->getVersion(), ['closed' => true, 'outcome' => 'done']);

		$this->assertSame([], $this->ids($this->mine(['overdue' => true])));
		$this->assertSame([(int)$ticket->getId()], $this->ids($this->mine(['include_closed' => true, 'due_before' => '2020-01-01'])));
	}

	public function testWithABoardOnlyThatBoardAndAnInvalidDateIsRefused(): void {
		$this->ticket($this->boardA, 'A', '2020-01-01');
		$b = $this->ticket($this->boardB, 'B', '2020-01-01');

		$rows = $this->call(['board_id' => $this->boardB, 'overdue' => true])->structured()['tickets'];

		$this->assertSame([(int)$b->getId()], array_column($rows, 'id'));
		$this->assertArrayNotHasKey('boardTitle', $rows[0]);
		$this->assertTrue($this->call(['changed_since' => 'gestern'])->isError());
		$this->assertTrue($this->call(['due_before' => '27.11.2026'])->isError());
	}

	public function testArchivedTicketsAndArchivedProjectsStayOutAcrossBoards(): void {
		$archived = $this->ticket($this->boardA, 'archiviert', '2020-01-01');
		$archived->setArchivedAt(new \DateTime());
		Server::get(TicketMapper::class)->update($archived);
		$kept = $this->ticket($this->boardA, 'bleibt', '2020-01-01');
		$this->ticket($this->boardB, 'altes Projekt', '2020-01-01');
		Server::get(\OCA\Projektwerk\Service\BoardService::class)->setProjectArchived($this->viewer($this->boardB), true);

		$this->assertSame([(int)$kept->getId()], $this->ids($this->mine(['overdue' => true])));
	}

	public function testImpossibleDatesAndFreeTextAreRefused(): void {
		foreach ([['due_before' => '2026-02-31'], ['changed_since' => 'tomorrow'], ['changed_since' => '2026-13-01']] as $arguments) {
			$this->assertTrue($this->call($arguments)->isError(), json_encode($arguments));
		}
		$this->assertFalse($this->call(['changed_since' => '2026-11-20T08:00:00+01:00'])->isError());
	}

	/** @return list<array<string, mixed>> nur die Zeilen dieses Tests */
	private function mine(array $arguments): array {
		$result = $this->call($arguments);
		$this->assertFalse($result->isError(), json_encode($result->toArray()));

		return array_values(array_filter(
			$result->structured()['tickets'],
			fn (array $row): bool => in_array($row['boardId'], [$this->boardA, $this->boardB], true),
		));
	}

	private function call(array $arguments): \OCA\Projektwerk\Mcp\ToolResult {
		return Server::get(ListTickets::class)->call(new AuthenticatedCaller(self::UID, 'test'), $arguments);
	}

	/** @return list<int> */
	private function ids(array $rows): array {
		$ids = array_map('intval', array_column($rows, 'id'));
		sort($ids);

		return $ids;
	}

	private function viewer(int $boardId): ViewerContext {
		return Server::get(BoardAccess::class)->contextFor(self::UID, $boardId);
	}

	private function ticket(int $boardId, string $title, ?string $due, ?string $responsible = null): Ticket {
		$viewer = $this->viewer($boardId);
		$column = Server::get(ColumnMapper::class)->findForBoard($viewer)[0];

		return Server::get(TicketService::class)->create($viewer, $title, null, 'internal', (int)$column->getId(), $responsible, $due);
	}
}
