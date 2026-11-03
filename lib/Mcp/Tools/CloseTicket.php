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

class CloseTicket extends WriteTool {

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
		return 'close_ticket';
	}

	public function title(): string {
		return 'Close ticket';
	}

	public function description(): string {
		return 'Closes a ticket as "done" or "discarded". It moves into the matching end column, if the board has one, '
			. 'and everyone involved is notified, on a customer-visible ticket the customer too. '
			. 'On a customer-visible ticket, ask the user first and pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'outcome' => ['type' => 'string', 'enum' => ['done', 'discarded'], 'description' => 'How the ticket ended.'],
				'version' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Version from get_ticket, to refuse if someone changed it meanwhile.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'outcome'],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array {
		return ['destructiveHint' => true] + parent::annotations();
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}
		if ($ticket->getClosedAt() !== null) {
			return [ToolResult::error('Ticket #' . $ticket->getNumber() . ' is already closed.'), 0];
		}

		$saved = $this->withVersion($ticket, $arguments, fn (int $version) => $this->tickets->update(
			$viewer,
			(int)$ticket->getId(),
			$version,
			['closed' => true, 'outcome' => $arguments['outcome']],
		));

		return [ToolResult::ok(['ticket' => self::ticketSummary($saved)]), (int)$saved->getId()];
	}
}
