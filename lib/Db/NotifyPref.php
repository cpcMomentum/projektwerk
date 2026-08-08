<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Der persoenliche Schalter fuer einen Benachrichtigungskanal.
 *
 * **Eine fehlende Zeile bedeutet „an"** — der Kanal ist die Vorgabe, nicht die
 * Ausnahme. Deshalb gibt es keinen Anlegevorgang bei der Registrierung, und
 * deshalb ist `channel` ein Textfeld statt zweier Spalten: Ein dritter Kanal
 * (Talk, Post-MVP 2) kommt dann ohne Migration dazu.
 *
 * Der Mapper folgt in Phase 6 zusammen mit dem Versandpfad — vorher gaebe es
 * niemanden, der ihn liest.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method int getEnabled()
 * @method void setEnabled(int $enabled)
 */
class NotifyPref extends Entity implements JsonSerializable {

	public const CHANNEL_BELL = 'bell';
	public const CHANNEL_MAIL = 'mail';

	protected ?string $userId = null;
	protected ?string $channel = null;
	protected ?int $enabled = null;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('channel', Types::STRING);
		$this->addType('enabled', Types::SMALLINT);
	}

	public function isEnabled(): bool {
		return $this->getEnabled() === 1;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'channel' => $this->getChannel(),
			'enabled' => $this->isEnabled(),
		];
	}
}
