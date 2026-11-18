<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Db\TicketLogMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\TicketLogService;
use OCP\Collaboration\Reference\IReferenceManager;
use PHPUnit\Framework\TestCase;

/**
 * Die Vorschaukarte wird beim Eintrag geleert und noch einmal, wenn die Transaktion des Aufrufers abgeschlossen ist.
 */
class TicketLogServiceTest extends TestCase {

	/** @var list<string> */
	private array $geleert = [];

	/** @var list<callable> */
	private array $aufgeschoben = [];

	private function service(): TicketLogService {
		$log = $this->createStub(TicketLogMapper::class);
		$log->method('insert')->willReturnArgument(0);
		$references = $this->createStub(IReferenceManager::class);
		$references->method('invalidateCache')->willReturnCallback(function (string $prefix): void {
			$this->geleert[] = $prefix;
		});
		$aufgeschoben = &$this->aufgeschoben;

		return new class($log, $this->createStub(TicketMapper::class), new TicketScope(), $references, $aufgeschoben) extends TicketLogService {
			/** @param list<callable> $aufgeschoben */
			public function __construct(TicketLogMapper $log, TicketMapper $tickets, TicketScope $scope, IReferenceManager $references, private array &$aufgeschoben) {
				parent::__construct($log, $tickets, $scope, $references);
			}

			protected function defer(callable $callback): void {
				$this->aufgeschoben[] = $callback;
			}
		};
	}

	public function testEveryChangedTicketIsInvalidatedOnceMoreAtTheEndOfTheRequest(): void {
		$service = $this->service();
		$service->recordFor('anna', 7, 'public', TicketLog::TITLE);
		$service->recordFor('anna', 7, 'public', TicketLog::DESCRIPTION);
		$service->recordFor('anna', 9, 'internal', TicketLog::TITLE);
		$this->assertSame(['7', '7', '9'], $this->geleert);
		$this->assertCount(1, $this->aufgeschoben, 'Ein Nachlauf je Anfrage, nicht je Eintrag.');

		$this->geleert = [];
		($this->aufgeschoben[0])();
		$this->assertSame(['7', '9'], $this->geleert);

		$this->geleert = [];
		$service->recordFor('anna', 11, 'public', TicketLog::TITLE);
		$this->assertCount(2, $this->aufgeschoben, 'Nach dem Nachlauf meldet sich der nächste Eintrag wieder an.');
		($this->aufgeschoben[1])();
		$this->assertSame(['11', '11'], $this->geleert);
	}
}
