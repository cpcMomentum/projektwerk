<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Wann eine Person einen Vorgang zuletzt angesehen hat (#79).
 *
 * Je Person und Vorgang genau eine Zeile. Wird nie an den Browser ausgeliefert
 * — die Karte bekommt nur die gerechnete Antwort „seit deinem Blick geändert",
 * nicht den Zeitstempel selbst.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method DateTime getSeenAt()
 * @method void setSeenAt(DateTime $seenAt)
 */
class TicketRead extends Entity {

	protected ?string $userId = null;
	protected ?int $ticketId = null;
	protected ?DateTime $seenAt = null;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('ticketId', Types::INTEGER);
		$this->addType('seenAt', Types::DATETIME);
	}
}
