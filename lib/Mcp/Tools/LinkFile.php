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
use OCA\Projektwerk\Service\FileLinkService;

class LinkFile extends WriteTool {

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		McpRateLimiter $rateLimiter,
		McpAudit $audit,
		private FileLinkService $links,
	) {
		parent::__construct($access, $guard, $rateLimiter, $audit);
	}

	public function name(): string {
		return 'link_file';
	}

	public function title(): string {
		return 'Link a file or folder';
	}

	public function description(): string {
		return 'Links a file or folder from the user\'s own Nextcloud (path relative to their files, e.g. "Projects/Customer A/Drafts") '
			. 'to the ticket. Nothing is copied or moved: the link points to the original location and survives renames. '
			. 'Each person only sees links to files and folders they can open in Nextcloud. Linking the same target twice returns the existing link. '
			. 'get_ticket lists the links with path and url.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000, 'description' => 'Path of the file or folder in the user\'s Nextcloud files.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'path'],
			'additionalProperties' => false,
		];
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}

		return [ToolResult::ok(['link' => $this->links->link($viewer, (int)$ticket->getId(), $arguments['path'])]), (int)$ticket->getId()];
	}
}
