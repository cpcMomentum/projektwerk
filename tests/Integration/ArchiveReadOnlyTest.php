<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Controller\BoardController;
use OCA\Projektwerk\Controller\CommentController;
use OCA\Projektwerk\Controller\SettingsController;
use OCA\Projektwerk\Controller\TicketController;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Mcp\Tools\AddComment;
use OCA\Projektwerk\Middleware\ArchiveMiddleware;
use OCA\Projektwerk\Middleware\ArchivedBoardException;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\EntitlementService;
use OCA\Projektwerk\Service\WerkPlusLimitException;
use OCP\AppFramework\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\L10N\IFactory;
use OCP\IRequest;
use OCP\Server;

/**
 * Archivierte Boards und Projekte sind nur lesbar und zählen bei keiner Grenze mit.
 */
class ArchiveReadOnlyTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
	}

	protected function tearDown(): void {
		$config = Server::get(IAppConfig::class);
		$config->deleteKey('projektwerk', McpConfig::KEY_PUBLIC_WRITE);
		parent::tearDown();
	}

	public function testTheViewerOfAnArchivedBoardOrProjectIsReadOnly(): void {
		$this->assertFalse($this->viewer()->readOnly);

		$this->archiveBoard($this->fixture->boardId);
		$this->assertTrue($this->viewer()->readOnly, 'Archiviertes Board');

		$this->archiveBoard($this->fixture->boardId, false);
		$this->archiveProject($this->fixture->projectId);
		$this->assertTrue($this->viewer()->readOnly, 'Board in archiviertem Projekt');
	}

	public function testTheMiddlewareRefusesWritesInAnArchivedBoard(): void {
		$this->archiveBoard($this->fixture->boardId);

		$this->expectException(ArchivedBoardException::class);
		$this->middleware('POST')->beforeController(CommentController::class, 'create');
	}

	public function testTheRefusalIsALockedResponseWithAMessage(): void {
		$response = $this->middleware('POST')->afterException(CommentController::class, 'create', new ArchivedBoardException());

		$this->assertSame(Http::STATUS_LOCKED, $response->getStatus());
		$this->assertNotEmpty($response->getData()['error']);
	}

	/**
	 * @return iterable<string, array{0: string, 1: string, 2: string}>
	 */
	public static function stillOpenInTheArchive(): iterable {
		yield 'lesen' => ['GET', TicketController::class, 'show'];
		yield 'gelesen markieren' => ['POST', TicketController::class, 'read'];
		yield 'anheften' => ['PUT', BoardController::class, 'setPin'];
		yield 'zurückholen' => ['PUT', SettingsController::class, 'archiveBoard'];
		yield 'Projekt zurückholen' => ['PUT', SettingsController::class, 'archiveProject'];
		yield 'Mitglied entfernen' => ['DELETE', SettingsController::class, 'removeMember'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('stillOpenInTheArchive')]
	public function testSomeWaysStayOpenInTheArchive(string $verb, string $controller, string $method): void {
		$this->archiveBoard($this->fixture->boardId);

		$this->middleware($verb)->beforeController($controller, $method);
		$this->addToAssertionCount(1);
	}

	public function testANonMemberGetsNoHintThatTheBoardIsArchived(): void {
		$this->archiveBoard($this->fixture->boardId);

		$this->middleware('POST', LeakMatrixFixture::FREMD)->beforeController(CommentController::class, 'create');
		$this->addToAssertionCount(1);
	}

	public function testAnActiveBoardIsNotBlocked(): void {
		$this->middleware('POST')->beforeController(CommentController::class, 'create');
		$this->addToAssertionCount(1);
	}

	public function testTheAiToolCannotWriteIntoAnArchivedBoard(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(true);
		$this->archiveBoard($this->fixture->boardId);

		$result = $this->mcp(AddComment::class, ['ticket_id' => $this->fixture->ticketIds['internal/anna'], 'body' => 'Notiz']);

		$this->assertTrue($result->isError());
		$this->assertStringContainsString('archived', (string)$result->toArray()['content'][0]['text']);
	}

	public function testTheProjectLimitBlocksTheEleventhProjectButArchivedOnesDoNotCount(): void {
		$this->onlyTheseProjectsActive($this->fixture->projectId);
		$this->addBareProjects(EntitlementService::FREE_PROJECTS - 1);
		$this->withoutWerkPlus('pw_projects');
		$service = Server::get(BoardService::class);

		try {
			$service->create(LeakMatrixFixture::ANNA, 'Das elfte');
			$this->fail('Das elfte aktive Projekt hätte geblockt werden müssen.');
		} catch (WerkPlusLimitException $e) {
			$this->assertStringContainsString('10', $e->getMessage());
		}

		$this->archiveProject($this->fixture->projectId);
		$service->create(LeakMatrixFixture::ANNA, 'Platz durch Archiv');
		$this->addToAssertionCount(1);
	}

	public function testWithPwProjectsTheEleventhProjectIsCreated(): void {
		$this->onlyTheseProjectsActive($this->fixture->projectId);
		$this->addBareProjects(EntitlementService::FREE_PROJECTS - 1);

		Server::get(BoardService::class)->create(LeakMatrixFixture::ANNA, 'Das elfte');
		$this->addToAssertionCount(1);
	}

	public function testUnarchivingAProjectAboveTheLimitIsBlocked(): void {
		$this->onlyTheseProjectsActive();
		$this->addBareProjects(EntitlementService::FREE_PROJECTS);
		$this->withoutWerkPlus('pw_projects');

		$this->expectException(WerkPlusLimitException::class);
		Server::get(BoardService::class)->setProjectArchived($this->viewer(), false);
	}

	public function testAnArchivedCustomerProjectDoesNotCount(): void {
		$this->onlyTheseProjectsActive($this->fixture->projectId);
		$this->archiveProject($this->fixture->projectId);
		$this->withoutWerkPlus('pw_customer_projects');

		$board = Server::get(BoardService::class)->create(LeakMatrixFixture::ANNA, 'Neuer Kunde');
		$member = new Member();
		$member->setBoardId((int)$board->getId());
		$member->setProjectId((int)$board->getProjectId());
		$member->setUserId(LeakMatrixFixture::CARLA);
		$member->setRole('external');
		$member->setIsManager(0);
		$member->setAddedBy(LeakMatrixFixture::ANNA);
		$member->setAddedAt(new \DateTime());
		Server::get(MemberMapper::class)->insert($member);

		$this->expectException(WerkPlusLimitException::class);
		Server::get(BoardService::class)->setProjectArchived($this->viewer(), false);
	}

	private function viewer(): \OCA\Projektwerk\Access\ViewerContext {
		return Server::get(BoardAccess::class)->contextFor(LeakMatrixFixture::ANNA, $this->fixture->boardId);
	}

	private function middleware(string $verb, string $userId = LeakMatrixFixture::ANNA): ArchiveMiddleware {
		$request = $this->createStub(IRequest::class);
		$request->method('getMethod')->willReturn($verb);
		$request->method('getParam')->willReturnCallback(fn (string $key): mixed => $key === 'boardId' ? (string)$this->fixture->boardId : null);

		return new ArchiveMiddleware($request, Server::get(BoardAccess::class), Server::get(IFactory::class)->get('projektwerk'), $userId);
	}

	/** @param array<string, mixed> $arguments */
	private function mcp(string $class, array $arguments): ToolResult {
		$limiter = $this->createStub(McpRateLimiter::class);
		$limiter->method('allowWrite')->willReturn(true);
		$parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
		$tool = new $class(...array_map(
			static fn (\ReflectionParameter $p): mixed => (string)$p->getType() === McpRateLimiter::class ? $limiter : Server::get((string)$p->getType()),
			$parameters,
		));

		return $tool->call(new AuthenticatedCaller(LeakMatrixFixture::ANNA, 'test-client'), ['board_id' => $this->fixture->boardId] + $arguments);
	}

	private function addBareProjects(int $count): void {
		for ($i = 0; $i < $count; $i++) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('pwerk_projects')->values([
				'title' => $qb->createNamedParameter('Füllprojekt ' . $i),
				'owner_user_id' => $qb->createNamedParameter(LeakMatrixFixture::ANNA),
				'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE),
				'updated_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE),
			])->executeStatement();
		}
	}

	private function archiveBoard(int $boardId, bool $archived = true): void {
		$this->setArchived('pwerk_boards', $boardId, $archived);
	}

	private function archiveProject(int $projectId): void {
		$this->setArchived('pwerk_projects', $projectId, true);
	}

	private function setArchived(string $table, int $id, bool $archived): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set('archived', $qb->createNamedParameter($archived ? 1 : 0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
