<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\Tool;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\TicketReadModel;

class ListTickets implements Tool {

	private const MAX_PAGE = 100;

	public function __construct(
		private BoardAccess $access,
		private TicketReadModel $tickets,
	) {
	}

	public function name(): string {
		return 'list_tickets';
	}

	public function title(): string {
		return 'List tickets';
	}

	public function description(): string {
		return 'Lists the tickets on a board that you are allowed to see, optionally filtered by column. '
			. 'Every row carries its visibility: "public" tickets are visible to the customer side, '
			. '"internal" only to your own side, "private" only to you. '
			. 'If next_cursor is set, call again with cursor to get the next page.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'column_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only tickets in this column.'],
				'cursor' => ['type' => 'string', 'maxLength' => 64, 'description' => 'next_cursor from a previous call.'],
			],
			'required' => ['board_id'],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array {
		return ['readOnlyHint' => true, 'openWorldHint' => false];
	}

	public function call(AuthenticatedCaller $caller, array $arguments): ToolResult {
		$offset = Cursor::decode($arguments['cursor'] ?? null);
		if ($offset === null) {
			return ToolResult::error('Invalid cursor. Call again without cursor to start from the first page.');
		}

		try {
			$viewer = $this->access->contextFor($caller->userId, $arguments['board_id']);
		} catch (NotAMemberException) {
			return ToolResult::notFound();
		}

		$data = $this->tickets->index($viewer, $arguments['column_id'] ?? null);
		$rows = array_map(fn (Ticket $ticket): array => $this->row($ticket, $data), $data['tickets']);

		$remaining = array_slice($rows, $offset);
		$page = array_slice($remaining, 0, min(self::MAX_PAGE, ToolResult::rowsThatFit($remaining)));
		$next = $offset + count($page) < count($rows) ? Cursor::encode($offset + count($page)) : null;

		return ToolResult::ok([
			'boardId' => $arguments['board_id'],
			'total' => count($rows),
			'tickets' => $page,
			'next_cursor' => $next,
		]);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function row(Ticket $ticket, array $data): array {
		$id = (int)$ticket->getId();

		return [
			'id' => $id,
			'number' => $ticket->getNumber(),
			'columnId' => $ticket->getColumnId(),
			'visibility' => $ticket->getVisibility(),
			'title' => $ticket->getTitle(),
			'responsibleUserId' => $ticket->getResponsibleUserId(),
			'dueDate' => $ticket->getDueDate()?->format('Y-m-d'),
			'closedOutcome' => $ticket->getClosedOutcome(),
			'githubIssueUrl' => $ticket->getGithubIssueUrl(),
			'waitingForCustomer' => isset($data['waiting'][$id]),
			'changedSinceYouLooked' => isset($data['changed'][$id]),
			'counts' => [
				'comments' => $data['counts']['comments'][$id] ?? 0,
				'steps' => $data['counts']['steps'][$id] ?? 0,
				'stepsDone' => $data['counts']['stepsDone'][$id] ?? 0,
				'attachments' => $data['counts']['attachments'][$id] ?? 0,
			],
		];
	}
}
