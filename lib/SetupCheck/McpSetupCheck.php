<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\SetupCheck;

use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\TokenValidator;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Meldet, woran der eingeschaltete MCP-Endpunkt still scheitert.
 */
class McpSetupCheck implements ISetupCheck {

	/** Unter einem Tag Verlängerungsdauer muss man sich nach jeder Pause neu anmelden. */
	private const MIN_REFRESH_SECONDS = 86400;

	public function __construct(
		private McpConfig $mcp,
		private TokenValidator $validator,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('ProjektWerk: MCP-Endpunkt für Claude');
	}

	public function run(): SetupResult {
		if (!$this->mcp->isSwitchedOn()) {
			return SetupResult::success($this->l10n->t('Der MCP-Endpunkt ist ausgeschaltet.'));
		}

		$problems = [];
		if (!$this->validator->isAvailable()) {
			$problems[] = $this->l10n->t('Die App „OpenID Connect Provider“ (oidc) ist nicht aktiv. Ohne sie kann sich niemand am MCP-Endpunkt anmelden.');
		}
		if ($this->mcp->allowedClients() === []) {
			$problems[] = $this->l10n->t('Es ist kein zugelassener oidc-Client eingetragen (mcp_allowed_clients). Der Endpunkt bleibt geschlossen.');
		}
		if ($this->mcp->resourceUrl() === '') {
			$problems[] = $this->l10n->t('Die kanonische Endpunkt-URL fehlt (mcp_resource_url). Der Endpunkt bleibt geschlossen.');
		}
		if ($this->config->getSystemValueString('memcache.distributed', '') === '') {
			$problems[] = $this->l10n->t('Es ist kein verteilter Cache eingerichtet (memcache.distributed). Die Begrenzung fehlgeschlagener Anmeldungen am MCP-Endpunkt wirkt dann nicht.');
		}
		try {
			$refresh = $this->appConfig->getValueString('oidc', 'refresh_expire_time', '900');
		} catch (\Throwable) {
			// Fremder Schlüssel mit anderem Typ: lieber still überspringen als die Übersicht brechen.
			$refresh = 'never';
		}
		if ($refresh !== 'never' && (int)$refresh < self::MIN_REFRESH_SECONDS) {
			$problems[] = $this->l10n->t('Die Verlängerung von oidc-Tokens läuft nach %s Sekunden ab (refresh_expire_time). Wer Claude länger nicht nutzt, muss sich danach neu anmelden. Empfohlen sind mindestens 30 Tage.', [$refresh]);
		}

		if ($problems === []) {
			return SetupResult::success($this->l10n->t('Der MCP-Endpunkt ist eingeschaltet und vollständig eingerichtet.'));
		}

		return SetupResult::warning(implode("\n\n", $problems));
	}
}
