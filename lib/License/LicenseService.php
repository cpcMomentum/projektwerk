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
	/** Nach diesen Codes faellt diese App auf Free, bis ein neueres Token kommt. */
	private const DROP_TOKEN = ['KEY_REVOKED', 'KEY_EXPIRED'];

	/** Nextcloud-App-IDs der Werk-Apps; ihre Versionen gehen bei jeder Prüfung mit. */
	private const WERK_APPS = ['rechnungswerk', 'projektwerk', 'worktime', 'contractmanager'];

	private const K_LAST_ATTEMPT = 'werkplus_last_attempt_at';
	private const K_LAST_ERROR = 'werkplus_last_error';
	/** Ein Token ohne `trial` wird einmal sofort erneuert, danach gilt wieder die 7-Tage-Regel. */
	private const K_CLAIMS_REFRESHED = 'werkplus_claims_refreshed';
	/** Ablage bis 0.4.26, vor der gemeinsamen Lizenz aller Werk-Apps. */
	private const LEGACY_KEY = 'werkplus_key';
	private const LEGACY = [self::LEGACY_KEY, 'werkplus_token', 'werkplus_instance_uuid', 'werkplus_last_check_at'];

	/** @var array{jwt: string, token: ?EntitlementToken}|null */
	private ?array $current = null;
	private ?string $instanceUuid = null;
	private bool $migrated = false;

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly SharedStore $store,
		private readonly InstanceId $instanceId,
		private readonly ITimeFactory $timeFactory,
		private readonly WerkPlusClient $client,
		private readonly EntitlementTokenVerifier $verifier,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly TrialNotices $trialNotices,
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

	/** Ende des Testzeitraums, solange das gespeicherte Token ein Testtoken ist, auch nach Ablauf. */
	public function trialEndsAt(): ?int {
		$token = $this->verifyQuietly($this->store()->token());
		return $token?->trial === true ? $token->licenseValidUntil : null;
	}

	public function state(): string {
		return $this->current()['state'];
	}

	public function getInstanceUuid(): string {
		return $this->instanceUuid ??= $this->instanceId->uuid();
	}

	/** Ein leerer Schluessel nimmt den gespeicherten. */
	public function activate(#[\SensitiveParameter] string $key): void {
		$key = self::normalizeKey($key);
		if ($key === '') {
			$key = $this->store()->key();
		}
		if ($key === '' || strlen($key) > 128 || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
			throw new \InvalidArgumentException('key');
		}
		$this->assertWritable();
		[$jwt, $token] = $this->fetchToken($key);
		if ($key === $this->store()->key()) {
			$this->keepNewer($key, $jwt, $token);
		} else {
			$this->store->setKey($key, $jwt);
		}
		$this->succeeded($token);
	}

	/** @throws WerkPlusApiException */
	public function startTrial(): string {
		if ($this->store()->key() !== '') {
			throw new \InvalidArgumentException('keySet');
		}
		$this->assertWritable();
		$now = $this->now();
		$this->appConfig->setValueString(Application::APP_ID, self::K_LAST_ATTEMPT, (string)$now);
		$response = $this->client->trial($this->getInstanceUuid(), $this->appVersions());

		// Vor der Pruefung sichern: Der Server schickt den Schluessel nur dieses eine Mal.
		$key = self::normalizeKey($response['key']);
		$this->store->setKey($key, '');
		try {
			$token = $this->verifier->verify($response['token'], $this->getInstanceUuid(), $now);
		} catch (InvalidTokenException $e) {
			// Kein Abbruch, sonst sieht der Betreiber den Schluessel nie; die Erneuerung holt ein gueltiges Token.
			$this->logger->warning('ProjektWerk: WerkPlus-Token abgelehnt', ['reason' => $e->getMessage()]);
			$this->recordError(new WerkPlusApiException(self::TOKEN_REJECTED), $now, true);
			// Die naechste Erneuerung soll nicht sechs Stunden warten.
			$this->appConfig->deleteKey(Application::APP_ID, self::K_LAST_ATTEMPT);
			return $key;
		}
		$this->store->setToken($response['token']);
		$this->succeeded($token);
		return $key;
	}

	/** @throws \InvalidArgumentException wenn kein Schluessel hinterlegt ist */
	public function refresh(): void {
		$key = $this->store()->key();
		if ($key === '') {
			throw new \InvalidArgumentException('key');
		}
		$this->assertWritable();
		[$jwt, $token] = $this->fetchToken($key);
		$this->keepNewer($key, $jwt, $token);
		$this->succeeded($token);
	}

	/** Fuer den Hintergrundjob: erneuert nur, wenn faellig, und wirft nie. */
	public function refreshIfDue(): void {
		// Hat eine andere Werk-App schon erneuert, entfällt der Abruf.
		$this->store->reload();
		$this->sendTrialNotices();
		if (!$this->isRefreshDue()) {
			return;
		}
		try {
			$this->refresh();
		} catch (\Throwable $e) {
			$this->logger->info('ProjektWerk: WerkPlus-Lizenz nicht erneuert', ['reason' => get_class($e) . ': ' . $e->getMessage()]);
		}
	}

	public function isRefreshDue(): bool {
		if ($this->store()->key() === '' || $this->store->isOutdated()) {
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
		if ($token->trial === null && $this->get(self::K_CLAIMS_REFRESHED) === '') {
			return true;
		}
		return $now - $token->issuedAt >= self::RENEW_AFTER
			|| $token->expiresAt - $now < self::RENEW_BEFORE_EXPIRY;
	}

	/** Gilt für alle Werk-Apps dieser Nextcloud. */
	public function removeKey(): void {
		$this->assertWritable();
		$this->store()->remove();
		foreach ([self::K_LAST_ATTEMPT, self::K_LAST_ERROR, self::K_CLAIMS_REFRESHED] as $configKey) {
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
		$key = $this->store()->key();
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
			'trial' => $token?->trial === true,
			'licenseValidUntil' => $token?->licenseValidUntil !== null ? $this->iso($token->licenseValidUntil) : null,
			'trialEnded' => $key !== '' && $token === null && $this->trialEnded(),
			// Das Token stammt aus der letzten erfolgreichen Prüfung, egal welche Werk-App sie ausgelöst hat.
			'lastCheckAt' => $token !== null ? $this->iso($token->issuedAt) : null,
			'lastError' => $key !== '' ? $this->currentError($token) : null,
			'storeOutdated' => $this->store->isOutdated(),
		];
	}

	private function trialEnded(): bool {
		$error = $this->lastError();
		return $error !== null && $error['code'] === 'KEY_EXPIRED' && $error['trial'];
	}

	/** @return array{code: string, serverMessage: string, at: int, trial: bool}|null */
	private function currentError(?EntitlementToken $token): ?array {
		$error = $this->lastError();
		// Hat inzwischen eine Werk-App erfolgreich geprüft, ist der eigene Fehler überholt.
		if ($error === null || ($token !== null && $token->issuedAt > $error['at'])) {
			return null;
		}
		return $error;
	}

	/**
	 * @return array{code: string, serverMessage: string, at: int, trial: bool}|null
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
			'trial' => ($data['trial'] ?? null) === true,
		];
	}

	/**
	 * @return array{string, EntitlementToken}
	 * @throws WerkPlusApiException
	 */
	private function fetchToken(#[\SensitiveParameter] string $key): array {
		$now = $this->now();
		$isStoredKey = $key === $this->store()->key();
		// Ein Versuch mit einem neuen Schlüssel darf die Erneuerung des gespeicherten nicht aufschieben.
		if ($isStoredKey) {
			$this->appConfig->setValueString(Application::APP_ID, self::K_LAST_ATTEMPT, (string)$now);
		}
		try {
			$response = $this->client->validate($key, $this->getInstanceUuid(), $this->appVersions());
			try {
				$token = $this->verifier->verify($response['token'], $this->getInstanceUuid(), $now);
			} catch (InvalidTokenException $e) {
				$this->logger->warning('ProjektWerk: WerkPlus-Token abgelehnt', ['reason' => $e->getMessage()]);
				throw new WerkPlusApiException(self::TOKEN_REJECTED);
			}
		} catch (WerkPlusApiException $e) {
			$this->recordError($e, $now, $isStoredKey);
			if ($isStoredKey && in_array($e->getErrorCode(), self::DROP_TOKEN, true)) {
				$this->dropToken($key, $e->getErrorCode(), $now);
			}
			throw $e;
		}
		return [$response['token'], $token];
	}

	/** Erneuern mehrere Werk-Apps gleichzeitig, darf nie ein älteres Token gewinnen. */
	private function keepNewer(#[\SensitiveParameter] string $key, #[\SensitiveParameter] string $jwt, EntitlementToken $token): void {
		$this->store->reload();
		// Hat eine andere Werk-App inzwischen einen neuen Schlüssel eingetragen, gehört das Token nicht mehr dazu.
		if ($this->store->key() !== $key) {
			return;
		}
		$stored = $this->verifyQuietly($this->store->token());
		if ($stored === null || $token->issuedAt > $stored->issuedAt) {
			$this->store->setToken($jwt);
		}
	}

	private function succeeded(?EntitlementToken $token): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::K_LAST_ERROR);
		// Liefert auch der Server noch kein `trial`, wartet die nächste Erneuerung wieder auf die 7-Tage-Regel.
		if ($token !== null && $token->trial === null) {
			$this->appConfig->setValueString(Application::APP_ID, self::K_CLAIMS_REFRESHED, '1');
		}
		$this->current = null;
	}

	/** Vertrag Regel 2: nur das Token, nur für den geprüften Schlüssel; die Ende-Erinnerung geht vorher raus. */
	private function dropToken(#[\SensitiveParameter] string $key, string $code, int $now): void {
		$this->store->reload();
		if ($this->store->isOutdated() || $this->store->key() !== $key) {
			return;
		}
		$token = $this->verifyQuietly($this->store->token());
		if ($token !== null && $code === 'KEY_EXPIRED') {
			// Danach weiß keine Werk-App mehr, dass es ein Test war.
			$this->notifyQuietly($token, max($now, $token->licenseValidUntil ?? $now));
		}
		$this->store->dropToken();
		$this->current = null;
	}

	private function sendTrialNotices(): void {
		if ($this->store->isOutdated()) {
			return;
		}
		$token = $this->verifyQuietly($this->store()->token());
		if ($token !== null) {
			$this->notifyQuietly($token, $this->now());
		}
	}

	private function notifyQuietly(EntitlementToken $token, int $now): void {
		try {
			$this->trialNotices->sendDue($token, $now);
		} catch (\Throwable $e) {
			$this->logger->info('ProjektWerk: WerkPlus-Erinnerung fehlgeschlagen', ['reason' => get_class($e) . ': ' . $e->getMessage()]);
		}
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
			'trial' => $e->wasTrial(),
			// Die Sperre gilt bis zum nächsten Token; verglichen wird Server- mit Serverzeit.
			'tokenIat' => $this->verifyQuietly($this->store->token())?->issuedAt ?? 0,
		], JSON_THROW_ON_ERROR));
		$this->current = null;
	}

	private function assertWritable(): void {
		if ($this->store()->isOutdated()) {
			throw new \InvalidArgumentException('storeOutdated');
		}
	}

	private function activeToken(): ?EntitlementToken {
		$current = $this->current();
		return in_array($current['state'], [EntitlementToken::STATE_VALID, EntitlementToken::STATE_GRACE], true)
			? $current['token']
			: null;
	}

	/**
	 * @return array{token: ?EntitlementToken, state: string}
	 */
	private function current(): array {
		$jwt = $this->store()->token();
		// Nur das geprüfte Token wird gehalten; den Zustand bestimmt bei jeder Abfrage die Uhr.
		if ($this->current === null || $this->current['jwt'] !== $jwt) {
			$this->current = ['jwt' => $jwt, 'token' => $this->verifyQuietly($jwt)];
		}
		$token = $this->current['token'];
		if ($token === null) {
			return ['token' => null, 'state' => $jwt === '' ? self::STATE_NONE : self::STATE_INVALID];
		}
		if ($this->revokedSince($token)) {
			return ['token' => null, 'state' => self::STATE_NONE];
		}
		return ['token' => $token, 'state' => $token->stateAt($this->now())];
	}

	// Greift, wenn dropToken nicht löschen durfte (Schlüssel gewechselt, neuere Ablage); dann zählt erst ein neueres Token.
	private function revokedSince(EntitlementToken $token): bool {
		$data = json_decode($this->get(self::K_LAST_ERROR), true);
		return is_array($data)
			&& in_array($data['code'] ?? null, self::DROP_TOKEN, true)
			&& $token->issuedAt <= (is_int($data['tokenIat'] ?? null) ? $data['tokenIat'] : PHP_INT_MAX);
	}

	private function verifyQuietly(#[\SensitiveParameter] string $jwt): ?EntitlementToken {
		if ($jwt === '') {
			return null;
		}
		try {
			return $this->verifier->verify($jwt, $this->getInstanceUuid(), $this->now());
		} catch (InvalidTokenException $e) {
			$this->logger->debug('ProjektWerk: gespeichertes WerkPlus-Token ungueltig', ['reason' => $e->getMessage()]);
			return null;
		}
	}

	private function store(): SharedStore {
		if (!$this->migrated) {
			$this->migrated = true;
			$this->migrateLegacy();
		}
		return $this->store;
	}

	/** Ein Schlüssel aus der eigenen Ablage wird einmalig übernommen, wenn die gemeinsame leer ist. */
	private function migrateLegacy(): void {
		$legacyKey = $this->get(self::LEGACY_KEY);
		if ($legacyKey === '' || $this->store->isOutdated()) {
			return;
		}
		if ($this->store->key() === '' && $this->store->token() === '') {
			// Das alte Token gehört zur alten Kennung und wird neu geholt.
			$this->store->setKey($legacyKey, '');
			$this->appConfig->deleteKey(Application::APP_ID, self::K_LAST_ATTEMPT);
			$this->appConfig->deleteKey(Application::APP_ID, self::K_LAST_ERROR);
		}
		foreach (self::LEGACY as $configKey) {
			$this->appConfig->deleteKey(Application::APP_ID, $configKey);
		}
	}

	/** @return array<string, string> */
	private function appVersions(): array {
		$versions = [];
		foreach (self::WERK_APPS as $appId) {
			if ($this->appManager->isEnabledForAnyone($appId)) {
				$versions[$appId] = $this->appManager->getAppVersion($appId);
			}
		}
		return $versions;
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
}
