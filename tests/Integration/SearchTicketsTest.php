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
use OCA\Projektwerk\Mcp\Tools\SearchTickets;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\TicketService;
use OCP\Server;

class SearchTicketsTest extends IntegrationTestCase {

	private const UID = 'admin';

	private int $boardId;
	private int $otherBoardId;
	private string $marker;

	protected function setUp(): void {
		parent::setUp();

		$this->marker = 'Bürgerbüro' . bin2hex(random_bytes(3));
		$this->boardId = (int)Server::get(BoardService::class)->create(self::UID, 'Suche A')->getId();
		$this->otherBoardId = (int)Server::get(BoardService::class)->create(self::UID, 'Suche B')->getId();
	}

	public function testAHitInTheDescriptionComesWithASnippet(): void {
		$ticket = $this->ticket($this->boardId, 'Schulung planen', 'Termin mit dem ' . $this->marker . ' abstimmen.');

		$rows = $this->search(['query' => mb_strtolower($this->marker)]);

		$this->assertSame([(int)$ticket->getId()], array_column($rows, 'id'));
		$this->assertStringContainsString($this->marker, (string)$rows[0]['snippet']);
		$this->assertSame('Suche A', $rows[0]['boardTitle']);
		$this->assertNotNull($rows[0]['columnTitle']);
		$this->assertSame('open', $rows[0]['status']);
	}

	public function testANumberWithHashFindsTheTicket(): void {
		$ticket = $this->ticket($this->boardId, 'Nummernsuche ' . $this->marker, null);

		$rows = $this->search(['query' => '#' . $ticket->getNumber(), 'board_id' => $this->boardId]);

		$this->assertContains((int)$ticket->getId(), array_column($rows, 'id'));
	}

	public function testTheBoardFilterKeepsOtherBoardsOut(): void {
		$this->ticket($this->boardId, 'A ' . $this->marker, null);
		$this->ticket($this->otherBoardId, 'B ' . $this->marker, null);

		$rows = $this->search(['query' => $this->marker, 'board_id' => $this->otherBoardId]);

		$this->assertSame([$this->otherBoardId], array_values(array_unique(array_column($rows, 'boardId'))));
		$this->assertCount(2, $this->search(['query' => $this->marker]));
	}

	public function testClosedTicketsAreFoundAsDoneUnlessExcluded(): void {
		$ticket = $this->ticket($this->boardId, 'Erledigt ' . $this->marker, null);
		Server::get(TicketService::class)->update($this->viewer($this->boardId), (int)$ticket->getId(), (int)$ticket->getVersion(), ['closed' => true, 'outcome' => 'done']);

		$this->assertSame('done', $this->search(['query' => $this->marker])[0]['status']);
		$this->assertSame([], $this->search(['query' => $this->marker, 'include_closed' => false]));
	}

	public function testATicketClosedBeforeOutcomesExistedCountsAsDone(): void {
		$ticket = $this->ticket($this->boardId, 'Altbestand ' . $this->marker, null);
		$ticket->setClosedAt(new \DateTime('2026-08-20 10:00:00'));
		Server::get(TicketMapper::class)->update($ticket);

		$this->assertSame('done', $this->search(['query' => $this->marker])[0]['status']);
		$this->assertSame([], $this->search(['query' => $this->marker, 'include_closed' => false]));
	}

	public function testAShortDescriptionGivesASnippetWithoutEllipsis(): void {
		$this->ticket($this->boardId, 'Kurz', "Termin  mit\n\n dem " . $this->marker);

		$this->assertSame('Termin mit dem ' . $this->marker, $this->search(['query' => $this->marker])[0]['snippet']);
	}

	public function testAProjectTheCallerIsNotInIsNotFound(): void {
		$result = Server::get(SearchTickets::class)->call(new AuthenticatedCaller(self::UID, 'test'), ['query' => $this->marker, 'project_id' => 999999999]);

		$this->assertTrue($result->isError());
	}

	public function testTheUnifiedSearchAlsoMatchesTheDescription(): void {
		$ticket = $this->ticket($this->boardId, 'Ohne Treffer im Titel', 'Hier steht ' . $this->marker);

		$found = Server::get(TicketMapper::class)->findVisibleMatching(self::UID, $this->marker, 10);

		$this->assertSame([(int)$ticket->getId()], array_map(static fn (Ticket $t): int => (int)$t->getId(), $found));
	}

	public function testPagingHandsOutACursor(): void {
		for ($i = 0; $i < 26; $i++) {
			$this->ticket($this->boardId, 'Viele ' . $i . ' ' . $this->marker, null);
		}

		$first = Server::get(SearchTickets::class)->call(new AuthenticatedCaller(self::UID, 'test'), ['query' => $this->marker])->structured();
		$second = Server::get(SearchTickets::class)->call(new AuthenticatedCaller(self::UID, 'test'), ['query' => $this->marker, 'cursor' => $first['next_cursor']])->structured();

		$this->assertCount(25, $first['tickets']);
		$this->assertCount(1, $second['tickets']);
		$this->assertNull($second['next_cursor']);
	}

	/** @return list<array<string, mixed>> */
	private function search(array $arguments): array {
		$result = Server::get(SearchTickets::class)->call(new AuthenticatedCaller(self::UID, 'test'), $arguments);
		$this->assertFalse($result->isError(), json_encode($result->toArray()));

		return $result->structured()['tickets'];
	}

	private function viewer(int $boardId): ViewerContext {
		return Server::get(BoardAccess::class)->contextFor(self::UID, $boardId);
	}

	private function ticket(int $boardId, string $title, ?string $description): Ticket {
		$viewer = $this->viewer($boardId);
		$column = Server::get(ColumnMapper::class)->findForBoard($viewer)[0];

		return Server::get(TicketService::class)->create($viewer, $title, $description, 'internal', (int)$column->getId());
	}
}
