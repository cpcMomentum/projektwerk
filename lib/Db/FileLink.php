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
 * Eine Datei oder ein Ordner, auf den ein Vorgang verweist; das Ziel bleibt an seiner Stelle.
 *
 * @method int|null getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method int|null getFileId()
 * @method void setFileId(int $fileId)
 * @method string|null getFileName()
 * @method void setFileName(string $fileName)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string|null getCreatedVia()
 * @method void setCreatedVia(?string $createdVia)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class FileLink extends Entity {

	protected ?int $ticketId = null;
	protected ?int $fileId = null;
	protected ?string $fileName = null;
	protected ?string $createdBy = null;
	protected ?string $createdVia = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('ticketId', Types::INTEGER);
		$this->addType('fileId', Types::INTEGER);
		$this->addType('fileName', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdVia', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
	}
}
