<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Die eigene Firma, einmal je Instanz (#352).
 *
 * Eine Instanz gehört in der Regel einem Dienstleister; neue Projekte übernehmen
 * diesen Namen als „eigene Firma", statt ihn je Projekt frei zu tippen. Im Projekt
 * bleibt er änderbar.
 */
class OwnCompanySettings {

	public const KEY = 'own_company';
	public const MAX_LENGTH = 255;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * @return string|null der Name, oder null wenn keiner gesetzt ist
	 */
	public function get(): ?string {
		$value = trim($this->config->getValueString(Application::APP_ID, self::KEY, ''));

		return $value === '' ? null : $value;
	}

	/**
	 * @throws \InvalidArgumentException bei zu langem Namen
	 */
	public function set(?string $name): ?string {
		$value = trim((string)$name);
		if (mb_strlen($value) > self::MAX_LENGTH) {
			throw new \InvalidArgumentException('too long');
		}

		if ($value === '') {
			$this->config->deleteKey(Application::APP_ID, self::KEY);

			return null;
		}
		$this->config->setValueString(Application::APP_ID, self::KEY, $value);

		return $value;
	}
}
