<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Comment;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Mcp\Tools\ListActivity;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\TicketService;
use OCP\IUserManager;
use OCP\Server;

class ListActivityTest extends IntegrationTestCase {

	private string $intern;
	private string $gast;
	private int $boardId;
	private int $projectId;

	protected function setUp(): void {
		parent::setUp();

		$suffix = bin2hex(random_bytes(4));
		$this->intern = 'pwact-i-' . $suffix;
		$this->gast = 'pwact-g-' . $suffix;
		foreach ([$this->intern, $this->gast] as $uid) {
			Server::get(IUserManager::class)->createUser($uid, 'Pw-' . bin2hex(random_bytes(8)) . '!aA1');
		}
		$board = Server::get(BoardService::class)->create($this->intern, 'Aktivität');
		$this->boardId = (int)$board->getId();
		$this->projectId = (int)$board->getProjectId();
		Server::get(MemberService::class)->add($this->viewer($this->intern), $this->gast, ViewerContext::ROLE_EXTERNAL);
	}

	protected function tearDown(): void {
		foreach ([$this->intern, $this->gast] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}

		parent::tearDown();
	}

	public function testCustomerCommentsSinceYesterday(): void {
		$a = $this->ticket('public', 'A');
		$b = $this->ticket('public', 'B');
		$this->comment($this->gast, $a, 'Frage zu A', '-20 hours');
		$this->comment($this->gast, $b, 'Frage zu B', '-10 hours');
		$this->comment($this->intern, $a, 'Antwort', '-1 hour');
		$this->comment($this->gast, $a, 'alt', '-3 days');

		$entries = $this->entries($this->gast, ['since' => (new \DateTime('-1 day'))->format(\DateTime::ATOM), 'kind' => 'comments', 'side' => 'external']);

		$this->assertSame(['Frage zu B', 'Frage zu A'], array_column($entries, 'text'));
		$this->assertSame([(int)$b->getId(), (int)$a->getId()], array_map(static fn (array $e): int => $e['ticket']['id'], $entries));
		$this->assertSame('external', $entries[0]['actor']['side']);
	}

	public function testAColumnMoveShowsWithPersonAndTime(): void {
		$ticket = $this->ticket('internal', 'verschieben');
		$columns = Server::get(ColumnMapper::class)->findForBoard($this->viewer($this->intern));
		Server::get(TicketService::class)->move($this->viewer($this->intern), (int)$ticket->getId(), (int)$ticket->getVersion(), (int)$columns[1]->getId(), null, null);

		$entries = $this->entries($this->intern, ['since' => (new \DateTime('today'))->format('Y-m-d'), 'project_id' => $this->projectId, 'kind' => 'changes']);

		$moves = array_values(array_filter($entries, static fn (array $e): bool => $e['action'] === 'moved'));
		$this->assertCount(1, $moves);
		$this->assertSame($this->intern, $moves[0]['actor']['userId']);
		$this->assertSame('internal', $moves[0]['actor']['side']);
		$this->assertNotNull($moves[0]['at']);
	}

	public function testACommentByMailCarriesItsOrigin(): void {
		$ticket = $this->ticket('public', 'per Mail');
		Server::get(CommentService::class)->create($this->viewer($this->gast)->viaMail(), (int)$ticket->getId(), 'Antwort per Mail');

		$entries = $this->entries($this->intern, ['since' => '2000-01-01', 'kind' => 'comments']);

		$this->assertSame('mail', $entries[0]['via']);
	}

	public function testTheCustomerSeesNoActivityOnInternalTickets(): void {
		$internal = $this->ticket('internal', 'intern');
		$this->comment($this->intern, $internal, 'nur für uns', '-1 hour');

		$entries = $this->entries($this->gast, ['since' => '2000-01-01']);

		$this->assertNotContains((int)$internal->getId(), array_map(static fn (array $e): int => $e['ticket']['id'], $entries));
	}

	public function testWithoutSinceComesAnUnderstandableError(): void {
		$result = $this->call($this->intern, []);

		$this->assertTrue($result->isError());
		$this->assertStringContainsString('since is required', json_encode($result->toArray()));
	}

	/** @return list<array<string, mixed>> */
	private function entries(string $uid, array $arguments): array {
		$result = $this->call($uid, $arguments);
		$this->assertFalse($result->isError(), json_encode($result->toArray()));

		return array_values(array_filter($result->structured()['entries'], fn (array $e): bool => $e['ticket']['boardId'] === $this->boardId));
	}

	private function call(string $uid, array $arguments): ToolResult {
		return Server::get(ListActivity::class)->call(new AuthenticatedCaller($uid, 'test'), $arguments);
	}

	private function viewer(string $uid): ViewerContext {
		return Server::get(BoardAccess::class)->contextFor($uid, $this->boardId);
	}

	private function ticket(string $visibility, string $title): Ticket {
		$viewer = $this->viewer($this->intern);
		$column = Server::get(ColumnMapper::class)->findForBoard($viewer)[0];

		return Server::get(TicketService::class)->create($viewer, $title, null, $visibility, (int)$column->getId());
	}

	private function comment(string $uid, Ticket $ticket, string $body, string $when): void {
		$comment = Server::get(CommentService::class)->create($this->viewer($uid), (int)$ticket->getId(), $body);
		$comment->setCreatedAt(new \DateTime($when));
		Server::get(CommentMapper::class)->update($comment);
	}
}
