<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpAudit;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\PublicGuard;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\TicketService;

class MoveTicket extends WriteTool {

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		McpRateLimiter $rateLimiter,
		McpAudit $audit,
		private TicketService $tickets,
		private TicketMapper $visible,
	) {
		parent::__construct($access, $guard, $rateLimiter, $audit);
	}

	public function name(): string {
		return 'move_ticket';
	}

	public function title(): string {
		return 'Move ticket to another column';
	}

	public function description(): string {
		return 'Moves a ticket to the end of another column (column ids from list_columns). '
			. 'Moving does not close a ticket; use close_ticket for that. '
			. 'On a customer-visible ticket, ask the user first and pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'target_column_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Column id from list_columns.'],
				'version' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Version from get_ticket, to refuse if someone changed it meanwhile.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'target_column_id'],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array {
		return ['idempotentHint' => true] + parent::annotations();
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}

		$others = array_values(array_filter(
			$this->visible->findVisibleInBoard($viewer, $arguments['target_column_id']),
			static fn (Ticket $other): bool => (int)$other->getId() !== (int)$ticket->getId(),
		));
		$last = $others === [] ? null : (int)end($others)->getId();

		$saved = $this->withVersion($ticket, $arguments, fn (int $version) => $this->tickets->move(
			$viewer,
			(int)$ticket->getId(),
			$version,
			$arguments['target_column_id'],
			$last,
			null,
		));

		return [ToolResult::ok(['ticket' => self::ticketSummary($saved)]), (int)$saved->getId()];
	}
}
