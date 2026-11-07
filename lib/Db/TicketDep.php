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
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method int getDependsOnId()
 * @method void setDependsOnId(int $dependsOnId)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string|null getCreatedVia()
 * @method void setCreatedVia(?string $createdVia)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class TicketDep extends Entity {

	protected ?int $boardId = null;
	protected ?int $ticketId = null;
	protected ?int $dependsOnId = null;
	protected ?string $createdBy = null;
	protected ?string $createdVia = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('boardId', Types::BIGINT);
		$this->addType('ticketId', Types::BIGINT);
		$this->addType('dependsOnId', Types::BIGINT);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdVia', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
	}
}
