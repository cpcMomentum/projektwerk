<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Service\AccountType;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\GuestNotAllowedException;
use OCA\Projektwerk\Service\NotManagerException;
use OCA\Projektwerk\Service\ProjectFolderService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Die Gast-Sperre in {@see BoardService::create()} (#280), ohne Datenbank.
 *
 * Der Kern: {@see AccountType} allein entscheidet nicht — sie muss auch
 * tatsaechlich *vor* der Transaktion gefragt werden. Ein Test, der nur
 * {@see AccountType} selbst prueft, faende eine vergessene Verdrahtung nicht.
 */
class BoardServiceTest extends TestCase {

	public function testAGuestCannotCreateABoard(): void {
		$accountType = $this->createStub(AccountType::class);
		$accountType->method('isGuest')->willReturn(true);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// Die Sperre greift vor der Transaktion — die DB wird gar nicht erst
		// angefasst.
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('beginTransaction');

		$service = new BoardService(
			$db,
			$this->createStub(BoardMapper::class),
			$this->createStub(ProjectMapper::class),
			$this->createStub(MemberMapper::class),
			$this->createStub(ColumnMapper::class),
			$this->createStub(BoardAccess::class),
			$l10n,
			$this->createStub(ProjectFolderService::class),
			$accountType,
		);

		$this->expectException(GuestNotAllowedException::class);

		$service->create('pw-guest', 'Neues Projekt');
	}

	/**
	 * #281: Ein Mitglied darf **nur** dann ein weiteres Board im Projekt anlegen,
	 * wenn das Projekt-Flag gesetzt ist. Ohne Flag und ohne Verwaltungsrecht →
	 * NotManagerException, bevor irgendetwas eingefügt wird.
	 */
	public function testAMemberCannotCreateABoardWithoutTheProjectFlag(): void {
		$project = new Project();
		$project->setMemberBoardsAllowed(0);
		$project->setOwnerUserId('owner');

		$projects = $this->createStub(ProjectMapper::class);
		$projects->method('findForViewer')->willReturn($project);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// createInProject öffnet die Transaktion vor dem Guard; scheitert der
		// Guard, wird zurückgerollt — eingefügt wird nichts.
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('rollBack');

		$boards = $this->createMock(BoardMapper::class);
		$boards->expects($this->never())->method('insert');

		$service = new BoardService(
			$db,
			$boards,
			$projects,
			$this->createStub(MemberMapper::class),
			$this->createStub(ColumnMapper::class),
			$this->createStub(BoardAccess::class),
			$l10n,
			$this->createStub(ProjectFolderService::class),
			$this->createStub(AccountType::class),
		);

		// Externes Mitglied ohne Verwaltungsrecht.
		$viewer = ViewerContext::forMember('u', 1, 9, ViewerContext::ROLE_EXTERNAL, false);

		$this->expectException(NotManagerException::class);
		$service->createInProject($viewer, 'Arbeitsgruppe A');
	}
}
