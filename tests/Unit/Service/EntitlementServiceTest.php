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
 * gehören in den Integrations-/Rauchtest. Prüfbar ohne Netz ist die Grenzlogik
 * selbst: **standardmäßig greift nichts** (unbegrenzt, bis das Entitlement-
 * Backend einen positiven Wert setzt), und ab einem gesetzten Wert blockt die
 * jeweilige Regel. Bewusst als reine Methoden gekapselt.
 */
class EntitlementServiceTest extends TestCase {

	/**
	 * `null` heißt „nicht konfiguriert" — dann liefert der Config-Mock den
	 * Vorgabewert zurück (wie das echte {@see IAppConfig}), und die Grenze gilt
	 * als unbegrenzt.
	 */
	private function service(?int $maxCustomer = null, ?int $maxBoards = null): EntitlementService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => match ($key) {
				'plus_max_customer_projects' => $maxCustomer ?? $default,
				'plus_max_boards_per_project' => $maxBoards ?? $default,
				default => $default,
			},
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t): string => $t);

		return new EntitlementService($config, $this->createMock(IDBConnection::class), $l10n);
	}

	public function testUnconfiguredMeansUnlimited(): void {
		$service = $this->service();
		$this->assertSame(PHP_INT_MAX, $service->maxCustomerProjects());
		$this->assertSame(PHP_INT_MAX, $service->maxBoardsPerProject());
	}

	public function testZeroMeansUnlimited(): void {
		$service = $this->service(maxCustomer: 0, maxBoards: 0);
		$this->assertSame(PHP_INT_MAX, $service->maxCustomerProjects());
		$this->assertSame(PHP_INT_MAX, $service->maxBoardsPerProject());
	}

	public function testUnconfiguredNeverBlocks(): void {
		$service = $this->service();
		// Auch bei vielen bestehenden Kundenprojekten/Boards: keine Grenze.
		$this->assertFalse($service->blocksNewExternalMember(0, 999));
		$this->assertFalse($service->blocksAdditionalBoard(999));
	}

	public function testConfiguredLimitIsHonoured(): void {
		$service = $this->service(maxCustomer: 5, maxBoards: 3);
		$this->assertSame(5, $service->maxCustomerProjects());
		$this->assertSame(3, $service->maxBoardsPerProject());
	}

	// --- Ab hier: Grenze scharf (positiver Wert vom Backend gesetzt) ---------

	public function testFirstCustomerProjectIsFree(): void {
		// Erstes externes Mitglied (0 vorhanden), noch kein Kundenprojekt.
		$this->assertFalse($this->service(maxCustomer: 1)->blocksNewExternalMember(0, 0));
	}

	public function testSecondCustomerProjectIsBlocked(): void {
		// Erstes externes Mitglied hier, aber es gibt schon ein Kundenprojekt.
		$this->assertTrue($this->service(maxCustomer: 1)->blocksNewExternalMember(0, 1));
	}

	public function testFurtherExternalOnExistingCustomerProjectIsFree(): void {
		// Das Projekt ist bereits Kundenprojekt (>=1 externes Mitglied) — weitere
		// externe sind frei, auch am Limit.
		$this->assertFalse($this->service(maxCustomer: 1)->blocksNewExternalMember(2, 1));
	}

	public function testRaisedCustomerLimitAllowsSecond(): void {
		$this->assertFalse($this->service(maxCustomer: 2)->blocksNewExternalMember(0, 1));
	}

	public function testSecondBoardIsBlocked(): void {
		$this->assertTrue($this->service(maxBoards: 1)->blocksAdditionalBoard(1));
	}

	public function testFirstBoardIsFree(): void {
		$this->assertFalse($this->service(maxBoards: 1)->blocksAdditionalBoard(0));
	}

	public function testRaisedBoardLimitAllowsSecond(): void {
		$this->assertFalse($this->service(maxBoards: 2)->blocksAdditionalBoard(1));
	}
}
