<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\Tool;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\BoardReadModel;
use OCP\AppFramework\Db\DoesNotExistException;

class ListMembers implements Tool {

	public function __construct(
		private BoardAccess $access,
		private BoardReadModel $boards,
	) {
	}

	public function name(): string {
		return 'list_members';
	}

	public function title(): string {
		return 'List board members';
	}

	public function description(): string {
		return 'Lists the people on a board with their user id, name and side ("internal" = service provider, '
			. '"external" = customer). Use the user id to make someone responsible for a ticket or to assign a step.';
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
			$members = $this->boards->members($this->access->contextFor($caller->userId, $arguments['board_id']));
		} catch (NotAMemberException|DoesNotExistException) {
			return ToolResult::notFound();
		}

		return ToolResult::ok([
			'boardId' => $arguments['board_id'],
			'members' => array_map(static fn (array $member): array => [
				'userId' => $member['userId'],
				'name' => $member['resolvedName'],
				'role' => $member['role'],
				'company' => $member['company'] ?? null,
			], $members),
		]);
	}
}
