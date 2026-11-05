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
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getIdemKey()
 * @method void setIdemKey(string $idemKey)
 * @method string getFingerprint()
 * @method void setFingerprint(string $fingerprint)
 * @method string|null getResponse()
 * @method void setResponse(?string $response)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class IdemKey extends Entity {

	protected ?string $userId = null;
	protected ?string $idemKey = null;
	protected ?string $fingerprint = null;
	protected ?string $response = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('idemKey', Types::STRING);
		$this->addType('fingerprint', Types::STRING);
		$this->addType('response', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME);
	}
}
