<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\License;

use OCA\Projektwerk\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/** Lizenzstand nach entitlement-v1; die Grenzen selbst setzt EntitlementService durch. */
class LicenseService {

	/** ProjektWerk-Flags aus der Registry `flags.md` des Backends. */
	public const FLAGS = [
		'pw_projects', 'pw_customer_projects', 'pw_multi_board', 'pw_mail_reply', 'pw_mcp',
	];

	public const STATE_NONE = 'none';
	public const STATE_INVALID = 'invalid';

	/** Eigener Code: Der Server antwortete, aber das Token bestand die lokale Pruefung nicht. */
	public const TOKEN_REJECTED = 'TOKEN_REJECTED';

	private const RENEW_AFTER = 7 * 86400;
	private const RENEW_BEFORE_EXPIRY = 7 * 86400;
	// Knapp unter dem Jobtakt von sechs Stunden, sonst fällt jeder zweite Lauf aus.
	private const RETRY_AFTER = 5 * 3600;
	/** Nach diesen Codes hilft sofortiges Wiederholen nicht; einmal taeglich, damit eine Verlaengerung von selbst greift. */
	private const SLOW_RETRY = ['REQUEST_INVALID', 'KEY_UNKNOWN', 'KEY_REVOKED', 'KEY_EXPIRED', 'INSTANCE_LIMIT_REACHED'];
	private const SLOW_RETRY_AFTER = 86400;
	/** Nach diesen Codes faellt die Instanz auf Free, das Token wird verworfen. */
	private const DROP_TOKEN = ['KEY_REVOKED', 'KEY_EXPIRED'];

	private const K_UUID = 'werkplus_instance_uuid';
	private const K_KEY = 'werkplus_key';
	private const K_TOKEN = 'werkplus_token';
	private const K_LAST_CHECK = 'werkplus_last_check_at';
	private const K_LAST_ATTEMPT = 'werkplus_last_attempt_at';
	private const K_LAST_ERROR = 'werkplus_last_error';

	/** @var array{jwt: string, token: ?EntitlementToken, state: string}|null */
	private ?array $current = null;

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly WerkPlusClient $client,
		private readonly EntitlementTokenVerifier $verifier,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function hasFeature(string $flag): bool {
		if (!in_array($flag, self::FLAGS, true)) {
			throw new \InvalidArgumentException('Unbekanntes Flag: ' . $flag);
		}
		$token = $this->activeToken();
		return $token !== null && in_array($flag, $token->features, true);
	}

	public function tier(): string {
		return $this->activeToken()?->tier ?? 'free';
	}

	public function state(): string {
		return $this->current()['state'];
	}

	public function getInstanceUuid(): string {
		$uuid = $this->get(self::K_UUID);
		if ($uuid === '') {
			$uuid = $this->newUuid();
			$this->appConfig->setValueString(Application::APP_ID, self::K_UUID, $uuid);
		}
		return $uuid;
	}

	/** Ein leerer Schluessel nimmt den gespeicherten. */
	public function activate(#[\SensitiveParameter] string $key): void {
		$key = self::normalizeKey($key);
		if ($key === '') {
			$key = $this->get(self::K_KEY);
		}
		if ($key === '' || strlen($key) > 128 || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
			throw new \InvalidArgumentException('key');
		}
		$this->fetchToken($key);
		$this->appConfig->setValueString(Application::APP_ID, self::K_KEY, $key, sensitive: true);
	}

	/** @throws WerkPlusApiException */
	public function startTrial(): string {
		if ($this->get(self::K_KEY) !== '') {
			throw new \InvalidArgumentException('keySet');
		}
		$now = $this->now();
		$this->appConfig->setValueString(Application::APP_ID, self::K_LAST_ATTEMPT, (string)$now);
		$response = $this->client->trial(
			strtolower($this->getInstanceUuid()),
			[Application::APP_ID => $this->appManager->getAppVersion(Application::APP_ID)],
		);

		// Vor der Pruefung sichern: Der Server schickt den Schluessel nur dieses eine Mal.
		$key = self::normalizeKey($response['key']);
		$this->appConfig->setValueString(Application::APP_ID, self::K_KEY, $key, sensitive: true);
		try {
			$this->verifier->verify($response['token'], $this->getInstanceUuid(), $now);
		} catch (InvalidTokenException $e) {
			// Kein Abbruch, sonst sieht der Betreiber den Schluessel nie; die Erneuerung holt ein gueltiges Token.
			$this->logger->warning('ProjektWerk: WerkPlus-Token abgelehnt', ['exception' => $e]);
			$this->recordError(new WerkPlusApiException(self::TOKEN_REJECTED, '', $e), $now, true);
			// Die naechste Erneuerung soll nicht sechs Stunden warten.
			$this->appConfig->deleteKey(Application::APP_ID, self::K_LAST_ATTEMPT);
			return $key;
		}
		$this->storeToken($response['token'], $now);
		return $key;
	}

