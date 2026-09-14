<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\EntitlementService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * WerkPlus-Grenzen (#288) — die Regeln, ohne Datenbank.
 *
 * Die Zählungen (wie viele Kundenprojekte, wie viele Boards) fragen die DB und
 * gehören in den Integrations-/Rauchtest. Prüfbar ohne Netz sind die beiden
 * Entscheidungen selbst und der Config-Fallback (`0`/leer → `1`) — bewusst als
 * reine Methoden gekapselt, damit genau die Grenzlogik eine Maschine hütet.
 */
class EntitlementServiceTest extends TestCase {

	private function service(int $maxCustomer = 1, int $maxBoards = 1): EntitlementService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => match ($key) {
				'plus_max_customer_projects' => $maxCustomer,
				'plus_max_boards_per_project' => $maxBoards,
				default => $default,
			},
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t): string => $t);

		return new EntitlementService($config, $this->createMock(IDBConnection::class), $l10n);
	}

	public function testDefaultsToOne(): void {
		$service = $this->service();
		$this->assertSame(1, $service->maxCustomerProjects());
		$this->assertSame(1, $service->maxBoardsPerProject());
	}

	public function testZeroFallsBackToOne(): void {
		$service = $this->service(maxCustomer: 0, maxBoards: 0);
		$this->assertSame(1, $service->maxCustomerProjects());
		$this->assertSame(1, $service->maxBoardsPerProject());
	}

	public function testHonoursRaisedLimits(): void {
		$service = $this->service(maxCustomer: 5, maxBoards: 3);
		$this->assertSame(5, $service->maxCustomerProjects());
		$this->assertSame(3, $service->maxBoardsPerProject());
	}

	public function testFirstCustomerProjectIsFree(): void {
		// Erstes externes Mitglied (0 vorhanden), noch kein Kundenprojekt.
		$this->assertFalse($this->service()->blocksNewExternalMember(0, 0));
	}

	public function testSecondCustomerProjectIsBlocked(): void {
		// Erstes externes Mitglied hier, aber es gibt schon ein Kundenprojekt.
		$this->assertTrue($this->service()->blocksNewExternalMember(0, 1));
	}

	public function testFurtherExternalOnExistingCustomerProjectIsFree(): void {
		// Das Projekt ist bereits Kundenprojekt (>=1 externes Mitglied) — weitere
		// externe sind frei, auch am Limit.
		$this->assertFalse($this->service()->blocksNewExternalMember(2, 1));
	}

	public function testRaisedCustomerLimitAllowsSecond(): void {
		$this->assertFalse($this->service(maxCustomer: 2)->blocksNewExternalMember(0, 1));
	}

	public function testSecondBoardIsBlocked(): void {
		$this->assertTrue($this->service()->blocksAdditionalBoard(1));
	}

	public function testFirstBoardIsFree(): void {
		$this->assertFalse($this->service()->blocksAdditionalBoard(0));
	}

	public function testRaisedBoardLimitAllowsSecond(): void {
		$this->assertFalse($this->service(maxBoards: 2)->blocksAdditionalBoard(1));
	}
}
