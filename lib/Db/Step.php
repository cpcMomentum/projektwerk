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
 * Ein Arbeitsschritt innerhalb eines Tickets.
 *
 * `assignedRole` wird bei der Zuweisung **kopiert**, nicht verbunden. Das ist
 * die Voraussetzung dafuer, dass „wartet auf Kunde" je Ticket aus den offenen
 * Schritten berechnet werden kann, ohne einen zweiten Verbund auf
 * `pwerk_members` — und dass der Zustand stabil bleibt, wenn die zugewiesene
 * Person das Board verlaesst.
 *
 * Der Wartezustand selbst wird **nie gespeichert**. Er entsteht in-memory aus
 * den Schritten (§3.7) — eine gespeicherte Spalte waere ein zweiter Ort, an dem
 * er stimmen muesste.
 *
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method ?string getResult()
 * @method void setResult(?string $result)
 * @method ?string getAssignedUserId()
 * @method void setAssignedUserId(?string $assignedUserId)
 * @method ?string getAssignedRole()
 * @method void setAssignedRole(?string $assignedRole)
 * @method ?DateTime getAssignedAt()
 * @method void setAssignedAt(?DateTime $assignedAt)
 * @method int getDone()
 * @method void setDone(int $done)
 * @method ?DateTime getDoneAt()
 * @method void setDoneAt(?DateTime $doneAt)
 * @method ?DateTime getDueDate()
 * @method void setDueDate(?DateTime $dueDate)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class Step extends Entity implements JsonSerializable {

	protected ?int $ticketId = null;
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $result = null;
	protected ?string $assignedUserId = null;
	protected ?string $assignedRole = null;
	protected ?DateTime $assignedAt = null;
	protected ?int $done = null;
	protected ?DateTime $doneAt = null;
	protected ?DateTime $dueDate = null;
	protected ?int $position = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('ticketId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('result', Types::TEXT);
		$this->addType('assignedUserId', Types::STRING);
		$this->addType('assignedRole', Types::STRING);
		$this->addType('assignedAt', Types::DATETIME);
		$this->addType('done', Types::SMALLINT);
		$this->addType('doneAt', Types::DATETIME);
		$this->addType('dueDate', Types::DATE);
		$this->addType('position', Types::INTEGER);
		$this->addType('createdAt', Types::DATETIME);
	}

	public function isDone(): bool {
		return $this->getDone() === 1;
	}

	/**
	 * Traegt dieser Schritt zum Zustand „wartet auf Kunde" bei?
	 *
	 * Offen **und** an eine externe Rolle vergeben. Beide Bedingungen zusammen —
	 * ein erledigter Schritt haelt nichts mehr auf.
	 */
	public function waitsOnExternal(): bool {
		return !$this->isDone() && $this->getAssignedRole() === ViewerContext::ROLE_EXTERNAL;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ticketId' => $this->getTicketId(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'result' => $this->getResult(),
			'assignedUserId' => $this->getAssignedUserId(),
			'assignedRole' => $this->getAssignedRole(),
			'assignedAt' => $this->getAssignedAt()?->format(DateTime::ATOM),
			'done' => $this->isDone(),
			'doneAt' => $this->getDoneAt()?->format(DateTime::ATOM),
			'dueDate' => $this->getDueDate()?->format('Y-m-d'),
			'position' => $this->getPosition(),
			'createdAt' => $this->getCreatedAt()?->format(DateTime::ATOM),
		];
	}
}
