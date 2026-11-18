<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\SetupCheck;

use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\TokenValidator;
use OCA\Projektwerk\Service\EntitlementService;
use OCA\Projektwerk\SetupCheck\McpSetupCheck;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

class McpSetupCheckTest extends TestCase {

	private function runWith(bool $switchedOn, bool $auditEnabled): SetupResult {
		$mcp = $this->createStub(McpConfig::class);
		$mcp->method('isSwitchedOn')->willReturn($switchedOn);
		$mcp->method('allowedClients')->willReturn(['claude']);
		$mcp->method('resourceUrl')->willReturn('https://cloud.example.org/apps/projektwerk/mcp');

		$entitlement = $this->createStub(EntitlementService::class);
		$entitlement->method('mcpAllowed')->willReturn(true);
		$validator = $this->createStub(TokenValidator::class);
		$validator->method('isAvailable')->willReturn(true);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('never');
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn('redis');
		$apps = $this->createStub(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => $app === 'admin_audit' && $auditEnabled);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return (new McpSetupCheck($mcp, $entitlement, $validator, $appConfig, $config, $apps, $l10n))->run();
	}

	public function testAFullySetUpEndpointWithAuditLogReportsSuccess(): void {
		$this->assertSame(SetupResult::SUCCESS, $this->runWith(true, true)->getSeverity());
	}

	public function testASwitchedOnEndpointWithoutAuditLogIsReported(): void {
		$result = $this->runWith(true, false);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('admin_audit', (string)$result->getDescription());
	}

	public function testASwitchedOffEndpointDoesNotAskForTheAuditLog(): void {
		$this->assertSame(SetupResult::SUCCESS, $this->runWith(false, false)->getSeverity());
	}
}
