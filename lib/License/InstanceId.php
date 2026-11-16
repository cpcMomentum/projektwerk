<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\License;

use OCP\IConfig;

/** Instanz-UUID nach entitlement-v1: in allen Werk-Apps einer Nextcloud gleich, nirgends gespeichert. */
class InstanceId {

	private const NAMESPACE = '8c743ff9fc504a3b9ab57b376b8ea840';

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function uuid(): string {
		return self::derive($this->config->getSystemValueString('instanceid'));
	}

	/** UUIDv5 nach RFC 9562, kleingeschrieben. */
	public static function derive(string $instanceId): string {
		$hash = substr(sha1(hex2bin(self::NAMESPACE) . $instanceId, true), 0, 16);
		$hash[6] = chr((ord($hash[6]) & 0x0f) | 0x50);
		$hash[8] = chr((ord($hash[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($hash), 4));
	}
}
