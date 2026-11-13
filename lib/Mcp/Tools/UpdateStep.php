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
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpAudit;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\PublicGuard;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\ContentLimits;
use OCA\Projektwerk\Service\StepService;

class UpdateStep extends WriteTool {

	private const FIELDS = [
		'title' => 'title',
		'description' => 'description',
		'result' => 'result',
		'assigned_user_id' => 'assignedUserId',
		'due_date' => 'dueDate',
		'done' => 'done',
	];

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		McpRateLimiter $rateLimiter,
		McpAudit $audit,
		private StepService $steps,
		private StepMapper $stepRows,
	) {
		parent::__construct($access, $guard, $rateLimiter, $audit);
	}

	public function name(): string {
		return 'update_step';
	}

	public function title(): string {
		return 'Update work step';
	}

	public function description(): string {
		return 'Changes a work step: tick it off (done: true) or reopen it, change title, description, result, '
			. 'assigned person (user id from list_members) or due date. Only the fields you pass change. '
			. 'Pass an empty string for assigned_user_id or due_date to clear it. '
			. 'On a customer-visible ticket, ask the user first and pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'step_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Step id from get_ticket.'],
				'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ContentLimits::STEP_TITLE, 'description' => 'Title.'],
				'description' => ['type' => 'string', 'maxLength' => ContentLimits::STEP_TEXT, 'description' => 'Markdown text.'],
				'result' => ['type' => 'string', 'maxLength' => ContentLimits::STEP_TEXT, 'description' => 'Result of the step, Markdown.'],
				'assigned_user_id' => ['type' => 'string', 'maxLength' => 64, 'description' => 'User id from list_members, or "" to clear.'],
				'due_date' => ['type' => 'string', 'maxLength' => 10, 'description' => 'YYYY-MM-DD, or "" to clear.'],
				'done' => ['type' => 'boolean', 'description' => 'true = ticked off, false = open again.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'step_id'],
			'additionalProperties' => false,
		];
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		$changes = [];
		foreach (self::FIELDS as $argument => $field) {
			if (array_key_exists($argument, $arguments)) {
				$changes[$field] = $arguments[$argument] === '' && in_array($field, ['assignedUserId', 'dueDate'], true)
					? null
					: $arguments[$argument];
			}
		}
		if ($changes === []) {
			return [ToolResult::error('Nothing to change.'), 0];
		}

		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}
		$belongs = array_filter(
			$this->stepRows->findForTickets([(int)$ticket->getId()]),
			static fn (Step $step): bool => (int)$step->getId() === $arguments['step_id'],
		);
		if ($belongs === []) {
			return [ToolResult::notFound(), 0];
		}

		$step = $this->steps->update($viewer, $arguments['step_id'], $changes);

		return [ToolResult::ok(['step' => AddStep::stepSummary($step)]), (int)$ticket->getId()];
	}
}
