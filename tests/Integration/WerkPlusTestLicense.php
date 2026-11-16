<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\License\EntitlementKeys;
use OCA\Projektwerk\License\EntitlementTokenVerifier;
use OCA\Projektwerk\License\InstanceId;
use OCA\Projektwerk\License\LicenseService;
use OCA\Projektwerk\License\SharedStore;
use OCP\IAppConfig;
use OCP\Server;

/**
 * Nur in der Integrationssuite: Die Prüfung kennt zusätzlich einen Testschlüssel, mit dem hier Tokens signiert werden.
 */
final class WerkPlusTestLicense {

	private const KID = 'integration-test';
	/** @var array<string, list<string>> */
	private const CONFIG_KEYS = [
		SharedStore::APP => ['store_version', 'key', 'token'],
		Application::APP_ID => ['werkplus_last_attempt_at', 'werkplus_last_error'],
	];
	private const SENSITIVE = ['key'];

	private static ?\OpenSSLAsymmetricKey $privateKey = null;

	/** Muss laufen, bevor der Container den Lizenzdienst zum ersten Mal baut. */
	public static function install(): void {
		if (self::$privateKey !== null) {
			return;
		}
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($key === false) {
			throw new \RuntimeException('openssl_pkey_new fehlgeschlagen');
		}
		self::$privateKey = $key;
		$keys = EntitlementKeys::PUBLIC_KEYS + [self::KID => openssl_pkey_get_details($key)['key']];
		(new Application())->getContainer()->registerService(
			EntitlementTokenVerifier::class,
			static fn (): EntitlementTokenVerifier => new EntitlementTokenVerifier($keys),
		);
	}

	/** @param list<string> $flags */
	public static function grant(array $flags = LicenseService::FLAGS): void {
		self::install();
		$now = time();
		$header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KID];
		$claims = [
			'iss' => EntitlementTokenVerifier::ISSUER,
			'aud' => EntitlementTokenVerifier::AUDIENCE,
			'sub' => Server::get(InstanceId::class)->uuid(),
			'iat' => $now,
			'nbf' => $now - 60,
			'exp' => $now + 86400,
			'graceUntil' => $now + 2 * 86400,
			'tier' => 'werkplus',
			'features' => array_values($flags),
		];
		$input = self::b64(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . self::b64(json_encode($claims, JSON_THROW_ON_ERROR));
		openssl_sign($input, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

		$config = Server::get(IAppConfig::class);
		$config->setValueString(SharedStore::APP, 'store_version', (string)SharedStore::VERSION);
		$config->setValueString(SharedStore::APP, 'token', $input . '.' . self::b64($signature));
	}

	/** @return array<string, array<string, ?string>> Die Lizenzwerte der Instanz, bevor ein Test sie überschreibt. */
	public static function snapshot(): array {
		$config = Server::get(IAppConfig::class);
		$values = [];
		foreach (self::CONFIG_KEYS as $app => $keys) {
			foreach ($keys as $key) {
				$values[$app][$key] = $config->hasKey($app, $key) ? $config->getValueString($app, $key) : null;
			}
		}
		return $values;
	}

	/** @param array<string, array<string, ?string>> $snapshot Eine echte Lizenz auf der Dev-Instanz bleibt so erhalten. */
	public static function restore(array $snapshot): void {
		$config = Server::get(IAppConfig::class);
		foreach ($snapshot as $app => $values) {
			foreach ($values as $key => $value) {
				if ($value === null) {
					$config->deleteKey($app, $key);
				} else {
					$config->setValueString($app, $key, $value, sensitive: $app === SharedStore::APP && in_array($key, self::SENSITIVE, true));
				}
			}
		}
	}

	private static function b64(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
