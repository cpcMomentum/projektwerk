<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpAudit;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\PublicGuard;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\StepService;

class AddStep extends WriteTool {

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		McpRateLimiter $rateLimiter,
		McpAudit $audit,
		private StepService $steps,
	) {
		parent::__construct($access, $guard, $rateLimiter, $audit);
	}

	public function name(): string {
		return 'add_step';
	}

	public function title(): string {
		return 'Add work step';
	}

	public function description(): string {
		return 'Adds a work step (checklist item) to a ticket, optionally assigned to a person (user id from list_members) '
			. 'and with a due date. The assigned person must be able to see the ticket and gets notified. '
			. 'On a customer-visible ticket, ask the user first and pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'Title.'],
				'description' => ['type' => 'string', 'maxLength' => 20000, 'description' => 'Markdown text.'],
				'assigned_user_id' => ['type' => 'string', 'maxLength' => 64, 'description' => 'User id from list_members.'],
				'due_date' => ['type' => 'string', 'maxLength' => 10, 'description' => 'YYYY-MM-DD.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'title'],
			'additionalProperties' => false,
		];
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}

		$step = $this->steps->create(
			$viewer,
			(int)$ticket->getId(),
			$arguments['title'],
			$arguments['assigned_user_id'] ?? null,
			$arguments['due_date'] ?? null,
			$arguments['description'] ?? null,
		);

		return [ToolResult::ok(['step' => self::stepSummary($step)]), (int)$ticket->getId()];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function stepSummary(Step $step): array {
		return [
			'id' => $step->getId(),
			'ticketId' => $step->getTicketId(),
			'title' => $step->getTitle(),
			'assignedUserId' => $step->getAssignedUserId(),
			'dueDate' => $step->getDueDate()?->format('Y-m-d'),
			'done' => $step->isDone(),
		];
	}
}
