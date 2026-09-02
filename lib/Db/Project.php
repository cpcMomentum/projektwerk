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
 * Das Projekt-Dach über den Boards (#246).
 *
 * Trägt die **Engagement-Ebene** eines Kundenprojekts: Owner, die Namen der
 * beiden Seiten, die geteilten Ordner (`90_Austausch` / `91_Tickets_intern`),
 * den Projektchat, den projektweiten Nummernzähler und den Archivzustand.
 * Mehrere Boards teilen sich all das; jedes Board trägt nur noch seine
 * Kanban-Ebene (Titel, Spalten, Karten).
 *
 * **Seit #246 PR 5c ist das Projekt die Quelle** für Ordner und Chat: Die
 * Einstellungen ({@see \OCA\Projektwerk\Service\BoardService::update()}) schreiben
 * sie hier, und die Anzeige ({@see \OCA\Projektwerk\Service\BoardService::forViewerWithProjectFields()})
 * spiegelt sie ins Board zurück. Migration 18 hat den Bestand einmalig
 * nachgezogen.
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method ?string getOrgInternal()
 * @method void setOrgInternal(?string $orgInternal)
 * @method ?string getOrgExternal()
 * @method void setOrgExternal(?string $orgExternal)
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
 * @method int getArchived()
 * @method void setArchived(int $archived)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class Project extends Entity {

	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $ownerUserId = null;
	protected ?string $orgInternal = null;
	protected ?string $orgExternal = null;
	protected ?int $folderPublicId = null;
	protected ?string $folderPublicPath = null;
	protected ?int $folderInternalId = null;
	protected ?string $folderInternalPath = null;
	protected ?string $chatUrl = null;
	protected ?int $ticketCounter = null;
	protected ?int $archived = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('ownerUserId', Types::STRING);
		$this->addType('orgInternal', Types::STRING);
		$this->addType('orgExternal', Types::STRING);
		$this->addType('folderPublicId', Types::INTEGER);
		$this->addType('folderPublicPath', Types::STRING);
		$this->addType('folderInternalId', Types::INTEGER);
		$this->addType('folderInternalPath', Types::STRING);
		$this->addType('chatUrl', Types::STRING);
		$this->addType('ticketCounter', Types::INTEGER);
		$this->addType('archived', Types::SMALLINT);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}
}
