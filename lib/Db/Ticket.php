<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use DateTime;
use JsonSerializable;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Vorgang auf dem Board.
 *
 * Zwei Felder tragen die Sichtbarkeitsregel und sind deshalb keine gewoehnlichen
 * Attribute:
 *
 * - `visibility` ist 'public' | 'internal' | 'private'. Die Konstanten dafuer
 *   stehen bewusst **nicht** hier, sondern in {@see TicketScope} — dort, wo die
 *   Bedingung formuliert ist. Zwei Listen von Enum-Werten waeren zwei Orte, an
 *   denen dasselbe stimmen muesste.
 * - `creator_role` wird beim Anlegen **eingefroren** und danach nie
 *   nachgezogen. Wuerde man die Rolle zur Laufzeit ermitteln, verloere
 *   'internal' seine Symmetrie, sobald jemand die Rolle wechselt oder das Board
 *   verlaesst — ein bereits geschriebenes internes Ticket wechselte dann still
 *   die Leserschaft.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getColumnId()
 * @method void setColumnId(int $columnId)
 * @method int getNumber()
 * @method void setNumber(int $number)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method string getVisibility()
 * @method void setVisibility(string $visibility)
 * @method string getCreatorUserId()
 * @method void setCreatorUserId(string $creatorUserId)
 * @method string getCreatorRole()
 * @method void setCreatorRole(string $creatorRole)
 * @method ?string getResponsibleUserId()
 * @method void setResponsibleUserId(?string $responsibleUserId)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method ?DateTime getClosedAt()
 * @method void setClosedAt(?DateTime $closedAt)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method ?int getGithubIssueNumber()
 * @method void setGithubIssueNumber(?int $githubIssueNumber)
 * @method ?string getGithubIssueUrl()
 * @method void setGithubIssueUrl(?string $githubIssueUrl)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class Ticket extends Entity implements JsonSerializable {

	protected ?int $boardId = null;
	protected ?int $columnId = null;
	protected ?int $number = null;
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $visibility = null;
	protected ?string $creatorUserId = null;
	protected ?string $creatorRole = null;
	protected ?string $responsibleUserId = null;
	protected ?int $position = null;
	protected ?DateTime $closedAt = null;
	protected ?int $version = null;
	protected ?int $githubIssueNumber = null;
	protected ?string $githubIssueUrl = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('columnId', Types::INTEGER);
		$this->addType('number', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('visibility', Types::STRING);
		$this->addType('creatorUserId', Types::STRING);
		$this->addType('creatorRole', Types::STRING);
		$this->addType('responsibleUserId', Types::STRING);
		$this->addType('position', Types::INTEGER);
		$this->addType('closedAt', Types::DATETIME);
		$this->addType('version', Types::INTEGER);
		$this->addType('githubIssueNumber', Types::INTEGER);
		$this->addType('githubIssueUrl', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	/**
	 * Ob dieser Betrachter das Ticket bearbeiten darf.
	 *
	 * Nicht zu verwechseln mit dem Sehen: Was jemand sieht, entscheidet
	 * ausschliesslich {@see TicketScope}. Diese Frage stellt sich also erst,
	 * wenn das Ticket bereits durch den Filter gekommen ist.
	 */
	public function isEditableBy(ViewerContext $viewer): bool {
		if ($viewer->boardId !== $this->getBoardId()) {
			return false;
		}
		return $viewer->isManager
			|| $this->getCreatorUserId() === $viewer->userId
			|| $this->getResponsibleUserId() === $viewer->userId;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->getBoardId(),
			'columnId' => $this->getColumnId(),
			'number' => $this->getNumber(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'visibility' => $this->getVisibility(),
			'creatorUserId' => $this->getCreatorUserId(),
			'creatorRole' => $this->getCreatorRole(),
			'responsibleUserId' => $this->getResponsibleUserId(),
			'position' => $this->getPosition(),
			'closedAt' => $this->getClosedAt()?->format(DateTime::ATOM),
			'version' => $this->getVersion(),
			'githubIssueNumber' => $this->getGithubIssueNumber(),
			'githubIssueUrl' => $this->getGithubIssueUrl(),
			'createdAt' => $this->getCreatedAt()?->format(DateTime::ATOM),
			'updatedAt' => $this->getUpdatedAt()?->format(DateTime::ATOM),
		];
	}
}
