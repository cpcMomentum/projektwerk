<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use DateTime;
use JsonSerializable;
use OCA\Projektwerk\Access\ViewerContext;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Die Mitgliedschaft einer Person in einem Board — und damit ihre Rolle.
 *
 * Diese Zeile ist der Anker der gesamten Zugriffskontrolle: {@see \OCA\Projektwerk\Access\BoardAccess}
 * liest sie, {@see \OCA\Projektwerk\Access\TicketScope} verbindet darauf. Die Rollenkonstanten stehen
 * deshalb in {@see ViewerContext} und nicht hier — es gibt genau eine Liste
 * gueltiger Rollen.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method ?int getProjectId()
 * @method void setProjectId(?int $projectId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method int getIsManager()
 * @method void setIsManager(int $isManager)
 * @method ?string getDisplayName()
 * @method void setDisplayName(?string $displayName)
 * @method string getAddedBy()
 * @method void setAddedBy(string $addedBy)
 * @method DateTime getAddedAt()
 * @method void setAddedAt(DateTime $addedAt)
 */
class Member extends Entity implements JsonSerializable {

	protected ?int $boardId = null;
	protected ?int $projectId = null;
	protected ?string $userId = null;
	protected ?string $role = null;
	protected ?int $isManager = null;
	protected ?string $displayName = null;
	protected ?string $addedBy = null;
	protected ?DateTime $addedAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('projectId', Types::INTEGER);
		$this->addType('userId', Types::STRING);
		$this->addType('role', Types::STRING);
		$this->addType('isManager', Types::SMALLINT);
		$this->addType('displayName', Types::STRING);
		$this->addType('addedBy', Types::STRING);
		$this->addType('addedAt', Types::DATETIME);
	}

	public function isInternal(): bool {
		return $this->getRole() === ViewerContext::ROLE_INTERNAL;
	}

	/**
	 * Verwaltungsrecht gilt laut §8 nur fuer interne Mitglieder. Ein externes
	 * Mitglied mit gesetztem Flag waere ein Datenfehler — hier wird er
	 * entschaerft statt weitergereicht, genau wie in der Fabrikmethode von
	 * {@see ViewerContext}.
	 */
	public function isManagerEffective(): bool {
		return $this->getIsManager() === 1 && $this->isInternal();
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->getBoardId(),
			'userId' => $this->getUserId(),
			'role' => $this->getRole(),
			'isManager' => $this->isManagerEffective(),
			// NULL heisst: Anzeigename aus Nextcloud verwenden.
			'displayName' => $this->getDisplayName(),
			'addedBy' => $this->getAddedBy(),
			'addedAt' => $this->getAddedAt()?->format(DateTime::ATOM),
		];
	}
}
