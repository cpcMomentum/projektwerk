<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

use OCA\Projektwerk\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Die drei Schalter des MCP-Endpunkts. Standard ist aus; der Endpunkt antwortet dann 404.
 *
 * Gesetzt per `occ config:app:set projektwerk <key> --value=…` (siehe docs/mcp-setup.md).
 */
class McpConfig {

	public const KEY_ENABLED = 'mcp_enabled';
	public const KEY_ALLOWED_CLIENTS = 'mcp_allowed_clients';
	public const KEY_RESOURCE_URL = 'mcp_resource_url';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * An ist der Endpunkt nur mit allen drei Werten; ein halb konfigurierter fällt zu.
	 */
	public function isEnabled(): bool {
		return $this->config->getValueString(Application::APP_ID, self::KEY_ENABLED, 'no') === 'yes'
			&& $this->resourceUrl() !== ''
			&& $this->allowedClients() !== [];
	}

	public function isSwitchedOn(): bool {
		return $this->config->getValueString(Application::APP_ID, self::KEY_ENABLED, 'no') === 'yes';
	}

	/**
	 * Die kanonische Endpunkt-URL, Byte für Byte so, wie Nutzer sie in Claude eintragen.
	 */
	public function resourceUrl(): string {
		return trim($this->config->getValueString(Application::APP_ID, self::KEY_RESOURCE_URL, ''));
	}

	/**
	 * Schutz gegen DNS-Rebinding: Ein mitgeschickter `Origin` muss der eigene sein, ohne ist erlaubt.
	 */
	public function originAllowed(string $origin): bool {
		if ($origin === '') {
			return true;
		}

		$parts = parse_url($this->resourceUrl());
		if (!isset($parts['scheme'], $parts['host'])) {
			return false;
		}
		$own = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

		return strtolower(rtrim($origin, '/')) === strtolower($own);
	}

	/**
	 * @return list<string> oidc-Client-IDs, deren Tokens hier gelten
	 */
	public function allowedClients(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY_ALLOWED_CLIENTS, '');

		return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $id): bool => $id !== ''));
	}
}
