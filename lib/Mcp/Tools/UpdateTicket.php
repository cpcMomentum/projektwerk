<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpAudit;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\PublicGuard;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\ContentLimits;
use OCA\Projektwerk\Service\TicketService;

class UpdateTicket extends WriteTool {

	private const FIELDS = [
		'title' => 'title',
		'description' => 'description',
		'responsible_user_id' => 'responsibleUserId',
		'due_date' => 'dueDate',
	];

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		McpRateLimiter $rateLimiter,
		McpAudit $audit,
		private TicketService $tickets,
	) {
		parent::__construct($access, $guard, $rateLimiter, $audit);
	}

	public function name(): string {
		return 'update_ticket';
	}

	public function title(): string {
		return 'Update ticket';
	}

	public function description(): string {
		return 'Changes title, description, responsible person or due date of a ticket. Only the fields you pass change. '
			. 'Pass an empty string for responsible_user_id or due_date to clear it. '
			. 'On a customer-visible ticket, ask the user first and pass confirm_public: true. '
			. 'Without version, the latest state is overwritten (last writer wins).';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ContentLimits::TICKET_TITLE, 'description' => 'Title.'],
				'description' => ['type' => 'string', 'maxLength' => 20000, 'description' => 'Markdown.'],
				'responsible_user_id' => ['type' => 'string', 'maxLength' => 64, 'description' => 'User id from list_members, or "" to clear.'],
				'due_date' => ['type' => 'string', 'maxLength' => 10, 'description' => 'YYYY-MM-DD, or "" to clear.'],
				'version' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Version from get_ticket, to refuse if someone changed it meanwhile.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id'],
			'additionalProperties' => false,
		];
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		$changes = [];
		foreach (self::FIELDS as $argument => $field) {
			if (array_key_exists($argument, $arguments)) {
				$changes[$field] = $field === 'responsibleUserId' && $arguments[$argument] === '' ? null : $arguments[$argument];
			}
		}
		if ($changes === []) {
			return [ToolResult::error('Nothing to change. Pass at least one of title, description, responsible_user_id, due_date.'), 0];
		}

		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}

		$saved = $this->withVersion($ticket, $arguments, fn (int $version) => $this->tickets->update($viewer, (int)$ticket->getId(), $version, $changes));

		return [ToolResult::ok(['ticket' => self::ticketSummary($saved)]), (int)$saved->getId()];
	}
}
