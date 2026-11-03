<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

use OCA\Projektwerk\Mcp\Tools\AddAttachment;
use OCA\Projektwerk\Mcp\Tools\AddComment;
use OCA\Projektwerk\Mcp\Tools\AddStep;
use OCA\Projektwerk\Mcp\Tools\AttachFile;
use OCA\Projektwerk\Mcp\Tools\CloseTicket;
use OCA\Projektwerk\Mcp\Tools\CreateTicket;
use OCA\Projektwerk\Mcp\Tools\GetTicket;
use OCA\Projektwerk\Mcp\Tools\ListBoards;
use OCA\Projektwerk\Mcp\Tools\ListColumns;
use OCA\Projektwerk\Mcp\Tools\ListMembers;
use OCA\Projektwerk\Mcp\Tools\ListTickets;
use OCA\Projektwerk\Mcp\Tools\MoveTicket;
use OCA\Projektwerk\Mcp\Tools\TransferToGithub;
use OCA\Projektwerk\Mcp\Tools\UpdateStep;
use OCA\Projektwerk\Mcp\Tools\UpdateTicket;

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
		ListMembers $listMembers,
		CreateTicket $createTicket,
		UpdateTicket $updateTicket,
		MoveTicket $moveTicket,
		CloseTicket $closeTicket,
		AddStep $addStep,
		UpdateStep $updateStep,
		AddComment $addComment,
		AddAttachment $addAttachment,
		AttachFile $attachFile,
		TransferToGithub $transferToGithub,
	) {
		$all = [$listBoards, $listColumns, $listTickets, $getTicket, $listMembers, $createTicket, $updateTicket, $moveTicket,
			$closeTicket, $addStep, $updateStep, $addComment, $addAttachment, $attachFile, $transferToGithub];
		foreach ($all as $tool) {
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
