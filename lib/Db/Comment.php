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
 * Ein Kommentar an einem Ticket.
 *
 * Kommentare haben **keine eigene Sichtbarkeit**. Sie erben sie vollstaendig
 * vom Ticket — und genau deshalb duerfen sie nie eigenstaendig abgefragt
 * werden. Die Bauform dafuer steht in {@see TicketChildMapper}.
 *
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getAuthorUserId()
 * @method void setAuthorUserId(string $authorUserId)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class Comment extends Entity implements JsonSerializable {

	protected ?int $ticketId = null;
	protected ?string $authorUserId = null;
	protected ?string $body = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('ticketId', Types::INTEGER);
		$this->addType('authorUserId', Types::STRING);
		$this->addType('body', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ticketId' => $this->getTicketId(),
			'authorUserId' => $this->getAuthorUserId(),
			'body' => $this->getBody(),
			'createdAt' => $this->getCreatedAt()?->format(DateTime::ATOM),
			'updatedAt' => $this->getUpdatedAt()?->format(DateTime::ATOM),
		];
	}
}
