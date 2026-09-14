<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Service\AccountType;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\GuestNotAllowedException;
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
}
