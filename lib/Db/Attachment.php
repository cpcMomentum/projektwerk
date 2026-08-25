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
 * Ein Anhang am Ticket — als **Verweis auf eine Datei**, nicht als Kopie.
 *
 * Fuehrend ist `fileId`. `filePath` und `location` werden beim
 * Sichtbarkeitswechsel nachgezogen und duerfen zwischenzeitlich veralten; der
 * Pfad dient der Anzeige. Kein `IAppData`, kein eigener Share-Provider, keine
 * von der App angelegten Freigaben.
 *
 * `location` ist 'public' | 'internal' und benennt, in welchem der beiden
 * Projektordner die Datei liegt. Das ist die einzige Stelle, an der die
 * Sichtbarkeit **physisch** wird — eine spaetere Codekorrektur heilt einen
 * Fehler hier nicht. Deshalb verweigert der MVP den Sichtbarkeitswechsel,
 * solange Anhaenge am Ticket haengen (§3.10).
 *
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method ?string getFilePath()
 * @method void setFilePath(?string $filePath)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getLocation()
 * @method void setLocation(string $location)
 * @method string getUploadedBy()
 * @method void setUploadedBy(string $uploadedBy)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class Attachment extends Entity implements JsonSerializable {

	public const LOCATION_PUBLIC = 'public';
	public const LOCATION_INTERNAL = 'internal';
	// Der persönliche Ablageort eines „Nur ich"-Vorgangs (#184, Phase B): nicht
	// im geteilten Team-Ordner, sondern im eigenen Files-Bereich der anlegenden
	// Person — der einzige Ort, dessen Reichweite exakt „nur diese eine Person"
	// ist. Aufgelöst wird er nicht über eine Board-Ordner-ID, sondern über
	// ProjectFolderService::privateFolderFor().
	public const LOCATION_PRIVATE = 'private';

	protected ?int $ticketId = null;
	protected ?int $fileId = null;
	protected ?string $filePath = null;
	protected ?string $fileName = null;
	protected ?string $location = null;
	protected ?string $uploadedBy = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('ticketId', Types::INTEGER);
		$this->addType('fileId', Types::INTEGER);
		$this->addType('filePath', Types::STRING);
		$this->addType('fileName', Types::STRING);
		$this->addType('location', Types::STRING);
		$this->addType('uploadedBy', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ticketId' => $this->getTicketId(),
			'fileId' => $this->getFileId(),
			'filePath' => $this->getFilePath(),
			'fileName' => $this->getFileName(),
			'location' => $this->getLocation(),
			'uploadedBy' => $this->getUploadedBy(),
			'createdAt' => $this->getCreatedAt()?->format(DateTime::ATOM),
		];
	}
}
