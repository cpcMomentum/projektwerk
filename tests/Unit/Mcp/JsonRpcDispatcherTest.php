<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Mcp;

use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\JsonRpcDispatcher;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\McpResponse;
use OCA\Projektwerk\Mcp\SchemaValidator;
use OCA\Projektwerk\Mcp\ToolRegistry;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Mcp\Tools\GetTicket;
use OCA\Projektwerk\Mcp\Tools\ListBoards;
use OCA\Projektwerk\Mcp\Tools\ListColumns;
use OCA\Projektwerk\Mcp\Tools\ListTickets;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class JsonRpcDispatcherTest extends TestCase {

	private const MODERN = '2026-07-28';

	private ListBoards $listBoards;
	private McpRateLimiter $rateLimiter;
	private JsonRpcDispatcher $dispatcher;
	private AuthenticatedCaller $caller;

	protected function setUp(): void {
		$this->listBoards = $this->createMock(ListBoards::class);
		$this->listBoards->method('name')->willReturn('list_boards');
		$this->listBoards->method('title')->willReturn('List boards');
		$this->listBoards->method('description')->willReturn('Lists boards.');
		$this->listBoards->method('inputSchema')->willReturn([
			'type' => 'object',
			'properties' => ['include_archived' => ['type' => 'boolean', 'description' => 'x']],
			'additionalProperties' => false,
		]);
		$this->listBoards->method('annotations')->willReturn(['readOnlyHint' => true]);

		$others = [];
		foreach ([ListColumns::class => 'list_columns', ListTickets::class => 'list_tickets', GetTicket::class => 'get_ticket'] as $class => $name) {
			$tool = $this->createStub($class);
			$tool->method('name')->willReturn($name);
			$tool->method('title')->willReturn($name);
			$tool->method('description')->willReturn($name);
			$tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => [], 'additionalProperties' => false]);
			$tool->method('annotations')->willReturn(['readOnlyHint' => true]);
			$others[] = $tool;
		}

		$this->rateLimiter = $this->createStub(McpRateLimiter::class);
		$this->rateLimiter->method('allowRead')->willReturn(true);
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.4.18');

		$this->dispatcher = new JsonRpcDispatcher(
			new ToolRegistry($this->listBoards, ...$others),
			new SchemaValidator(),
			$this->rateLimiter,
			$appManager,
		);
		$this->caller = new AuthenticatedCaller('anna', 'claude');
	}

	public function testParseErrorIs400WithMinus32700(): void {
		$response = $this->dispatcher->dispatch('{not json', $this->legacyHeaders(), $this->caller);

		$this->assertSame(400, $response->status);
		$this->assertSame(-32700, $response->body['error']['code']);
		$this->assertNull($response->body['id']);
	}

	public function testBatchesAreRejected(): void {
		$response = $this->dispatcher->dispatch('[{"jsonrpc":"2.0","id":1,"method":"ping"}]', $this->legacyHeaders(), $this->caller);

		$this->assertSame(400, $response->status);
		$this->assertSame(-32600, $response->body['error']['code']);
	}

	public function testNullIdIsAnInvalidRequest(): void {
		$response = $this->dispatcher->dispatch('{"jsonrpc":"2.0","id":null,"method":"ping"}', $this->legacyHeaders(), $this->caller);

		$this->assertSame(400, $response->status);
		$this->assertSame(-32600, $response->body['error']['code']);
	}

	public function testNotificationsAreAcceptedWithoutBody(): void {
		$response = $this->dispatcher->dispatch('{"jsonrpc":"2.0","method":"notifications/initialized"}', $this->legacyHeaders(), $this->caller);

		$this->assertSame(202, $response->status);
		$this->assertNull($response->body);
	}

	public function testInitializeNegotiatesALegacyVersion(): void {
		$body = '{"jsonrpc":"2.0","id":7,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}';
		$known = $this->resultOf($this->dispatcher->dispatch($body, $this->headers('', '', ''), $this->caller));
		$this->assertSame('2025-06-18', $known['protocolVersion']);
		$this->assertSame(['tools' => ['listChanged' => false]], $known['capabilities']);

		$body = '{"jsonrpc":"2.0","id":7,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}';
		$unknown = $this->resultOf($this->dispatcher->dispatch($body, $this->headers('', '', ''), $this->caller));
		$this->assertSame('2025-11-25', $unknown['protocolVersion']);
	}

	public function testMissingOrUnknownVersionListsTheSupportedOnes(): void {
		foreach (['', '1999-01-01'] as $version) {
			$response = $this->dispatcher->dispatch($this->request('tools/list'), $this->headers($version, 'tools/list', ''), $this->caller);

			$this->assertSame(400, $response->status, $version);
			$this->assertSame(-32022, $response->body['error']['code']);
			$this->assertSame(['2026-07-28', '2025-11-25', '2025-06-18'], $response->body['error']['data']['supported']);
		}
	}

	public function testModernRequestsMustMirrorMethodVersionAndName(): void {
		$cases = [
			'version' => [$this->request('tools/list', [], '2025-11-25'), $this->headers(self::MODERN, 'tools/list', '')],
			'method' => [$this->request('tools/list', [], self::MODERN), $this->headers(self::MODERN, 'ping', '')],
			'name' => [$this->request('tools/call', ['name' => 'list_boards', 'arguments' => []], self::MODERN), $this->headers(self::MODERN, 'tools/call', 'get_ticket')],
		];
		foreach ($cases as $label => [$body, $headers]) {
			$response = $this->dispatcher->dispatch($body, $headers, $this->caller);

			$this->assertSame(400, $response->status, $label);
			$this->assertSame(-32020, $response->body['error']['code'], $label);
		}
	}

	public function testBase64EncodedNameHeaderIsDecodedBeforeComparing(): void {
		$this->listBoards->method('call')->willReturn(ToolResult::ok(['boards' => []]));
		$body = $this->request('tools/call', ['name' => 'list_boards', 'arguments' => []], self::MODERN);
		$headers = $this->headers(self::MODERN, 'tools/call', '=?base64?' . base64_encode('list_boards') . '?=');

		$this->assertSame(200, $this->dispatcher->dispatch($body, $headers, $this->caller)->status);
	}

	public function testModernResultsCarryResultTypeAndUnknownMethodsAre404(): void {
		$discover = $this->dispatcher->dispatch($this->request('server/discover', [], self::MODERN), $this->headers(self::MODERN, 'server/discover', ''), $this->caller);
		$this->assertSame('complete', $this->resultOf($discover)['resultType']);
		$this->assertContains(self::MODERN, $this->resultOf($discover)['supportedVersions']);

		$unknown = $this->dispatcher->dispatch($this->request('resources/list', [], self::MODERN), $this->headers(self::MODERN, 'resources/list', ''), $this->caller);
		$this->assertSame(404, $unknown->status);
		$this->assertSame(-32601, $unknown->body['error']['code']);
	}

	public function testLegacyUnknownMethodStays200SoClientsDoNotReinitialise(): void {
		$response = $this->dispatcher->dispatch($this->request('resources/list'), $this->legacyHeaders(), $this->caller);

		$this->assertSame(200, $response->status);
		$this->assertSame(-32601, $response->body['error']['code']);
	}

	public function testToolsAreListedInAStableOrderWithObjectProperties(): void {
		$response = $this->dispatcher->dispatch($this->request('tools/list'), $this->legacyHeaders(), $this->caller);
		$tools = $this->resultOf($response)['tools'];

		$this->assertSame(['get_ticket', 'list_boards', 'list_columns', 'list_tickets'], array_column($tools, 'name'));
		$json = json_encode($response->body);
		$this->assertStringContainsString('"properties":{}', $json, 'Leere properties müssen als Objekt hinaus.');
	}

	public function testUnknownToolIsAProtocolErrorButBadArgumentsAreAToolError(): void {
		$unknown = $this->dispatcher->dispatch($this->request('tools/call', ['name' => 'nope']), $this->legacyHeaders(), $this->caller);
		$this->assertSame(-32602, $unknown->body['error']['code']);

		$this->listBoards->expects($this->never())->method('call');
		$bad = $this->resultOf($this->dispatcher->dispatch(
			$this->request('tools/call', ['name' => 'list_boards', 'arguments' => ['include_archived' => 'yes', 'x' => 1]]),
			$this->legacyHeaders(),
			$this->caller,
		));
		$this->assertTrue($bad['isError']);
		$this->assertStringContainsString('include_archived must be of type boolean', $bad['content'][0]['text']);
		$this->assertStringContainsString('x is not a known argument', $bad['content'][0]['text']);
	}

	public function testToolResultIsPassedThrough(): void {
		$this->listBoards->expects($this->once())->method('call')
			->with($this->caller, ['include_archived' => true])
			->willReturn(ToolResult::ok(['boards' => [['id' => 1]]]));

		$result = $this->resultOf($this->dispatcher->dispatch(
			$this->request('tools/call', ['name' => 'list_boards', 'arguments' => ['include_archived' => true]]),
			$this->legacyHeaders(),
			$this->caller,
		));

		$this->assertFalse($result['isError']);
		$this->assertSame(['boards' => [['id' => 1]]], $result['structuredContent']);
		$this->assertSame('{"boards":[{"id":1}]}', $result['content'][0]['text']);
	}

	public function testExhaustedBudgetIs429(): void {
		$limiter = $this->createStub(McpRateLimiter::class);
		$limiter->method('allowRead')->willReturn(false);
		$appManager = $this->createStub(IAppManager::class);
		$dispatcher = new JsonRpcDispatcher(new ToolRegistry(
			$this->listBoards,
			$this->createStub(ListColumns::class),
			$this->createStub(ListTickets::class),
			$this->createStub(GetTicket::class),
		), new SchemaValidator(), $limiter, $appManager);

		$response = $dispatcher->dispatch($this->request('tools/call', ['name' => 'list_boards']), $this->legacyHeaders(), $this->caller);

		$this->assertSame(429, $response->status);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request(string $method, array $params = [], ?string $metaVersion = null): string {
		if ($metaVersion !== null) {
			$params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => $metaVersion];
		}

		return (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object)$params]);
	}

	/**
	 * @return array{protocolVersion: string, method: string, name: string}
	 */
	private function headers(string $version, string $method, string $name): array {
		return ['protocolVersion' => $version, 'method' => $method, 'name' => $name];
	}

	/**
	 * @return array{protocolVersion: string, method: string, name: string}
	 */
	private function legacyHeaders(): array {
		return $this->headers('2025-11-25', '', '');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function resultOf(McpResponse $response): array {
		$this->assertSame(200, $response->status, (string)json_encode($response->body));

		return json_decode((string)json_encode($response->body['result']), true);
	}
}
