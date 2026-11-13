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
 * `visibility` ist der Stand im Moment der Änderung; beim Lesen gilt die Sichtbarkeitsregel darauf.
 *
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getActorUserId()
 * @method void setActorUserId(string $actorUserId)
 * @method ?string getVia()
 * @method void setVia(?string $via)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method ?string getSubject()
 * @method void setSubject(?string $subject)
 * @method ?string getOldValue()
 * @method void setOldValue(?string $oldValue)
 * @method ?string getNewValue()
 * @method void setNewValue(?string $newValue)
 * @method string getVisibility()
 * @method void setVisibility(string $visibility)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class TicketLog extends Entity implements JsonSerializable {

	/** Akteur für Änderungen ohne handelnde Person, etwa per `occ`. */
	public const ACTOR_SYSTEM = 'system';

	public const CREATED = 'created';
	public const MOVED = 'moved';
	public const TITLE = 'title';
	public const DESCRIPTION = 'description';
	public const RESPONSIBLE = 'responsible';
	public const DUE_DATE = 'due_date';
	public const CLOSED = 'closed';
	public const REOPENED = 'reopened';
	public const ARCHIVED = 'archived';
	public const UNARCHIVED = 'unarchived';
	public const VISIBILITY = 'visibility';
	public const GITHUB = 'github';
	public const DELETED = 'deleted';
	public const RESTORED = 'restored';
	public const STEP_ADDED = 'step_added';
	public const STEP_DONE = 'step_done';
	public const STEP_REOPENED = 'step_reopened';
	public const STEP_ASSIGNED = 'step_assigned';
	public const STEP_DELETED = 'step_deleted';
	public const ATTACHMENT_ADDED = 'attachment_added';
	public const ATTACHMENT_REMOVED = 'attachment_removed';
	public const COMMENT_EDITED = 'comment_edited';
	public const COMMENT_DELETED = 'comment_deleted';

	protected ?int $ticketId = null;
	protected ?string $actorUserId = null;
	protected ?string $via = null;
	protected ?string $action = null;
	protected ?string $subject = null;
	protected ?string $oldValue = null;
	protected ?string $newValue = null;
	protected ?string $visibility = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('ticketId', Types::INTEGER);
		$this->addType('actorUserId', Types::STRING);
		$this->addType('via', Types::STRING);
		$this->addType('action', Types::STRING);
		$this->addType('subject', Types::STRING);
		$this->addType('oldValue', Types::STRING);
		$this->addType('newValue', Types::STRING);
		$this->addType('visibility', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
	}

	/** Ohne `visibility`: Grundlage des Filters, keine Auskunft. */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ticketId' => $this->getTicketId(),
			'actorUserId' => $this->getActorUserId(),
			'via' => $this->getVia(),
			'action' => $this->getAction(),
			'subject' => $this->getSubject(),
			'oldValue' => $this->getOldValue(),
			'newValue' => $this->getNewValue(),
			'createdAt' => $this->getCreatedAt()?->format(DateTime::ATOM),
		];
	}
}
