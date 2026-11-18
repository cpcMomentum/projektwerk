<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Mcp;

use OCA\Projektwerk\Controller\AiAccessController;
use OCA\Projektwerk\Controller\McpController;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\BearerAuthenticator;
use OCA\Projektwerk\Mcp\JsonRpcDispatcher;
use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\McpResponse;
use OCA\Projektwerk\Mcp\RequestBody;
use OCA\Projektwerk\Service\EntitlementService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Der KI-Zugriff über MCP ist als ganze Funktion an `pw_mcp` gebunden.
 */
class McpWerkPlusGateTest extends TestCase {

	private function entitlement(bool $mcp): EntitlementService {
		$entitlement = $this->createStub(EntitlementService::class);
		$entitlement->method('mcpAllowed')->willReturn($mcp);

		return $entitlement;
	}

	private function controller(bool $mcp, JsonRpcDispatcher $dispatcher): McpController {
		$config = $this->createStub(McpConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('originAllowed')->willReturn(true);
		$authenticator = $this->createStub(BearerAuthenticator::class);
		$authenticator->method('authenticate')->willReturn(new AuthenticatedCaller('anna', 'claude'));
		$body = $this->createStub(RequestBody::class);
		$body->method('read')->willReturn('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

		return new McpController($this->createStub(IRequest::class), $config, $this->entitlement($mcp), $authenticator, $dispatcher, $body, $this->createStub(LoggerInterface::class));
	}

	public function testWithoutPwMcpEveryRequestIsRefusedWithAReason(): void {
		$dispatcher = $this->createMock(JsonRpcDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatch');

		$response = $this->controller(false, $dispatcher)->handle();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('WerkPlus', $response->getData()['error']['message']);
	}

	public function testWithPwMcpTheRequestIsServed(): void {
		$dispatcher = $this->createMock(JsonRpcDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatch')->willReturn(McpResponse::result(1, ['tools' => []]));

		$response = $this->controller(true, $dispatcher)->handle();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testWithoutPwMcpTheAccessCannotBeConfigured(): void {
		$config = $this->createMock(McpConfig::class);
		$config->expects($this->never())->method('setPublicWriteAllowed');
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$controller = new AiAccessController($this->createStub(IRequest::class), $config, $this->entitlement(false), $this->createStub(LoggerInterface::class), $l10n, 'admin');

		$response = $controller->save(true);

		$this->assertSame(Http::STATUS_PAYMENT_REQUIRED, $response->getStatus());
		$this->assertTrue($response->getData()['werkplusLimit']);
		$this->assertFalse($controller->show()->getData()['werkplus']);
	}

	public function testWithoutPwMcpWritingToPublicTicketsCanStillBeSwitchedOff(): void {
		$config = $this->createMock(McpConfig::class);
		$config->expects($this->once())->method('setPublicWriteAllowed')->with(false);
		$controller = new AiAccessController($this->createStub(IRequest::class), $config, $this->entitlement(false), $this->createStub(LoggerInterface::class), $this->createStub(IL10N::class), 'admin');

		$this->assertSame(Http::STATUS_OK, $controller->save(false)->getStatus());
	}
}
