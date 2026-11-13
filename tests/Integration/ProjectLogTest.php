<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\ProjectLog;
use OCA\Projektwerk\Db\ProjectLogMapper;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\NotManagerException;
use OCA\Projektwerk\Service\ProjectLogService;
use OCA\Projektwerk\Service\TicketLogService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Server;
use Psr\Log\NullLogger;

class ProjectLogTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
	}

	public function testRoleAndManagerChangesAreRecordedWithOldAndNewValue(): void {
		$anna = $this->anna();
		$members = Server::get(MemberService::class);

		$members->update($anna, LeakMatrixFixture::BERT, ['isManager' => true]);
		$members->update($anna, LeakMatrixFixture::DIRK, ['role' => ViewerContext::ROLE_INTERNAL]);

		$eintraege = $this->eintraege();
		$this->assertContains([ProjectLog::MANAGER_GRANTED, LeakMatrixFixture::BERT, null, null], $eintraege);
		$this->assertContains([ProjectLog::ROLE_CHANGED, LeakMatrixFixture::DIRK, ViewerContext::ROLE_EXTERNAL, ViewerContext::ROLE_INTERNAL], $eintraege);
	}

	public function testRemovingAMemberAndArchivingABoardAreRecorded(): void {
		$anna = $this->anna();
		Server::get(MemberService::class)->remove($anna, LeakMatrixFixture::DIRK);
		Server::get(BoardService::class)->setArchived($anna, true);
		Server::get(BoardService::class)->setArchived($anna, true);

		$aktionen = array_column($this->eintraege(), 0);
		$this->assertContains(ProjectLog::MEMBER_REMOVED, $aktionen);
		$this->assertSame(1, count(array_keys($aktionen, ProjectLog::BOARD_ARCHIVED, true)), 'Nur der echte Wechsel zählt.');
	}

	public function testOnlyInternalManagersMayReadTheLog(): void {
		$this->expectException(NotManagerException::class);

		Server::get(ProjectLogService::class)->listFor($this->fixture->contextFor(LeakMatrixFixture::BERT));
	}

	public function testEveryEntryAlsoGoesToTheAdminLog(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->once())->method('dispatchTyped')->with($this->isInstanceOf(CriticalActionPerformedEvent::class));

		$service = new ProjectLogService(Server::get(ProjectLogMapper::class), $events, Server::get(IUserManager::class), $this->createStub(IL10N::class), new NullLogger());
		$service->record($this->anna(), ProjectLog::MEMBER_REMOVED, LeakMatrixFixture::DIRK);
	}

	public function testEditingAndDeletingACommentLeavesANoteWithoutTheText(): void {
		$anna = $this->anna();
		$comments = Server::get(CommentService::class);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$comment = $comments->create($anna, $ticketId, 'Erste Fassung');
		$comments->update($anna, (int)$comment->getId(), 'Zweite Fassung');
		$comments->delete($anna, (int)$comment->getId());

		$ticket = Server::get(TicketMapper::class)->findVisible($anna, $ticketId);
		$verlauf = array_filter(
			Server::get(TicketLogService::class)->listFor($anna, $ticket),
			static fn (TicketLog $e): bool => in_array($e->getAction(), [TicketLog::COMMENT_EDITED, TicketLog::COMMENT_DELETED], true),
		);
		$this->assertSame([TicketLog::COMMENT_EDITED, TicketLog::COMMENT_DELETED], array_values(array_map(static fn (TicketLog $e): string => (string)$e->getAction(), $verlauf)));
		foreach ($verlauf as $eintrag) {
			$this->assertNull($eintrag->getSubject());
			$this->assertNull($eintrag->getOldValue());
		}
	}

	public function testOlderPagesContinueBeforeTheOldestLoadedEntry(): void {
		$log = Server::get(ProjectLogService::class);
		for ($i = 1; $i <= ProjectLogService::PAGE_SIZE + 5; $i++) {
			$log->record($this->anna(), ProjectLog::BOARD_CREATED, null, 'B' . $i);
		}

		$erste = $log->listFor($this->anna());
		$this->assertCount(ProjectLogService::PAGE_SIZE, $erste['entries']);
		$this->assertTrue($erste['hasMore']);
		$this->assertSame('B' . (ProjectLogService::PAGE_SIZE + 5), $erste['entries'][0]['subject']);

		$log->record($this->anna(), ProjectLog::BOARD_CREATED, null, 'neu dazwischen');
		$zweite = $log->listFor($this->anna(), (int)end($erste['entries'])['id']);
		$this->assertSame(['B5', 'B4', 'B3', 'B2', 'B1'], array_column($zweite['entries'], 'subject'));
		$this->assertFalse($zweite['hasMore']);
	}

	private function anna(): ViewerContext {
		return $this->fixture->contextFor(LeakMatrixFixture::ANNA);
	}

	/** @return list<array{0: string, 1: ?string, 2: ?string, 3: ?string}> */
	private function eintraege(): array {
		return array_map(
			static fn (array $e): array => [$e['action'], $e['targetUserId'], $e['oldValue'], $e['newValue']],
			Server::get(ProjectLogService::class)->listFor($this->anna())['entries'],
		);
	}
}
