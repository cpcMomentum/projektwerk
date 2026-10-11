<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

use OCA\Projektwerk\Mcp\Tools\GetTicket;
use OCA\Projektwerk\Mcp\Tools\ListBoards;
use OCA\Projektwerk\Mcp\Tools\ListColumns;
use OCA\Projektwerk\Mcp\Tools\ListTickets;

/**
 * Die freigeschalteten Werkzeuge, in fester Reihenfolge (Modelle cachen die Liste).
 */
class ToolRegistry {

	/** @var array<string, Tool> */
	private array $tools = [];

	public function __construct(
		ListBoards $listBoards,
		ListColumns $listColumns,
		ListTickets $listTickets,
		GetTicket $getTicket,
	) {
		foreach ([$listBoards, $listColumns, $listTickets, $getTicket] as $tool) {
			$this->tools[$tool->name()] = $tool;
		}
		ksort($this->tools);
	}

	/**
	 * @return list<Tool>
	 */
	public function all(): array {
		return array_values($this->tools);
	}

	public function get(string $name): ?Tool {
		return $this->tools[$name] ?? null;
	}
}
