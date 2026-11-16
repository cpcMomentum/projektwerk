<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\License;

use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;

/** Schlüssel und Token einmal je Nextcloud, für alle Werk-Apps (entitlement-v1, „Gemeinsame Ablage“). */
class SharedStore {

	/** Gehört keiner App, damit das Entfernen einer Werk-App die Lizenz nicht mitnimmt. */
	public const APP = 'werkwolke';
	public const VERSION = 1;

	private const K_VERSION = 'store_version';
	private const K_KEY = 'key';
	private const K_TOKEN = 'token';

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	/** Eine neuere Werk-App hat die Ablage umgebaut; dann wird sie nicht mehr beschrieben. */
	public function isOutdated(): bool {
		return (int)$this->get(self::K_VERSION) > self::VERSION;
	}

	public function key(): string {
		return $this->get(self::K_KEY);
	}

	public function token(): string {
		return $this->get(self::K_TOKEN);
	}

	/** Andere Werk-Apps schreiben in anderen Prozessen; vor Entscheidungen frisch lesen. */
	public function reload(): void {
		$this->appConfig->clearCache();
	}

	/** Ein neuer Schlüssel ersetzt Schlüssel und Token gemeinsam. */
	public function setKey(#[\SensitiveParameter] string $key, string $token): void {
		$this->assertWritable();
		$this->appConfig->setValueString(self::APP, self::K_VERSION, (string)self::VERSION);
		$this->appConfig->setValueString(self::APP, self::K_KEY, $key, sensitive: true);
		if ($token === '') {
			$this->appConfig->deleteKey(self::APP, self::K_TOKEN);
		} else {
			$this->appConfig->setValueString(self::APP, self::K_TOKEN, $token);
		}
	}

	/** Die Prüfung, ob das Token neuer ist, liegt beim Aufrufer, weil nur er es verifizieren kann. */
	public function setToken(string $token): void {
		$this->assertWritable();
		$this->appConfig->setValueString(self::APP, self::K_VERSION, (string)self::VERSION);
		$this->appConfig->setValueString(self::APP, self::K_TOKEN, $token);
	}

	/** Nur für die Admin-Aktion „Schlüssel entfernen“; sie gilt für alle Werk-Apps. */
	public function remove(): void {
		$this->assertWritable();
		$this->appConfig->deleteKey(self::APP, self::K_KEY);
		$this->appConfig->deleteKey(self::APP, self::K_TOKEN);
	}

	private function assertWritable(): void {
		if ($this->isOutdated()) {
			throw new \InvalidArgumentException('storeOutdated');
		}
	}

	private function get(string $configKey): string {
		try {
			return $this->appConfig->getValueString(self::APP, $configKey);
		} catch (AppConfigException) {
			// Eine fremde Werk-App mit anderem Werttyp darf hier nicht jede Anfrage scheitern lassen.
			return '';
		}
	}
}
