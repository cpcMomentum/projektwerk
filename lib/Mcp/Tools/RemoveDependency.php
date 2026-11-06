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
use OCA\Projektwerk\Service\TicketDependencyService;
use OCA\Projektwerk\Service\TicketReadModel;

class RemoveDependency extends WriteTool {

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		McpRateLimiter $rateLimiter,
		McpAudit $audit,
		private TicketDependencyService $dependencies,
	) {
		parent::__construct($access, $guard, $rateLimiter, $audit);
	}

	public function name(): string {
		return 'remove_dependency';
	}

	public function title(): string {
		return 'Remove dependency';
	}

	public function description(): string {
		return 'Removes the link that ticket_id waits for depends_on_id. Both tickets stay as they are. '
			. 'If either ticket is customer-visible, ask the user first and pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The waiting ticket, id from list_tickets or get_ticket.'],
				'depends_on_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The ticket it waits for, id from list_tickets or get_ticket.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'depends_on_id'],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array {
		return ['destructiveHint' => true] + parent::annotations();
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		// Beide Seiten zeigen die Verknüpfung an, also zählt jede kundensichtbare.
		foreach ([$arguments['ticket_id'], $arguments['depends_on_id']] as $id) {
			[, $refusal] = $this->guard->forTicket($viewer, $id, $arguments);
			if ($refusal !== null) {
				return [$refusal, 0];
			}
		}

		$links = $this->dependencies->remove($viewer, $arguments['ticket_id'], $arguments['depends_on_id']);

		return [ToolResult::ok([
			'ticketId' => $arguments['ticket_id'],
			'dependsOn' => TicketReadModel::linkSummaries($links['dependsOn']),
			'neededBy' => TicketReadModel::linkSummaries($links['neededBy']),
		]), $arguments['ticket_id']];
	}
}
