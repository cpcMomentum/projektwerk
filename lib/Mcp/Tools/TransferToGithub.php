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
use OCA\Projektwerk\Service\TicketService;

class TransferToGithub extends WriteTool {

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
		return 'transfer_to_github';
	}

	public function title(): string {
		return 'Transfer ticket to GitHub';
	}

	public function description(): string {
		return 'Creates a GitHub issue from the ticket in the repository configured for the project, with the user\'s own GitHub token. '
			. 'Only for the service provider side, only once per ticket. '
			. 'On a customer-visible ticket, ask the user first and pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id'],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array {
		return ['openWorldHint' => true] + parent::annotations();
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}
		if ($ticket->getGithubIssueNumber() !== null) {
			return [ToolResult::error('Ticket #' . $ticket->getNumber() . ' was already transferred: ' . $ticket->getGithubIssueUrl()), 0];
		}

		$saved = $this->tickets->transferToGithub($viewer, (int)$ticket->getId());

		return [ToolResult::ok([
			'ticket' => self::ticketSummary($saved),
			'githubIssueUrl' => $saved->getGithubIssueUrl(),
		]), (int)$saved->getId()];
	}
}
