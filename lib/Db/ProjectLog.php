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
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method string getActorUserId()
 * @method void setActorUserId(string $actorUserId)
 * @method ?string getVia()
 * @method void setVia(?string $via)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method ?string getTargetUserId()
 * @method void setTargetUserId(?string $targetUserId)
 * @method ?string getSubject()
 * @method void setSubject(?string $subject)
 * @method ?string getOldValue()
 * @method void setOldValue(?string $oldValue)
 * @method ?string getNewValue()
 * @method void setNewValue(?string $newValue)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class ProjectLog extends Entity implements JsonSerializable {

	public const PROJECT_CREATED = 'project_created';
	public const PROJECT_ARCHIVED = 'project_archived';
	public const PROJECT_UNARCHIVED = 'project_unarchived';
	public const BOARD_CREATED = 'board_created';
	public const BOARD_ARCHIVED = 'board_archived';
	public const BOARD_UNARCHIVED = 'board_unarchived';
	public const BOARD_DELETED = 'board_deleted';
	public const MEMBER_ADDED = 'member_added';
	public const MEMBER_REMOVED = 'member_removed';
	public const ROLE_CHANGED = 'role_changed';
	public const MANAGER_GRANTED = 'manager_granted';
	public const MANAGER_REVOKED = 'manager_revoked';
	public const OWNER_CHANGED = 'owner_changed';

	public const REASON_ACCOUNT_DELETED = 'account_deleted';
	public const VIA_CONSOLE = 'occ';

	protected ?int $projectId = null;
	protected ?string $actorUserId = null;
	protected ?string $via = null;
	protected ?string $action = null;
	protected ?string $targetUserId = null;
	protected ?string $subject = null;
	protected ?string $oldValue = null;
	protected ?string $newValue = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('projectId', Types::INTEGER);
		$this->addType('actorUserId', Types::STRING);
		$this->addType('via', Types::STRING);
		$this->addType('action', Types::STRING);
		$this->addType('targetUserId', Types::STRING);
		$this->addType('subject', Types::STRING);
		$this->addType('oldValue', Types::STRING);
		$this->addType('newValue', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'actorUserId' => $this->getActorUserId(),
			'via' => $this->getVia(),
			'action' => $this->getAction(),
			'targetUserId' => $this->getTargetUserId(),
			'subject' => $this->getSubject(),
			'oldValue' => $this->getOldValue(),
			'newValue' => $this->getNewValue(),
			'createdAt' => $this->getCreatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}
}
