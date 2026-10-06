<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\Tool;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\BoardReadModel;
use OCP\AppFramework\Db\DoesNotExistException;

class ListColumns implements Tool {

	public function __construct(
		private BoardAccess $access,
		private BoardReadModel $boards,
	) {
	}

	public function name(): string {
		return 'list_columns';
	}

	public function title(): string {
		return 'List board columns';
	}

	public function description(): string {
		return 'Lists the columns of a board in display order. '
			. 'finalOutcome marks a closing column ("done" or "discarded"), otherwise null.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
			],
			'required' => ['board_id'],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array {
		return ['readOnlyHint' => true, 'openWorldHint' => false];
	}

	public function call(AuthenticatedCaller $caller, array $arguments): ToolResult {
		try {
			$columns = $this->boards->columns($this->access->contextFor($caller->userId, $arguments['board_id']));
		} catch (NotAMemberException|DoesNotExistException) {
			return ToolResult::notFound();
		}

		$columns = array_map(static fn (Column $column): array => [
			'id' => $column->getId(),
			'title' => $column->getTitle(),
			'position' => $column->getPosition(),
			'finalOutcome' => $column->getFinalOutcome(),
		], $columns);

		return ToolResult::ok(['boardId' => $arguments['board_id'], 'columns' => $columns]);
	}
}
