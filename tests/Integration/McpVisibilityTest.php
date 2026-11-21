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
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpAudit;
use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Mcp\Tools\UpdateTicket;
use OCA\Projektwerk\Service\AttachmentService;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\TicketReadModel;
use OCA\Projektwerk\Service\TicketService;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Server;

class McpVisibilityTest extends IntegrationTestCase {

	private string $intern;
	private string $kunde;
	private int $boardId;
	/** @var list<array{string, string}> */
	private array $audited = [];

	protected function setUp(): void {
		parent::setUp();

		$suffix = bin2hex(random_bytes(4));
		$this->intern = 'pwvis-i-' . $suffix;
		$this->kunde = 'pwvis-k-' . $suffix;
		foreach ([$this->intern, $this->kunde] as $uid) {
			Server::get(IUserManager::class)->createUser($uid, 'Pw-' . bin2hex(random_bytes(8)) . '!aA1');
		}
		$home = Server::get(IRootFolder::class)->getUserFolder($this->intern);
		$home->newFolder('Projekt/90_Austausch');
		$home->newFolder('Projekt/91_Tickets_intern');

		$this->boardId = (int)Server::get(BoardService::class)->create($this->intern, 'Sichtbarkeit')->getId();
		Server::get(BoardService::class)->update($this->viewer($this->intern), [
			'folderPublicPath' => 'Projekt/90_Austausch',
			'folderInternalPath' => 'Projekt/91_Tickets_intern',
		]);
		Server::get(MemberService::class)->add($this->viewer($this->intern), $this->kunde, ViewerContext::ROLE_EXTERNAL);
	}

	protected function tearDown(): void {
		Server::get(IAppConfig::class)->deleteKey('projektwerk', McpConfig::KEY_PUBLIC_WRITE);
		foreach ([$this->intern, $this->kunde] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}

		parent::tearDown();
	}

	public function testWithTheSwitchOffInternalToPublicIsRefused(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(false);
		$ticket = $this->ticket('internal');

		$result = $this->update($this->intern, $ticket, ['visibility' => 'public', 'confirm_public' => true]);

		$this->assertTrue($result->isError());
		$this->assertSame('internal', $this->visibilityOf($ticket));
		$this->assertSame([], $this->audited);
	}

	public function testWithTheSwitchOnInternalToPublicMovesAttachmentsAndIsLogged(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(true);
		$ticket = $this->ticket('internal');
		Server::get(AttachmentService::class)->create($this->viewer($this->intern), (int)$ticket->getId(), 'angebot.txt', 'Inhalt');

		$this->assertTrue($this->update($this->intern, $ticket, ['visibility' => 'public'])->isError(), 'ohne Bestätigung');
		$result = $this->update($this->intern, $ticket, ['visibility' => 'public', 'confirm_public' => true]);

		$this->assertFalse($result->isError(), json_encode($result->toArray()));
		$detail = Server::get(TicketReadModel::class)->show($this->viewer($this->kunde), (int)$ticket->getId());
		$this->assertSame('public', (string)$detail['ticket']->getVisibility(), 'Die Kundenseite sieht den Vorgang.');
		$this->assertSame('public', $detail['attachments'][0]['location']);
		$this->assertStringStartsWith('Projekt/90_Austausch/', (string)$detail['attachments'][0]['filePath']);
		$changes = array_values(array_filter(
			Server::get(TicketReadModel::class)->history($this->viewer($this->intern), (int)$ticket->getId()),
			static fn ($entry): bool => $entry->getAction() === 'visibility',
		));
		$this->assertSame('tool', $changes[0]->getVia());
		$this->assertSame([['internal', 'public']], $this->audited);
	}

	public function testWithTheSwitchOffPublicToInternalIsRefused(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(false);
		$ticket = $this->ticket('public');

		$this->assertTrue($this->update($this->intern, $ticket, ['visibility' => 'internal', 'confirm_public' => true])->isError());
		$this->assertSame('public', $this->visibilityOf($ticket));
	}

	public function testInternalToPrivateNeedsNoSwitch(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(false);
		$ticket = $this->ticket('internal');

		$result = $this->update($this->intern, $ticket, ['visibility' => 'private']);

		$this->assertFalse($result->isError(), json_encode($result->toArray()));
		$this->assertSame('private', $this->visibilityOf($ticket));
		$this->assertSame([], $this->audited);
	}

	public function testVisibilityTogetherWithOtherFieldsIsRefusedAndNothingChanges(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(true);
		$ticket = $this->ticket('internal');

		$result = $this->update($this->intern, $ticket, ['title' => 'Neu', 'visibility' => 'private']);

		$this->assertTrue($result->isError());
		$detail = Server::get(TicketReadModel::class)->show($this->viewer($this->intern), (int)$ticket->getId())['ticket'];
		$this->assertSame('Sichtbarkeit internal', (string)$detail->getTitle());
		$this->assertSame('internal', (string)$detail->getVisibility());
	}

	public function testThePersonWhoMayNotChangeItInTheUiIsRefused(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(true);
		$ticket = $this->ticket('public');

		$result = $this->update($this->kunde, $ticket, ['visibility' => 'private', 'confirm_public' => true]);

		$this->assertTrue($result->isError());
		$this->assertSame('public', $this->visibilityOf($ticket));
	}

	private function update(string $uid, Ticket $ticket, array $arguments): ToolResult {
		$limiter = $this->createStub(McpRateLimiter::class);
		$limiter->method('allowWrite')->willReturn(true);
		$audit = $this->createStub(McpAudit::class);
		$audit->method('recordVisibility')->willReturnCallback(function ($caller, $board, $ticketId, string $from, string $to): void {
			$this->audited[] = [$from, $to];
		});
		$tool = new UpdateTicket(
			Server::get(BoardAccess::class),
			Server::get(\OCA\Projektwerk\Mcp\PublicGuard::class),
			$limiter,
			$audit,
			Server::get(TicketService::class),
			Server::get(McpConfig::class),
		);

		return $tool->call(new AuthenticatedCaller($uid, 'test'), ['board_id' => $this->boardId, 'ticket_id' => (int)$ticket->getId()] + $arguments);
	}

	private function visibilityOf(Ticket $ticket): string {
		return (string)Server::get(TicketReadModel::class)->show($this->viewer($this->intern), (int)$ticket->getId())['ticket']->getVisibility();
	}

	private function viewer(string $uid): ViewerContext {
		return Server::get(BoardAccess::class)->contextFor($uid, $this->boardId);
	}

	private function ticket(string $visibility): Ticket {
		$viewer = $this->viewer($this->intern);
		$column = Server::get(ColumnMapper::class)->findForBoard($viewer)[0];

		return Server::get(TicketService::class)->create($viewer, 'Sichtbarkeit ' . $visibility, null, $visibility, (int)$column->getId());
	}
}
