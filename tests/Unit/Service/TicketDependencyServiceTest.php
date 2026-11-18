<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketDepMapper;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\TicketDependencyService;
use OCA\Projektwerk\Service\TicketLogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TicketDependencyServiceTest extends TestCase {

	private TicketLogService&MockObject $log;

	private function service(bool $inserted): TicketDependencyService {
		$partner = new Ticket();
		$partner->setVisibility('internal');
		$tickets = $this->createStub(TicketMapper::class);
		$tickets->method('findVisible')->willReturn($partner);
		$tickets->method('findVisibleDependents')->willReturn([]);
		$tickets->method('findVisibleDependencies')->willReturn([]);
		$deps = $this->createStub(TicketDepMapper::class);
		$deps->method('insertPair')->willReturn($inserted);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-18 12:00:00'));
		$this->log = $this->createMock(TicketLogService::class);

		return new TicketDependencyService($tickets, $deps, $this->log, $time, $this->createStub(IL10N::class));
	}

	private function viewer(): ViewerContext {
		return ViewerContext::forMember('anna', 7, 99, ViewerContext::ROLE_INTERNAL, false, false, false);
	}

	public function testANewLinkIsRecordedWithThePartnersVisibilityAndWithoutItsTitle(): void {
		$service = $this->service(true);
		$this->log->expects($this->once())->method('record')
			->with($this->anything(), $this->anything(), TicketLog::DEPENDENCY_ADDED, null, 'internal', '2');

		$service->add($this->viewer(), 1, 2);
	}

	public function testALinkThatAnotherRequestCreatedFirstIsNotRecordedTwice(): void {
		$service = $this->service(false);
		$this->log->expects($this->never())->method('record');

		$service->add($this->viewer(), 1, 2);
	}
}
