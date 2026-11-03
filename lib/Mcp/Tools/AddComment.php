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
use OCA\Projektwerk\Service\CommentService;

class AddComment extends WriteTool {

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		private McpRateLimiter $limits,
		McpAudit $audit,
		private CommentService $comments,
	) {
		parent::__construct($access, $guard, $limits, $audit);
	}

	public function name(): string {
		return 'add_comment';
	}

	public function title(): string {
		return 'Add comment';
	}

	public function description(): string {
		return 'Adds a comment to a ticket. It is shown as written by the user "via AI tool". '
			. 'Everyone involved is notified; @user-id mentions notify that person. '
			. 'On a customer-visible ticket the customer reads it and may get an e-mail: '
			. 'show the user the exact text, ask for confirmation, then pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000, 'description' => 'Markdown.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'body'],
			'additionalProperties' => false,
		];
	}

	protected function bucket(): string {
		return 'comment';
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}
		if ($ticket->getVisibility() === PublicGuard::VISIBILITY_PUBLIC && !$this->limits->allowWrite($caller, 'public_comment')) {
			return [ToolResult::error('Rate limit for customer-visible comments exceeded. Try again later.'), 0];
		}

		$comment = $this->comments->create($viewer, (int)$ticket->getId(), $arguments['body']);

		return [ToolResult::ok(['comment' => [
			'id' => $comment->getId(),
			'ticketId' => $comment->getTicketId(),
			'createdAt' => $comment->getCreatedAt()?->format(\DateTimeInterface::ATOM),
		]]), (int)$ticket->getId()];
	}
}
