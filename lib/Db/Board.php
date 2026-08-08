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
 * Ein Projekt.
 *
 * `ticketCounter` und `changeSeq` sind Zaehler, die **nie ueber dieses Entity**
 * hochgezaehlt werden duerfen: Lesen-Erhoehen-Schreiben ist genau das Rennen,
 * das zwei Tickets dieselbe Nummer gibt. Beide bewegen sich per atomarem
 * `UPDATE ... SET x = x + 1` (§3.9); die Setter existieren nur, weil das Entity
 * die Spalten sonst beim Speichern verloere. Der Vorgang selbst kommt mit
 * Phase 2, wo der erste Schreibpfad entsteht.
 *
 * `folderPublicPath` und `folderInternalPath` dienen **nur der Anzeige** und
 * duerfen veralten — fuehrend ist die Datei-ID. In S2 (07.08.2026) gemessen:
 * Die Datei-ID ueberlebt einen Umzug innerhalb des Team-Ordners, der Pfad nicht.
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method ?int getFolderPublicId()
 * @method void setFolderPublicId(?int $folderPublicId)
 * @method ?string getFolderPublicPath()
 * @method void setFolderPublicPath(?string $folderPublicPath)
 * @method ?int getFolderInternalId()
 * @method void setFolderInternalId(?int $folderInternalId)
 * @method ?string getFolderInternalPath()
 * @method void setFolderInternalPath(?string $folderInternalPath)
 * @method ?string getChatUrl()
 * @method void setChatUrl(?string $chatUrl)
 * @method int getTicketCounter()
 * @method void setTicketCounter(int $ticketCounter)
 * @method int getGithubEnabled()
 * @method void setGithubEnabled(int $githubEnabled)
 * @method int getArchived()
 * @method void setArchived(int $archived)
 * @method int getChangeSeq()
 * @method void setChangeSeq(int $changeSeq)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class Board extends Entity implements JsonSerializable {

	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $ownerUserId = null;
	protected ?int $folderPublicId = null;
	protected ?string $folderPublicPath = null;
	protected ?int $folderInternalId = null;
	protected ?string $folderInternalPath = null;
	protected ?string $chatUrl = null;
	protected ?int $ticketCounter = null;
	protected ?int $githubEnabled = null;
	protected ?int $archived = null;
	protected ?int $changeSeq = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('ownerUserId', Types::STRING);
		$this->addType('folderPublicId', Types::INTEGER);
		$this->addType('folderPublicPath', Types::STRING);
		$this->addType('folderInternalId', Types::INTEGER);
		$this->addType('folderInternalPath', Types::STRING);
		$this->addType('chatUrl', Types::STRING);
		$this->addType('ticketCounter', Types::INTEGER);
		// SMALLINT 0/1, nie Types::BOOLEAN mit notnull — das erzeugt
		// Schema-Fehler, und PARAM_BOOL schreibt auf PostgreSQL 'f' statt 0.
		$this->addType('githubEnabled', Types::SMALLINT);
		$this->addType('archived', Types::SMALLINT);
		$this->addType('changeSeq', Types::INTEGER);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function isArchived(): bool {
		return $this->getArchived() === 1;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'ownerUserId' => $this->getOwnerUserId(),
			'folderPublicId' => $this->getFolderPublicId(),
			'folderPublicPath' => $this->getFolderPublicPath(),
			'folderInternalId' => $this->getFolderInternalId(),
			'folderInternalPath' => $this->getFolderInternalPath(),
			'chatUrl' => $this->getChatUrl(),
			'githubEnabled' => $this->getGithubEnabled() === 1,
			'archived' => $this->isArchived(),
			'createdAt' => $this->getCreatedAt()?->format(DateTime::ATOM),
			'updatedAt' => $this->getUpdatedAt()?->format(DateTime::ATOM),
		];
	}
}
