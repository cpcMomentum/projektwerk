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
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Service\BoardReadModel;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\NotManagerException;
use OCA\Projektwerk\Service\StepService;
use OCA\Projektwerk\Service\TicketService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Ein leeres Board löschen: nur leer, nie das letzte, nie fremde Vorgänge.
 */
final class BoardDeleteTest extends IntegrationTestCase {

	private const ANNA = 'del-anna';
	private const BERT = 'del-bert';
	private const CARLA = 'del-carla';

	private BoardService $service;
	private Board $boardA;
	private Board $boardB;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(BoardService::class);

		$this->boardA = $this->service->create(self::ANNA, 'Projekt Löschen');
		$this->boardB = $this->service->createInProject($this->viewer(self::ANNA, (int)$this->boardA->getId()), 'Versehen');
		$this->member(self::BERT, ViewerContext::ROLE_INTERNAL, (int)$this->boardB->getId());
		$this->member(self::CARLA, ViewerContext::ROLE_EXTERNAL, (int)$this->boardA->getId());
	}

	public function testAnEmptyBoardIsDeletedAndHomeMembersMoveToTheRemainingBoard(): void {
		$anna = $this->viewer(self::ANNA, (int)$this->boardB->getId());
		$this->assertTrue($this->deletable(self::ANNA, $this->boardB), 'Ein leeres Board muss als löschbar gelten.');

		$result = $this->service->delete($anna);

		$this->assertSame((int)$this->boardA->getId(), $result['nextBoardId']);
		$this->assertSame(0, $this->rows('pwerk_boards', 'id', (int)$this->boardB->getId()));
		$this->assertSame(0, $this->rows('pwerk_columns', 'board_id', (int)$this->boardB->getId()));
		$this->assertSame(0, $this->rows('pwerk_members', 'board_id', (int)$this->boardB->getId()), 'Keine Mitgliedszeile zeigt auf das gelöschte Board.');

		$bert = $this->viewer(self::BERT, (int)$this->boardA->getId());
		$this->assertSame(ViewerContext::ROLE_INTERNAL, $bert->role, 'Bert bleibt Mitglied des Projekts.');
		$this->assertCount(3, Server::get(MemberMapper::class)->findForBoard($bert));
	}

	public function testABoardWithAVisibleTicketIsRefused(): void {
		$anna = $this->viewer(self::ANNA, (int)$this->boardB->getId());
		$this->ticket($anna, TicketScope::VISIBILITY_PUBLIC);

		$this->assertFalse($this->deletable(self::ANNA, $this->boardB));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->delete($anna);
	}

	public function testATicketTheDeleterCannotSeeBlocksTheDeleteWithoutRevealingIt(): void {
		$bert = $this->viewer(self::BERT, (int)$this->boardB->getId());
		$this->ticket($bert, TicketScope::VISIBILITY_PRIVATE);
		$anna = $this->viewer(self::ANNA, (int)$this->boardB->getId());
		$this->assertSame([], Server::get(\OCA\Projektwerk\Db\TicketMapper::class)->findVisibleInBoard($anna), 'Anna darf Berts privaten Vorgang nicht sehen.');

		$this->assertFalse($this->deletable(self::ANNA, $this->boardB));
		try {
			$this->service->delete($anna);
			$this->fail('Ein Board mit einem fremden Vorgang darf nicht gelöscht werden.');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringNotContainsString('1', $e->getMessage(), 'Die Meldung nennt keine Anzahl.');
		}
		$this->assertSame(1, $this->rows('pwerk_boards', 'id', (int)$this->boardB->getId()));
		$this->assertSame(1, $this->rows('pwerk_tickets', 'board_id', (int)$this->boardB->getId()));
	}

	public function testDeletedTicketsCountAsEmptyAndGoWithTheBoard(): void {
		$anna = $this->viewer(self::ANNA, (int)$this->boardB->getId());
		$ticket = $this->ticket($anna, TicketScope::VISIBILITY_PUBLIC);
		Server::get(StepService::class)->create($anna, (int)$ticket->getId(), 'Schritt');
		Server::get(CommentService::class)->create($anna, (int)$ticket->getId(), 'Kommentar');
		$aktuell = Server::get(\OCA\Projektwerk\Db\TicketMapper::class)->findVisible($anna, (int)$ticket->getId());
		Server::get(TicketService::class)->delete($anna, (int)$ticket->getId(), (int)$aktuell->getVersion());

		$this->assertTrue($this->deletable(self::ANNA, $this->boardB));
		$this->service->delete($anna);

		$this->assertSame(0, $this->rows('pwerk_tickets', 'id', (int)$ticket->getId()));
		$this->assertSame(0, $this->rows('pwerk_steps', 'ticket_id', (int)$ticket->getId()));
		$this->assertSame(0, $this->rows('pwerk_comments', 'ticket_id', (int)$ticket->getId()));
	}

	public function testTheLastBoardOfAProjectIsRefused(): void {
		$this->service->delete($this->viewer(self::ANNA, (int)$this->boardB->getId()));
		$anna = $this->viewer(self::ANNA, (int)$this->boardA->getId());

		$this->assertFalse($this->deletable(self::ANNA, $this->boardA));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->delete($anna);
	}

	public function testOnlyManagersAndTheBoardCreatorMayDelete(): void {
		$this->assertFalse($this->deletable(self::BERT, $this->boardB), 'Bert ist weder Verwalter noch Ersteller.');

		$this->expectException(NotManagerException::class);
		$this->service->delete($this->viewer(self::BERT, (int)$this->boardB->getId()));
	}

	public function testOtherBoardsAndTheirColumnsStayUntouched(): void {
		$vorher = count(Server::get(ColumnMapper::class)->findForBoard($this->viewer(self::ANNA, (int)$this->boardA->getId())));

		$this->service->delete($this->viewer(self::ANNA, (int)$this->boardB->getId()));

		$this->assertSame(1, $this->rows('pwerk_boards', 'id', (int)$this->boardA->getId()));
		$this->assertSame($vorher, count(Server::get(ColumnMapper::class)->findForBoard($this->viewer(self::ANNA, (int)$this->boardA->getId()))));
	}

	public function testAnActiveBoardIsPreferredOverAnArchivedOneAsTheNextBoard(): void {
		$this->service->setArchived($this->viewer(self::ANNA, (int)$this->boardA->getId()), true);
		$aktiv = $this->service->createInProject($this->viewer(self::ANNA, (int)$this->boardA->getId()), 'Zweites aktiv');

		$result = $this->service->delete($this->viewer(self::ANNA, (int)$this->boardB->getId()));

		$this->assertSame((int)$aktiv->getId(), $result['nextBoardId']);
	}

	private function viewer(string $userId, int $boardId): ViewerContext {
		return Server::get(BoardAccess::class)->contextFor($userId, $boardId);
	}

	private function deletable(string $userId, Board $board): bool {
		return Server::get(BoardReadModel::class)->show($this->viewer($userId, (int)$board->getId()))['deletable'];
	}

	private function member(string $userId, string $role, int $homeBoardId): void {
		$member = new Member();
		$member->setBoardId($homeBoardId);
		$member->setProjectId((int)$this->boardA->getProjectId());
		$member->setUserId($userId);
		$member->setRole($role);
		$member->setIsManager(0);
		$member->setDisplayName(null);
		$member->setAddedBy(self::ANNA);
		$member->setAddedAt(new \DateTime());
		Server::get(MemberMapper::class)->insert($member);
	}

	private function ticket(ViewerContext $viewer, string $visibility): \OCA\Projektwerk\Db\Ticket {
		$column = Server::get(ColumnMapper::class)->findForBoard($viewer)[0];

		return Server::get(TicketService::class)->create($viewer, 'Vorgang', null, $visibility, (int)$column->getId());
	}

	private function rows(string $table, string $column, int $value): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
