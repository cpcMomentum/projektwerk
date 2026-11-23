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
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\Tools\GetTicket;
use OCA\Projektwerk\Mcp\Tools\LinkFile;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\FileLinkService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\TicketReadModel;
use OCA\Projektwerk\Service\TicketService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Server;

class FileLinkTest extends IntegrationTestCase {

	private string $intern;
	private string $kunde;
	private string $kollegin;

	private Folder $home;
	private FileLinkService $links;
	private TicketReadModel $read;
	private int $boardId;

	protected function setUp(): void {
		parent::setUp();

		// Je Test frische Kennungen: Nextcloud hält Speicher-Mounts im Prozess, auch über gelöschte Konten hinweg.
		$suffix = bin2hex(random_bytes(4));
		$this->intern = 'pwlink-i-' . $suffix;
		$this->kunde = 'pwlink-k-' . $suffix;
		$this->kollegin = 'pwlink-c-' . $suffix;
		$users = Server::get(IUserManager::class);
		foreach ([$this->intern, $this->kunde, $this->kollegin] as $uid) {
			$users->createUser($uid, 'Pw-' . bin2hex(random_bytes(8)) . '!aA1');
		}
		$this->home = Server::get(IRootFolder::class)->getUserFolder($this->intern);
		$this->home->newFolder('Inhalte/Schulungen');
		$this->home->newFile('Inhalte/Konzept.md', 'v1');

		$board = Server::get(BoardService::class)->create($this->intern, 'Verknüpfungen');
		$this->boardId = (int)$board->getId();
		Server::get(MemberService::class)->add($this->viewer($this->intern), $this->kunde, ViewerContext::ROLE_EXTERNAL);
		Server::get(MemberService::class)->add($this->viewer($this->intern), $this->kollegin, ViewerContext::ROLE_INTERNAL);

		$this->links = Server::get(FileLinkService::class);
		$this->read = Server::get(TicketReadModel::class);
	}

	protected function tearDown(): void {
		$users = Server::get(IUserManager::class);
		foreach ([$this->intern, $this->kunde, $this->kollegin] as $uid) {
			$users->get($uid)?->delete();
		}

		parent::tearDown();
	}

	public function testALinkedFolderOpensWhereItLies(): void {
		$ticket = $this->ticket('public');

		$this->links->link($this->viewer($this->intern), $ticket, '/Inhalte/Schulungen/');

		$links = $this->linksFor($this->intern, $ticket);
		$this->assertCount(1, $links);
		$this->assertSame('Inhalte/Schulungen', $links[0]['path']);
		$this->assertSame('folder', $links[0]['type']);
		$this->assertFalse($links[0]['missing']);
		$this->assertStringContainsString('/f/' . $this->home->get('Inhalte/Schulungen')->getId(), (string)$links[0]['url']);
	}

	public function testALinkedFileStaysAtItsOriginalPlaceWithoutACopy(): void {
		$ticket = $this->ticket('internal');

		$this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Konzept.md');

		$this->assertSame(['Konzept.md', 'Schulungen'], $this->names($this->home->get('Inhalte')));
		$this->assertSame('file', $this->linksFor($this->intern, $ticket)[0]['type']);
	}

	public function testAPersonWithoutAccessDoesNotSeeTheLink(): void {
		$ticket = $this->ticket('public');
		$this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Schulungen');

		$this->assertSame([], $this->linksFor($this->kunde, $ticket));
	}

	public function testARenamedTargetIsStillFound(): void {
		$ticket = $this->ticket('internal');
		$this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Schulungen');

		$this->home->get('Inhalte/Schulungen')->move($this->home->getPath() . '/Inhalte/Schulungen-2027');

		$this->assertSame('Inhalte/Schulungen-2027', $this->linksFor($this->intern, $ticket)[0]['path']);
	}

	public function testAColleagueWithoutAccessSeesNeitherLinkNorName(): void {
		$ticket = $this->ticket('internal');
		$this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Konzept.md');

		$this->assertSame([], $this->linksFor($this->kollegin, $ticket));

		$this->home->get('Inhalte/Konzept.md')->delete();

		$this->assertSame([], $this->linksFor($this->kollegin, $ticket));
	}

	public function testADeletedTargetIsMissingOnlyForThePersonWhoLinkedIt(): void {
		$ticket = $this->ticket('public');
		$this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Konzept.md');

		$this->home->get('Inhalte/Konzept.md')->delete();

		$own = $this->linksFor($this->intern, $ticket);
		$this->assertCount(1, $own);
		$this->assertTrue($own[0]['missing']);
		$this->assertSame('Konzept.md', $own[0]['name']);
		$this->assertSame([], $this->linksFor($this->kunde, $ticket));
		$this->assertSame([], $this->linksFor($this->kollegin, $ticket));
	}

