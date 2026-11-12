<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\ColumnService;
use OCA\Projektwerk\Service\NotManagerException;
use OCA\Projektwerk\Service\TicketLogService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Der Board-Einrichter-Guard beim Spalten-Anlegen (#281).
 *
 * Board-scopes Recht: Projekt-Manager **oder** Ersteller genau dieses Boards
 * dürfen die Spalten pflegen; ein anderes Mitglied nicht.
 */
class ColumnServiceTest extends TestCase {

	private function serviceWith(?ColumnMapper $columns = null): ColumnService {
		return new ColumnService(
			$this->createStub(IDBConnection::class),
			$columns ?? $this->createStub(ColumnMapper::class),
			$this->createStub(BoardMapper::class),
			$this->createStub(TicketMapper::class),
			$this->createStub(TicketLogService::class),
			$this->l10n(),
		);
	}

	private function viewer(string $role, bool $isManager, bool $isCreator): ViewerContext {
		return ViewerContext::forMember('u', 1, 9, $role, $isManager, $isCreator);
	}

	public function testAPlainMemberCannotCreateColumns(): void {
		$service = $this->serviceWith();

		$this->expectException(NotManagerException::class);
		$service->create(
			$this->viewer(ViewerContext::ROLE_EXTERNAL, isManager: false, isCreator: false),
			'Neu',
		);
	}

	public function testTheBoardCreatorMayCreateColumns(): void {
		$columns = $this->createStub(ColumnMapper::class);
		$columns->method('findForBoard')->willReturn([]);
		$columns->method('insert')->willReturnArgument(0);

		$service = $this->serviceWith($columns);

		// Externer Ersteller — kein Manager, aber Ersteller seines Boards.
		$column = $service->create(
			$this->viewer(ViewerContext::ROLE_EXTERNAL, isManager: false, isCreator: true),
			'Backlog',
		);

		$this->assertInstanceOf(Column::class, $column);
		$this->assertSame('Backlog', $column->getTitle());
	}

	public function testAManagerMayCreateColumns(): void {
		$columns = $this->createStub(ColumnMapper::class);
		$columns->method('findForBoard')->willReturn([]);
		$columns->method('insert')->willReturnArgument(0);

		$service = $this->serviceWith($columns);

		$column = $service->create(
			$this->viewer(ViewerContext::ROLE_INTERNAL, isManager: true, isCreator: false),
			'Backlog',
		);

		$this->assertInstanceOf(Column::class, $column);
	}

	private function l10n(): IL10N {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l10n;
	}
}
