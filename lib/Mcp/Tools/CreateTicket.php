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

class CreateTicket extends WriteTool {

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
		return 'create_ticket';
	}

	public function title(): string {
		return 'Create ticket';
	}

	public function description(): string {
		return 'Creates a ticket in a column. visibility has no default and must be chosen deliberately: '
			. '"public" = the customer sees it, "internal" = only your side, "private" = only you. '
			. 'A public ticket needs confirm_public: true after asking the user. '
			. 'responsible_user_id comes from list_members; the person must be able to see the ticket.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'column_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Column id from list_columns.'],
				'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ContentLimits::TICKET_TITLE, 'description' => 'Title.'],
				'description' => ['type' => 'string', 'maxLength' => 20000, 'description' => 'Markdown.'],
				'visibility' => ['type' => 'string', 'enum' => ['public', 'internal', 'private'], 'description' => '"public" = customer sees it, "internal" = only your side, "private" = only you.'],
				'responsible_user_id' => ['type' => 'string', 'maxLength' => 64, 'description' => 'User id from list_members.'],
				'due_date' => ['type' => 'string', 'maxLength' => 10, 'description' => 'YYYY-MM-DD.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'column_id', 'title', 'visibility'],
			'additionalProperties' => false,
		];
	}

	protected function bucket(): string {
		return 'create_ticket';
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		$refusal = $this->guard->forNewTicket($arguments['visibility'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}

		$ticket = $this->tickets->create(
			$viewer,
			$arguments['title'],
			$arguments['description'] ?? null,
			$arguments['visibility'],
			$arguments['column_id'],
			$arguments['responsible_user_id'] ?? null,
			$arguments['due_date'] ?? null,
		);

		return [ToolResult::ok(['ticket' => self::ticketSummary($ticket)]), (int)$ticket->getId()];
	}
}
