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
use OCA\Projektwerk\License\LicenseService;
use OCP\IAppConfig;
use OCP\Server;

/**
 * Nur in der Integrationssuite: Die Prüfung kennt zusätzlich einen Testschlüssel, mit dem hier Tokens signiert werden.
 */
final class WerkPlusTestLicense {

	private const KID = 'integration-test';
	private const UUID = '0b6f4c1e-7d2a-4f3b-9e8c-5a1d2b3c4d5e';
	private const CONFIG_KEYS = ['werkplus_instance_uuid', 'werkplus_token', 'werkplus_key', 'werkplus_last_check_at', 'werkplus_last_attempt_at', 'werkplus_last_error'];

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
			'sub' => self::UUID,
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
		$config->setValueString(Application::APP_ID, 'werkplus_instance_uuid', self::UUID);
		$config->setValueString(Application::APP_ID, 'werkplus_token', $input . '.' . self::b64($signature), sensitive: true);
	}

	/** @return array<string, ?string> Die Lizenzwerte der Instanz, bevor ein Test sie überschreibt. */
	public static function snapshot(): array {
		$config = Server::get(IAppConfig::class);
		$values = [];
		foreach (self::CONFIG_KEYS as $key) {
			$values[$key] = $config->hasKey(Application::APP_ID, $key) ? $config->getValueString(Application::APP_ID, $key) : null;
		}
		return $values;
	}

	/** @param array<string, ?string> $snapshot Eine echte Lizenz auf der Dev-Instanz bleibt so erhalten. */
	public static function restore(array $snapshot): void {
		$config = Server::get(IAppConfig::class);
		foreach ($snapshot as $key => $value) {
			if ($value === null) {
				$config->deleteKey(Application::APP_ID, $key);
			} else {
				$config->setValueString(Application::APP_ID, $key, $value, sensitive: in_array($key, ['werkplus_key', 'werkplus_token'], true));
			}
		}
	}

	private static function b64(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
