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
 * **Wer ein Ticket aendern darf, steht hier bewusst noch nicht.** Die
 * Produktbeschreibung sagt zu Schreibrechten am einzelnen Ticket nichts; sie
 * regelt Rollen (§ Rollentabelle) und das Verwaltungsrecht am Board. Eine hier
 * erfundene Regel waere genau die Art Festlegung, die eine spaetere Schicht
 * uebernimmt, ohne sie noch einmal herzuleiten. Sie entsteht mit dem ersten
 * Schreibpfad in Phase 2.
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
 * @method ?DateTime getDeletedAt()
 * @method void setDeletedAt(?DateTime $deletedAt)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method ?string getLastEditorUserId()
 * @method void setLastEditorUserId(?string $lastEditorUserId)
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
	/**
	 * Weich geloescht.
	 *
	 * **Wird nie ausgeliefert** — siehe `jsonSerialize()`. Der Wert existiert
	 * nur, damit `TicketScope` geloeschte Vorgaenge aus jeder Abfrage nimmt.
	 */
	protected ?DateTime $deletedAt = null;
	protected ?int $version = null;
	protected ?string $lastEditorUserId = null;
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
		$this->addType('deletedAt', Types::DATETIME);
		$this->addType('version', Types::INTEGER);
		$this->addType('lastEditorUserId', Types::STRING);
		$this->addType('githubIssueNumber', Types::INTEGER);
		$this->addType('githubIssueUrl', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	/**
	 * **`position` geht nicht mit über die Leitung.**
	 *
	 * Das Ticket ist die einzige Entität, die je Betrachter gefiltert wird —
	 * und eine ausgelieferte Sortierposition verriete genau das, was der Filter
	 * verbirgt: Sieht ein Kunde in einer Spalte die Positionen 1, 3 und 7, weiss
	 * er, dass es dazwischen Vorgaenge gibt. §5.8 nennt Sortierpositionen
	 * ausdruecklich neben den Zaehlern.
	 *
	 * §11 nimmt diese Auskunft **nur fuer die Ticketnummer** bewusst in Kauf,
	 * weil die Nummer Dateinamen und Direktlinks traegt. Fuer die Position gilt
	 * die Ausnahme nicht.
	 *
	 * Es kostet auch nichts: Die Reihenfolge steckt in der Reihenfolge des
	 * gelieferten Feldes, und `moveTicket()` nimmt laut §3.6 **Nachbar-IDs statt
	 * einer Position** entgegen. Wer nie eine Position sendet, muss auch keine
	 * lesen. `position` bleibt damit vollstaendig serverseitig.
	 *
	 * `Column::position` und `Step::position` sind davon nicht betroffen: Weder
	 * Spalten noch die Schritte eines sichtbaren Tickets werden je Betrachter
	 * gefiltert, dort verraet eine Luecke nichts.
	 */
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
			// `position` fehlt hier absichtlich — siehe Methodenkommentar.
			'closedAt' => $this->getClosedAt()?->format(DateTime::ATOM),
			'version' => $this->getVersion(),
			// NULL heisst: seit dem Anlegen unveraendert.
			'lastEditorUserId' => $this->getLastEditorUserId(),
			'githubIssueNumber' => $this->getGithubIssueNumber(),
			'githubIssueUrl' => $this->getGithubIssueUrl(),
			'createdAt' => $this->getCreatedAt()?->format(DateTime::ATOM),
			'updatedAt' => $this->getUpdatedAt()?->format(DateTime::ATOM),
		];
	}
}