	/** @throws \InvalidArgumentException wenn kein Schluessel hinterlegt ist */
	public function refresh(): void {
		$key = $this->get(self::K_KEY);
		if ($key === '') {
			throw new \InvalidArgumentException('key');
		}
		$this->fetchToken($key);
	}

	/** Fuer den Hintergrundjob: erneuert nur, wenn faellig, und wirft nie. */
	public function refreshIfDue(): void {
		if (!$this->isRefreshDue()) {
			return;
		}
		try {
			$this->refresh();
		} catch (\Throwable $e) {
			$this->logger->info('ProjektWerk: WerkPlus-Lizenz nicht erneuert', ['exception' => $e]);
		}
	}

	public function isRefreshDue(): bool {
		if ($this->get(self::K_KEY) === '') {
			return false;
		}
		$now = $this->now();
		if ($now - (int)$this->get(self::K_LAST_ATTEMPT) < self::RETRY_AFTER) {
			return false;
		}
		$lastError = $this->lastError();
		if ($lastError !== null
			&& in_array($lastError['code'], self::SLOW_RETRY, true)
			&& $now - $lastError['at'] < self::SLOW_RETRY_AFTER) {
			return false;
		}
		$token = $this->current()['token'];
		if ($token === null) {
			return true;
		}
		return $now - $token->issuedAt >= self::RENEW_AFTER
			|| $token->expiresAt - $now < self::RENEW_BEFORE_EXPIRY;
	}

