<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Mcp\BearerAuthenticator;
use OCA\Projektwerk\Mcp\JsonRpcDispatcher;
use OCA\Projektwerk\Mcp\McpAuthException;
use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\McpResponse;
use OCA\Projektwerk\Mcp\RequestBody;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Der MCP-Endpunkt (Streamable HTTP, nur POST, nur JSON).
 *
 * `#[PublicPage]` und `#[NoCSRFRequired]` sind hier die dokumentierte Ausnahme der Hausregel:
 * Die Anmeldung ist ein Bearer-Token ohne Cookie-Sitzung, und erst die App kann mit der
 * `resource_metadata`-Challenge antworten. Siehe docs/nextcloud-fallstricke.md.
 */
class McpController extends Controller {

	public function __construct(
		IRequest $request,
		private McpConfig $config,
		private BearerAuthenticator $authenticator,
		private JsonRpcDispatcher $dispatcher,
		private RequestBody $body,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function handle(): Response {
		if (!$this->config->isEnabled()) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		if (!$this->config->originAllowed($this->request->getHeader('Origin'))) {
			return new JSONResponse(
				McpResponse::error(403, null, JsonRpcDispatcher::INVALID_REQUEST, 'Origin not allowed')->body,
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			$caller = $this->authenticator->authenticate($this->request->getHeader('Authorization'));
		} catch (McpAuthException $e) {
			$response = new JSONResponse([], $e->status);
			if ($e->challenge !== null) {
				$response->addHeader('WWW-Authenticate', $e->challenge);
			}

			return $response;
		}

		$body = $this->body->read();
		if ($body === null) {
			return new JSONResponse(
				McpResponse::error(413, null, JsonRpcDispatcher::INVALID_REQUEST, 'Request body too large')->body,
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			);
		}

		try {
			$result = $this->dispatcher->dispatch($body, [
				'protocolVersion' => $this->request->getHeader('MCP-Protocol-Version'),
				'method' => $this->request->getHeader('Mcp-Method'),
				'name' => $this->request->getHeader('Mcp-Name'),
			], $caller);
		} catch (\Throwable $e) {
			$this->logger->error('MCP request failed', ['exception' => $e, 'app' => Application::APP_ID]);
			$result = McpResponse::error(500, null, JsonRpcDispatcher::INTERNAL_ERROR, 'Internal error');
		}

		if ($result->body === null) {
			$response = new Response();
			$response->setStatus($result->status);

			return $response;
		}

		return new JSONResponse($result->body, $result->status);
	}

	/**
	 * GET und DELETE gehören zu älteren Revisionen (SSE-Strom, Sitzungsende) und gibt es hier nicht.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function methodNotAllowed(): JSONResponse {
		if (!$this->config->isEnabled()) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$response = new JSONResponse([], Http::STATUS_METHOD_NOT_ALLOWED);
		$response->addHeader('Allow', 'POST');

		return $response;
	}
}
