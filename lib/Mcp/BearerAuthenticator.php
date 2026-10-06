<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Service\AccountType;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Prüft das Bearer-Token einer MCP-Anfrage und liefert, wer ruft.
 *
 * Die Gültigkeit entscheidet `oidc`. Hier kommt nur hinzu, was `oidc` nicht weiß:
 * ob das Token für **diesen** Endpunkt und einen **zugelassenen** Client ausgestellt wurde.
 */
class BearerAuthenticator {

	public const SCOPES = 'openid profile offline_access';

	/** Fehlversuche je Token, bevor 429 kommt. */
	private const MAX_FAILURES = 10;
	private const FAILURE_WINDOW = 600;

	private ICache $failures;

	public function __construct(
		private McpConfig $config,
		private TokenValidator $validator,
		private AccountType $accountType,
		private IURLGenerator $urls,
		private IUserManager $users,
		private IAppManager $appManager,
		private ITimeFactory $time,
		ICacheFactory $cacheFactory,
	) {
		$this->failures = $cacheFactory->createDistributed('projektwerk_mcp_authfail');
	}

	/**
	 * @throws McpAuthException
	 */
	public function authenticate(string $authorizationHeader): AuthenticatedCaller {
		if (!$this->config->isEnabled()) {
			throw new McpAuthException(Http::STATUS_NOT_FOUND);
		}

		if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorizationHeader), $match)) {
			throw new McpAuthException(Http::STATUS_UNAUTHORIZED, $this->challenge(null));
		}
		$token = $match[1];

		// Erst die billige Formprüfung: Was nie für uns ausgestellt sein kann, kostet weder
		// einen oidc-Aufruf noch einen Eintrag im Fehlerzähler.
		$claims = $this->accessTokenClaims($token);
		$clientId = $claims === null ? null : $this->boundClientId($claims);
		if ($clientId === null) {
			throw new McpAuthException(Http::STATUS_UNAUTHORIZED, $this->challenge('invalid_token'));
		}

		$failureKey = hash('sha256', $token);
		if ((int)$this->failures->get($failureKey) >= self::MAX_FAILURES) {
			throw new McpAuthException(Http::STATUS_TOO_MANY_REQUESTS);
		}

		$userId = $this->validator->userIdFor($token);
		if ($userId === null || ($claims['sub'] ?? null) !== $userId) {
			$this->failures->set($failureKey, (int)$this->failures->get($failureKey) + 1, self::FAILURE_WINDOW);
			throw new McpAuthException(Http::STATUS_UNAUTHORIZED, $this->challenge('invalid_token'));
		}

		if ($this->accountType->isGuest($userId)) {
			throw new McpAuthException(Http::STATUS_FORBIDDEN);
		}

		// Die Weboberfläche sperrt, wem die App per Gruppe nicht freigegeben ist. Für eine
		// #[PublicPage] prüft Nextcloud das nicht, also hier: dieselbe Grenze wie im Browser.
		$user = $this->users->get($userId);
		if ($user === null || !$user->isEnabled() || !$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			throw new McpAuthException(Http::STATUS_UNAUTHORIZED, $this->challenge('invalid_token'));
		}

		return new AuthenticatedCaller($userId, $clientId);
	}

	public function protectedResourceMetadataUrl(): string {
		return $this->urls->linkToRouteAbsolute('projektwerk.mcpMetadata.protectedResource');
	}

	/**
	 * Header und Nutzlast eines RFC-9068-Zugriffstokens, ohne Signaturprüfung.
	 *
	 * Die Signatur hat `oidc` gerade geprüft. `typ` muss `at+jwt` sein: Das Event erkennt
	 * auch ID-Tokens als gültig an, und die gehen auch an fremde Anwendungen.
	 *
	 * @return array<string, mixed>|null
	 */
	private function accessTokenClaims(string $token): ?array {
		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			return null;
		}
		$header = $this->decodeSegment($parts[0]);
		$payload = $this->decodeSegment($parts[1]);
		if ($header === null || $payload === null || ($header['typ'] ?? null) !== 'at+jwt') {
			return null;
		}

		return $payload;
	}

	/**
	 * Der Client, wenn das Token an diesen Endpunkt gebunden, zugelassen und nicht abgelaufen ist.
	 *
	 * @param array<string, mixed> $claims
	 */
	private function boundClientId(array $claims): ?string {
		$clientId = $claims['client_id'] ?? $claims['azp'] ?? null;
		if (!is_string($clientId) || !in_array($clientId, $this->config->allowedClients(), true)) {
			return null;
		}

		$audience = $claims['aud'] ?? null;
		$audience = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
		if (!in_array($this->config->resourceUrl(), $audience, true)) {
			return null;
		}

		if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= $this->time->getTime()) {
			return null;
		}

		$scopes = is_string($claims['scope'] ?? null) ? explode(' ', $claims['scope']) : [];
		if (!in_array('openid', $scopes, true)) {
			return null;
		}

		return $clientId;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function decodeSegment(string $segment): ?array {
		$json = base64_decode(strtr($segment, '-_', '+/'), true);
		if ($json === false) {
			return null;
		}
		try {
			$data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return is_array($data) ? $data : null;
	}

	private function challenge(?string $error): string {
		$parts = ['resource_metadata="' . $this->protectedResourceMetadataUrl() . '"', 'scope="' . self::SCOPES . '"'];
		if ($error !== null) {
			$parts[] = 'error="' . $error . '"';
		}

		return 'Bearer ' . implode(', ', $parts);
	}
}
