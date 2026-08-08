<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Eine Spalte des Boards.
 *
 * Spalten sind fuer beide Seiten identisch (§5.1) — sie tragen keine
 * Sichtbarkeit. Was sich zwischen intern und extern unterscheidet, ist
 * ausschliesslich die Ticket-Menge darin.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method ?string getColor()
 * @method void setColor(?string $color)
 */
class Column extends Entity implements JsonSerializable {

	protected ?int $boardId = null;
	protected ?string $title = null;
	protected ?int $position = null;
	protected ?string $color = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('position', Types::INTEGER);
		$this->addType('color', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->getBoardId(),
			'title' => $this->getTitle(),
			'position' => $this->getPosition(),
			'color' => $this->getColor(),
		];
	}
}
