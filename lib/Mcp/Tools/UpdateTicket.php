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
use OCA\Projektwerk\Mcp\McpConfig;
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
		private McpConfig $config,
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
		$visibility = $this->config->publicWriteAllowed()
			? 'visibility "public" makes the ticket and its attachments visible to the customer side: tell the user exactly that, ask first and pass confirm_public: true. '
			: 'visibility can only switch between "internal" and "private" here; the administrator has switched off AI changes to or from "public". ';

		return 'Changes title, description, responsible person, due date or visibility of a ticket. Only the fields you pass change. '
			. 'Pass an empty string for responsible_user_id or due_date to clear it. '
			. 'On a customer-visible ticket, ask the user first and pass confirm_public: true. '
			. $visibility
			. 'Change visibility in a call of its own, without other fields. '
			. 'Only the side that owns the ticket may change its visibility, and only the creator may make it "private". '
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
				'visibility' => ['type' => 'string', 'enum' => ['public', 'internal', 'private'], 'description' => '"public" = customer sees it, "internal" = only your side, "private" = only you.'],
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
		$visibility = $arguments['visibility'] ?? null;
		if ($changes === [] && $visibility === null) {
			return [ToolResult::error('Nothing to change. Pass at least one of title, description, responsible_user_id, due_date, visibility.'), 0];
		}
		if ($changes !== [] && $visibility !== null) {
			return [ToolResult::error('Change visibility in a call of its own, without other fields, so that either both happen or neither.'), 0];
		}

		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		$refusal ??= $visibility === null ? null : $this->guard->forTargetVisibility($ticket, $visibility, $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}

		if ($visibility === null) {
			$saved = $this->withVersion($ticket, $arguments, fn (int $version) => $this->tickets->update($viewer, (int)$ticket->getId(), $version, $changes));

			return [ToolResult::ok(['ticket' => self::ticketSummary($saved)]), (int)$saved->getId()];
		}

		$before = (string)$ticket->getVisibility();
		// Kein Wiederholen nach Konflikt: Die Prüfung des Schalters gilt nur für den gelesenen Stand.
		$saved = $this->tickets->changeVisibility($viewer, (int)$ticket->getId(), $arguments['version'] ?? (int)$ticket->getVersion(), $visibility);
		if ($before !== $visibility && in_array(PublicGuard::VISIBILITY_PUBLIC, [$before, $visibility], true)) {
			$this->audit->recordVisibility($caller, $viewer->boardId, (int)$saved->getId(), $before, $visibility);
		}

		return [ToolResult::ok(['ticket' => self::ticketSummary($saved)]), (int)$saved->getId()];
	}
}