	public function removeKey(): void {
		foreach ([self::K_KEY, self::K_TOKEN, self::K_LAST_CHECK, self::K_LAST_ATTEMPT, self::K_LAST_ERROR] as $configKey) {
			$this->appConfig->deleteKey(Application::APP_ID, $configKey);
		}
		$this->current = null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getStatus(): array {
		$current = $this->current();
		$token = $current['token'];
		$active = $this->activeToken();
		$key = $this->get(self::K_KEY);
		$lastCheck = (int)$this->get(self::K_LAST_CHECK);
		return [
			'instanceUuid' => $this->getInstanceUuid(),
			'keySet' => $key !== '',
			'keyHint' => $key !== '' ? '…' . substr($key, -4) : null,
			'state' => $current['state'],
			'tier' => $this->tier(),
			'features' => $active?->features ?? [],
			'licensedEmployees' => $active?->licensedEmployees,
			'expiresAt' => $token !== null ? $this->iso($token->expiresAt) : null,
			'graceUntil' => $token !== null ? $this->iso($token->graceUntil) : null,
			'lastCheckAt' => $lastCheck > 0 ? $this->iso($lastCheck) : null,
			// Ohne hinterlegten Schluessel waere ein alter Fehlversuch nur noch Rauschen.
			'lastError' => $key !== '' ? $this->lastError() : null,
		];
	}

	/**
	 * @return array{code: string, serverMessage: string, at: int}|null
	 */
	public function lastError(): ?array {
		$data = json_decode($this->get(self::K_LAST_ERROR), true);
		if (!is_array($data) || !is_string($data['code'] ?? null)) {
			return null;
		}
		return [
			'code' => $data['code'],
			'serverMessage' => is_string($data['serverMessage'] ?? null) ? $data['serverMessage'] : '',
			'at' => is_int($data['at'] ?? null) ? $data['at'] : 0,
		];
	}

	/**
	 * @throws WerkPlusApiException
	 */
	private function fetchToken(#[\SensitiveParameter] string $key): void {
		$now = $this->now();
		// Ein Versuch mit einem neuen Schlüssel darf die Erneuerung des gespeicherten nicht aufschieben.
		if ($key === $this->get(self::K_KEY)) {
			$this->appConfig->setValueString(Application::APP_ID, self::K_LAST_ATTEMPT, (string)$now);
		}
		try {
			$response = $this->client->validate(
				$key,
				strtolower($this->getInstanceUuid()),
				[Application::APP_ID => $this->appManager->getAppVersion(Application::APP_ID)],
			);
			try {
				$this->verifier->verify($response['token'], $this->getInstanceUuid(), $now);
			} catch (InvalidTokenException $e) {
				$this->logger->warning('ProjektWerk: WerkPlus-Token abgelehnt', ['exception' => $e]);
				throw new WerkPlusApiException(self::TOKEN_REJECTED, '', $e);
			}
		} catch (WerkPlusApiException $e) {
			$this->recordError($e, $now, $key === $this->get(self::K_KEY));
			throw $e;
		}
		$this->storeToken($response['token'], $now);
	}

	private function storeToken(string $jwt, int $now): void {
		$this->appConfig->setValueString(Application::APP_ID, self::K_TOKEN, $jwt, sensitive: true);
		$this->appConfig->setValueString(Application::APP_ID, self::K_LAST_CHECK, (string)$now);
		$this->appConfig->deleteKey(Application::APP_ID, self::K_LAST_ERROR);
		$this->current = null;
	}

	private function recordError(WerkPlusApiException $e, int $now, bool $isStoredKey): void {
		// Fehler eines NEUEN Schluessels nur an den Aufrufer, sonst bremst er die Erneuerung des gespeicherten.
		if (!$isStoredKey) {
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, self::K_LAST_ERROR, json_encode([
			'code' => $e->getErrorCode(),
			'serverMessage' => $e->getServerMessage(),
			'at' => $now,
		], JSON_THROW_ON_ERROR));
		if (in_array($e->getErrorCode(), self::DROP_TOKEN, true)) {
			$this->appConfig->deleteKey(Application::APP_ID, self::K_TOKEN);
		}
		$this->current = null;
	}

	private function activeToken(): ?EntitlementToken {
		$current = $this->current();
		return in_array($current['state'], [EntitlementToken::STATE_VALID, EntitlementToken::STATE_GRACE], true)
			? $current['token']
			: null;
	}

	/**
	 * @return array{jwt: string, token: ?EntitlementToken, state: string}
	 */
	private function current(): array {
		$jwt = $this->get(self::K_TOKEN);
		// Langlaufende Prozesse wie Hintergrundjobs sehen ein neu gespeichertes Token sofort.
		if ($this->current !== null && $this->current['jwt'] === $jwt) {
			$token = $this->current['token'];
			// Ablauf und Nachfrist hängen an der Uhr, nicht am Token.
			return $token === null ? $this->current : ['state' => $token->stateAt($this->now())] + $this->current;
		}
		if ($jwt === '') {
			return $this->current = ['jwt' => $jwt, 'token' => null, 'state' => self::STATE_NONE];
		}
		$now = $this->now();
		try {
			$token = $this->verifier->verify($jwt, $this->get(self::K_UUID), $now);
		} catch (InvalidTokenException $e) {
			$this->logger->debug('ProjektWerk: gespeichertes WerkPlus-Token ungueltig', ['exception' => $e]);
			return $this->current = ['jwt' => $jwt, 'token' => null, 'state' => self::STATE_INVALID];
		}
		$this->current = ['jwt' => $jwt, 'token' => $token, 'state' => $token->stateAt($now)];
		return $this->current;
	}

	// Wie der Server: Leerraum und Bindestriche weg, Koerper und Pruefsumme gross, Praefix klein.
	private static function normalizeKey(#[\SensitiveParameter] string $key): string {
		$key = (string)preg_replace('/[\s-]+/u', '', $key);
		if (strncasecmp($key, 'wp_', 3) === 0) {
			return 'wp_' . strtoupper(substr($key, 3));
		}
		return strtoupper($key);
	}

	private function get(string $configKey): string {
		return $this->appConfig->getValueString(Application::APP_ID, $configKey);
	}

	private function now(): int {
		return $this->timeFactory->getTime();
	}

	private function iso(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}

	private function newUuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}
}
