<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Mcp;

use OCA\Projektwerk\Mcp\McpConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class McpConfigTest extends TestCase {

	/**
	 * @param array<string, string> $values
	 */
	private function config(array $values): McpConfig {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $values[$key] ?? $default,
		);

		return new McpConfig($appConfig);
	}

	public function testOnlyFullyConfiguredEndpointIsEnabled(): void {
		$full = ['mcp_enabled' => 'yes', 'mcp_resource_url' => 'https://c.example/apps/projektwerk/mcp', 'mcp_allowed_clients' => 'a, b ,'];

		$this->assertTrue($this->config($full)->isEnabled());
		$this->assertSame(['a', 'b'], $this->config($full)->allowedClients());
		$this->assertFalse($this->config(['mcp_enabled' => 'no'] + $full)->isEnabled());
		$this->assertFalse($this->config(['mcp_resource_url' => ''] + $full)->isEnabled());
		$this->assertFalse($this->config(['mcp_allowed_clients' => ' , '] + $full)->isEnabled());
	}

	public function testOriginMustBeOurOwnWhenSent(): void {
		$config = $this->config(['mcp_resource_url' => 'https://Cloud.Example:8443/index.php/apps/projektwerk/mcp']);

		$this->assertTrue($config->originAllowed(''), 'ohne Origin (Server-zu-Server)');
		$this->assertTrue($config->originAllowed('https://cloud.example:8443'), 'eigener, Groß/klein egal');
		$this->assertTrue($config->originAllowed('https://cloud.example:8443/'));
		$this->assertFalse($config->originAllowed('https://evil.example'));
		$this->assertFalse($config->originAllowed('https://cloud.example'), 'anderer Port');
		$this->assertFalse($config->originAllowed('http://cloud.example:8443'), 'anderes Schema');
		$this->assertFalse($config->originAllowed('null'));
		$this->assertFalse($this->config([])->originAllowed('https://cloud.example'), 'ohne URL zu');
	}
}
