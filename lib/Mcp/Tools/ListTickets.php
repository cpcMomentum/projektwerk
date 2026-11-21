<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Access\WaitStateCalculator;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\Tool;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\BoardReadModel;
use OCA\Projektwerk\Service\TicketReadModel;
use OCP\IDateTimeZone;

class ListTickets implements Tool {

	private const MAX_PAGE = 100;
	public const ME = 'me';

	public function __construct(
		private BoardAccess $access,
		private TicketReadModel $tickets,
		private WaitStateCalculator $waitState,
		private CommentMapper $comments,
		private BoardReadModel $boards,
		private IDateTimeZone $zone,
	) {
	}

	public function name(): string {
		return 'list_tickets';
	}

	public function title(): string {
		return 'List tickets';
	}

	public function description(): string {
		return 'Lists the tickets you are allowed to see. With board_id one board (optionally one column), without board_id all your projects, '
			. 'like "My tasks". Filters combine: responsible_user_id ("me" for yourself), due_before (YYYY-MM-DD, inclusive), '
			. 'overdue (due date before today, open tickets only), changed_since (ISO date or date-time; a new comment counts as a change). '
			. 'Closed tickets are left out unless include_closed is true. '
			. 'Every row carries its visibility: "public" tickets are visible to the customer side, '
			. '"internal" only to your own side, "private" only to you. '
			. 'If next_cursor is set, call again with the same filters and cursor to get the next page.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards. Omit to list across all your projects.'],
				'column_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only tickets in this column (needs board_id).'],
				'responsible_user_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'Only tickets this person is responsible for; "me" for yourself.'],
				'due_before' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Only tickets due on or before this date (YYYY-MM-DD).'],
				'overdue' => ['type' => 'boolean', 'description' => 'true: only overdue tickets; false: only tickets that are not overdue.'],
				'changed_since' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Only tickets changed or commented since this point (ISO 8601 date or date-time).'],
				'include_closed' => ['type' => 'boolean', 'description' => 'Also list done and discarded tickets. Default false.'],
				'cursor' => ['type' => 'string', 'maxLength' => 64, 'description' => 'next_cursor from a previous call.'],
			],
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
		$boardId = $arguments['board_id'] ?? null;
		if ($boardId === null && isset($arguments['column_id'])) {
			return ToolResult::error('column_id needs board_id.');
		}
		if (isset($arguments['due_before']) && DateArgument::parse($arguments['due_before'], DateArgument::DATE, $this->zone->getTimeZone()) === null) {
			return ToolResult::error('due_before must be a date like 2026-11-27.');
		}
		$since = null;
		if (isset($arguments['changed_since'])) {
			$since = DateArgument::parse($arguments['changed_since'], DateArgument::DATE_OR_TIME, $this->zone->getTimeZone());
			if ($since === null) {
				return ToolResult::error('changed_since must be an ISO 8601 date (2026-11-20) or date-time (2026-11-20T08:00:00+01:00).');
			}
		}

		if ($boardId !== null) {
			try {
				$data = $this->tickets->index($this->access->contextFor($caller->userId, $boardId), $arguments['column_id'] ?? null);
			} catch (NotAMemberException) {
				return ToolResult::notFound();
			}
		} else {
			$boards = $this->boardsById($caller->userId);
			$active = array_keys(array_filter($boards, static fn (array $board): bool => !$board['archived'] && !$board['projectArchived']));
			$data = $this->tickets->indexAcrossBoards($caller->userId, (bool)($arguments['include_closed'] ?? false), $active);
		}

		$tickets = $this->filtered($caller, $data['tickets'], $arguments);
		$lastChanged = $this->lastChanged($tickets);
		if ($since !== null) {
			$tickets = array_values(array_filter($tickets, static fn (Ticket $t): bool => $lastChanged[(int)$t->getId()] >= $since));
		}

		$boards ??= [];
		$rows = array_map(fn (Ticket $ticket): array => $this->row($ticket, $data, $lastChanged, $boards, $boardId === null), $tickets);

		$remaining = array_slice($rows, $offset);
		$page = array_slice($remaining, 0, min(self::MAX_PAGE, ToolResult::rowsThatFit($remaining)));
		$next = $offset + count($page) < count($rows) ? Cursor::encode($offset + count($page)) : null;

		return ToolResult::ok([
			'boardId' => $boardId,
			'total' => count($rows),
			'tickets' => $page,
			'next_cursor' => $next,
		]);
	}

	/** @return list<Ticket> nur die Vorgänge, die alle Filter erfüllen */
	private function filtered(AuthenticatedCaller $caller, array $tickets, array $arguments): array {
		$responsible = $arguments['responsible_user_id'] ?? null;
		if ($responsible === self::ME) {
			$responsible = $caller->userId;
		}
		$dueBefore = $arguments['due_before'] ?? null;
		$overdue = $arguments['overdue'] ?? null;
		$includeClosed = (bool)($arguments['include_closed'] ?? false);

		return array_values(array_filter($tickets, function (Ticket $ticket) use ($responsible, $dueBefore, $overdue, $includeClosed): bool {
			if (!$includeClosed && $ticket->getClosedAt() !== null) {
				return false;
			}
			if ($responsible !== null && $ticket->getResponsibleUserId() !== $responsible) {
				return false;
			}
			$due = $ticket->getDueDate()?->format('Y-m-d');
			if ($dueBefore !== null && ($due === null || $due > $dueBefore)) {
				return false;
			}

			return $overdue === null || $this->isOverdue($ticket) === $overdue;
		}));
	}

	private function isOverdue(Ticket $ticket): bool {
		return $ticket->getClosedAt() === null && $this->waitState->isOverdue($ticket);
	}

	/** @return array<int, \DateTimeImmutable> späteste Änderung oder jüngster Kommentar je Vorgang */
	private function lastChanged(array $tickets): array {
		$ids = array_map(static fn (Ticket $ticket): int => (int)$ticket->getId(), $tickets);
		$newest = $this->comments->findNewestForTickets($ids);
		$last = [];
		foreach ($tickets as $ticket) {
			$id = (int)$ticket->getId();
			$updated = \DateTimeImmutable::createFromInterface($ticket->getUpdatedAt() ?? $ticket->getCreatedAt() ?? new \DateTime('@0'));
			$commented = isset($newest[$id]) ? new \DateTimeImmutable($newest[$id]['at']) : null;
			$last[$id] = $commented !== null && $commented > $updated ? $commented : $updated;
		}

		return $last;
	}

	/** @return array<int, array<string, mixed>> */
	private function boardsById(string $userId): array {
		$boards = [];
		foreach ($this->boards->listFor($userId, true) as $board) {
			$boards[(int)$board['id']] = $board;
		}

		return $boards;
	}

	private function row(Ticket $ticket, array $data, array $lastChanged, array $boards, bool $acrossBoards): array {
		$id = (int)$ticket->getId();
		$row = [
			'id' => $id,
			'number' => $ticket->getNumber(),
			'columnId' => $ticket->getColumnId(),
			'visibility' => $ticket->getVisibility(),
			'title' => $ticket->getTitle(),
			'responsibleUserId' => $ticket->getResponsibleUserId(),
			'dueDate' => $ticket->getDueDate()?->format('Y-m-d'),
			'overdue' => $this->isOverdue($ticket),
			'closedOutcome' => $ticket->getClosedOutcome(),
			'lastChangedAt' => $lastChanged[$id]->format(\DateTime::ATOM),
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
		if ($acrossBoards) {
			$board = $boards[(int)$ticket->getBoardId()] ?? null;
			$row += ['boardId' => $ticket->getBoardId(), 'boardTitle' => $board['title'] ?? null, 'projectId' => $board['projectId'] ?? null, 'projectTitle' => $board['projectTitle'] ?? null];
		}

		return $row;
	}
}
