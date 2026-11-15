<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\License\LicenseService;
use OCA\Projektwerk\Service\EntitlementService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class EntitlementServiceTest extends TestCase {

	/** @param list<string> $flags */
	private function service(array $flags = []): EntitlementService {
		$license = $this->createStub(LicenseService::class);
		$license->method('hasFeature')->willReturnCallback(static fn (string $flag): bool => in_array($flag, $flags, true));

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t): string => $t);

		return new EntitlementService($license, $this->createStub(IDBConnection::class), $l10n);
	}

	public function testWithoutLicenseTheFreeScopeApplies(): void {
		$service = $this->service();
		$this->assertSame(10, $service->maxActiveProjects());
		$this->assertSame(1, $service->maxCustomerProjects());
		$this->assertSame(1, $service->maxBoardsPerProject());
		$this->assertFalse($service->mailReplyAllowed());
		$this->assertFalse($service->mcpAllowed());
	}

	/**
	 * @return iterable<string, array{0: string, 1: callable(EntitlementService): mixed, 2: mixed}>
	 */
	public static function eachFlagLiftsItsOwnLimit(): iterable {
		yield 'pw_projects' => ['pw_projects', static fn (EntitlementService $s): int => $s->maxActiveProjects(), PHP_INT_MAX];
		yield 'pw_customer_projects' => ['pw_customer_projects', static fn (EntitlementService $s): int => $s->maxCustomerProjects(), PHP_INT_MAX];
		yield 'pw_multi_board' => ['pw_multi_board', static fn (EntitlementService $s): int => $s->maxBoardsPerProject(), PHP_INT_MAX];
		yield 'pw_mail_reply' => ['pw_mail_reply', static fn (EntitlementService $s): bool => $s->mailReplyAllowed(), true];
		yield 'pw_mcp' => ['pw_mcp', static fn (EntitlementService $s): bool => $s->mcpAllowed(), true];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('eachFlagLiftsItsOwnLimit')]
	public function testEachFlagLiftsOnlyItsOwnLimit(string $flag, callable $read, mixed $lifted): void {
		$this->assertSame($lifted, $read($this->service([$flag])));
		$others = array_values(array_diff(LicenseService::FLAGS, [$flag]));
		$this->assertNotSame($lifted, $read($this->service($others)), 'Ein anderes Flag darf diese Grenze nicht aufheben.');
	}

	public function testFirstCustomerProjectIsFree(): void {
		$this->assertFalse($this->service()->blocksNewExternalMember(0, 0));
	}

	public function testSecondCustomerProjectIsBlocked(): void {
		$this->assertTrue($this->service()->blocksNewExternalMember(0, 1));
		$this->assertFalse($this->service(['pw_customer_projects'])->blocksNewExternalMember(0, 1));
	}

	public function testFurtherExternalOnExistingCustomerProjectIsFree(): void {
		$this->assertFalse($this->service()->blocksNewExternalMember(2, 1));
	}

	public function testSecondBoardIsBlocked(): void {
		$this->assertFalse($this->service()->blocksAdditionalBoard(0));
		$this->assertTrue($this->service()->blocksAdditionalBoard(1));
		$this->assertFalse($this->service(['pw_multi_board'])->blocksAdditionalBoard(1));
	}

	public function testTheEleventhProjectIsBlocked(): void {
		$this->assertFalse($this->service()->blocksAdditionalProject(9));
		$this->assertTrue($this->service()->blocksAdditionalProject(10));
		$this->assertFalse($this->service(['pw_projects'])->blocksAdditionalProject(10));
	}
}
