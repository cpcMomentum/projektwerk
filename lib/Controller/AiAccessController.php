<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Service\EntitlementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Admin-only, weil keine Methode #[NoAdminRequired] trägt. */
class AiAccessController extends Controller {

	public function __construct(
		IRequest $request,
		private McpConfig $config,
		private EntitlementService $entitlement,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function show(): JSONResponse {
		return new JSONResponse(['publicWrite' => $this->config->publicWriteAllowed(), 'werkplus' => $this->entitlement->mcpAllowed()]);
	}

	public function save(?bool $publicWrite = null): JSONResponse {
		if ($publicWrite === null) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		// Ausschalten muss auch ohne Lizenz gehen.
		if ($publicWrite && !$this->entitlement->mcpAllowed()) {
			return new JSONResponse(['error' => $this->l10n->t('Der KI-Zugriff über MCP ist Teil von WerkPlus.'), 'werkplusLimit' => true], Http::STATUS_PAYMENT_REQUIRED);
		}

		$this->config->setPublicWriteAllowed($publicWrite);
		$this->logger->warning('KI-Schreiben in kundensichtbare Vorgänge {state} durch {user}', [
			'app' => Application::APP_ID,
			'state' => $publicWrite ? 'erlaubt' : 'gesperrt',
			'user' => $this->userId,
		]);

		return $this->show();
	}
}
