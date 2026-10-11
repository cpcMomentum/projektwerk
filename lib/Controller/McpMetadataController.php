<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Mcp\BearerAuthenticator;
use OCA\Projektwerk\Mcp\McpConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * RFC-9728-Metadaten des MCP-Endpunkts: welcher Autorisierungsserver Tokens dafür ausstellt.
 */
class McpMetadataController extends Controller {

	public function __construct(
		IRequest $request,
		private McpConfig $config,
		private IURLGenerator $urls,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function protectedResource(): JSONResponse {
		if (!$this->config->isEnabled()) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([
			// Byte für Byte die URL, die Nutzer in Claude eintragen; sonst lehnt Claude ab.
			'resource' => $this->config->resourceUrl(),
			// Der Issuer von `oidc` ist die Basis-URL der Instanz.
			'authorization_servers' => [rtrim($this->urls->getAbsoluteURL('/'), '/')],
			'bearer_methods_supported' => ['header'],
			'scopes_supported' => explode(' ', BearerAuthenticator::SCOPES),
			'resource_name' => 'ProjektWerk',
		]);
	}
}
