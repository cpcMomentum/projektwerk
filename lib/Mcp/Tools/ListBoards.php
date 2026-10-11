<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\Tool;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\BoardReadModel;

class ListBoards implements Tool {

	public function __construct(
		private BoardReadModel $boards,
	) {
	}

	public function name(): string {
		return 'list_boards';
	}

	public function title(): string {
		return 'List boards';
	}

	public function description(): string {
		return 'Lists the ProjektWerk boards you are a member of, with your role on each board '
			. '("internal" = service provider side, "external" = customer side). '
			. 'Use the board id with list_columns, list_tickets and get_ticket.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'include_archived' => ['type' => 'boolean', 'description' => 'Also list archived boards. Default false.'],
			],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array {
		return ['readOnlyHint' => true, 'openWorldHint' => false];
	}

	public function call(AuthenticatedCaller $caller, array $arguments): ToolResult {
		$rows = array_map(static fn (array $board): array => [
			'id' => $board['id'],
			'projectId' => $board['projectId'],
			'title' => $board['title'],
			'description' => $board['description'],
			'customer' => $board['customer'],
			'archived' => $board['archived'],
			'yourRole' => $board['viewerRole'],
		], $this->boards->listFor($caller->userId, (bool)($arguments['include_archived'] ?? false)));

		return ToolResult::ok(['boards' => $rows]);
	}
}