	public function testLinkingAndUnlinkingIsInTheHistoryWithoutTheName(): void {
		$ticket = $this->ticket('internal');
		$link = $this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Konzept.md');
		$this->links->delete($this->viewer($this->intern), $link['id']);

		$entries = array_values(array_filter(
			$this->read->history($this->viewer($this->intern), $ticket),
			static fn ($entry): bool => str_starts_with((string)$entry->getAction(), 'file_'),
		));
		$this->assertSame(['file_linked', 'file_unlinked'], array_map(static fn ($entry): string => (string)$entry->getAction(), $entries));
		$this->assertNull($entries[0]->getSubject());
	}

	public function testAMissingLinkCanBeRemovedAndTheFileIsNeverTouched(): void {
		$ticket = $this->ticket('internal');
		$link = $this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Konzept.md');

		$this->links->delete($this->viewer($this->intern), $link['id']);

		$this->assertSame([], $this->linksFor($this->intern, $ticket));
		$this->assertTrue($this->home->nodeExists('Inhalte/Konzept.md'));
	}

	public function testAChangeOfVisibilityLeavesTheLinkAlone(): void {
		$ticket = $this->ticket('internal');
		$this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Konzept.md');
		$version = (int)$this->read->show($this->viewer($this->intern), $ticket)['ticket']->getVersion();

		Server::get(TicketService::class)->changeVisibility($this->viewer($this->intern), $ticket, $version, 'public');

		$this->assertSame('Inhalte/Konzept.md', $this->linksFor($this->intern, $ticket)[0]['path']);
		$this->assertTrue($this->home->nodeExists('Inhalte/Konzept.md'));
	}

	public function testLinkingTheSameTargetTwiceKeepsOneLink(): void {
		$ticket = $this->ticket('internal');

		$first = $this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Konzept.md');
		$second = $this->links->link($this->viewer($this->intern), $ticket, 'Inhalte/Konzept.md');

		$this->assertSame($first['id'], $second['id']);
		$this->assertCount(1, $this->linksFor($this->intern, $ticket));
	}

	public function testAToolLinksByPathAndGetTicketReturnsPathAndUrl(): void {
		$ticket = $this->ticket('internal');

		$linked = $this->tool(LinkFile::class, ['ticket_id' => $ticket, 'path' => 'Inhalte/Schulungen']);
		$this->assertFalse($linked->isError(), json_encode($linked->toArray()));

		$data = json_decode((string)$this->tool(GetTicket::class, ['ticket_id' => $ticket])->toArray()['content'][0]['text'], true);
		$this->assertSame('Inhalte/Schulungen', $data['links'][0]['path']);
		$this->assertNotEmpty($data['links'][0]['url']);
	}

	public function testAnUnknownPathIsRefused(): void {
		$this->expectException(\OCP\Files\NotPermittedException::class);

		$this->links->link($this->viewer($this->intern), $this->ticket('internal'), 'Gibt/Es/Nicht');
	}

	private function viewer(string $uid): ViewerContext {
		return Server::get(BoardAccess::class)->contextFor($uid, $this->boardId);
	}

	private function ticket(string $visibility): int {
		$viewer = $this->viewer($this->intern);
		$column = Server::get(ColumnMapper::class)->findForBoard($viewer)[0];

		return (int)Server::get(TicketService::class)->create($viewer, 'Schulung planen', null, $visibility, (int)$column->getId())->getId();
	}

	/** @return list<array<string, mixed>> */
	private function linksFor(string $uid, int $ticketId): array {
		return $this->read->show($this->viewer($uid), $ticketId)['links'];
	}

	/** @return list<string> */
	private function names(Folder $folder): array {
		$names = array_map(static fn ($node): string => $node->getName(), $folder->getDirectoryListing());
		sort($names);

		return $names;
	}

	private function tool(string $class, array $arguments): \OCA\Projektwerk\Mcp\ToolResult {
		$limiter = $this->createStub(McpRateLimiter::class);
		$limiter->method('allowWrite')->willReturn(true);
		$parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
		$tool = new $class(...array_map(
			static fn (\ReflectionParameter $p): mixed => (string)$p->getType() === McpRateLimiter::class ? $limiter : Server::get((string)$p->getType()),
			$parameters,
		));

		return $tool->call(new AuthenticatedCaller($this->intern, 'test-client'), ['board_id' => $this->boardId] + $arguments);
	}
}
