<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Eine mitarbeitende Person am Ticket (neben der einen verantwortlichen).
 *
 * Traegt bewusst **keine Rolle**: Wer hier steht, ist Mitglied des Boards, und
 * die dort geltende Rolle steht in `pwerk_members`. Eine zweite Kopie waere ein
 * zweiter Ort, an dem sie stimmen muesste.
 *
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method DateTime getAddedAt()
 * @method void setAddedAt(DateTime $addedAt)
 */
class TicketUser extends Entity implements JsonSerializable {

	protected ?int $ticketId = null;
	protected ?string $userId = null;
	protected ?DateTime $addedAt = null;

	public function __construct() {
		$this->addType('ticketId', Types::INTEGER);
		$this->addType('userId', Types::STRING);
		$this->addType('addedAt', Types::DATETIME);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ticketId' => $this->getTicketId(),
			'userId' => $this->getUserId(),
			'addedAt' => $this->getAddedAt()?->format(DateTime::ATOM),
		];
	}
}
