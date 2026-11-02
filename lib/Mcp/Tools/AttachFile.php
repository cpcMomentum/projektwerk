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
use OCA\Projektwerk\Service\AttachmentService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class AttachFile extends WriteTool {

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		McpRateLimiter $rateLimiter,
		McpAudit $audit,
		private AttachmentService $attachments,
		private IRootFolder $root,
	) {
		parent::__construct($access, $guard, $rateLimiter, $audit);
	}

	public function name(): string {
		return 'attach_file';
	}

	public function title(): string {
		return 'Attach a file from Nextcloud';
	}

	public function description(): string {
		return 'Copies a file from the user\'s own Nextcloud (path relative to their files, e.g. "Documents/offer.pdf") '
			. 'into the ticket\'s project folder and attaches it. Only files the user can access. No size limit. '
			. 'On a customer-visible ticket the customer can open it, so ask the user first and pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000, 'description' => 'Path in the user\'s Nextcloud files.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'path'],
			'additionalProperties' => false,
		];
	}

	protected function bucket(): string {
		return 'attachment';
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}

		try {
			$source = $this->root->getUserFolder($caller->userId)->get(ltrim($arguments['path'], '/'));
		} catch (NotFoundException) {
			return [ToolResult::error('No file at this path in your Nextcloud.'), 0];
		}
		if (!$source instanceof File || !$source->isReadable()) {
			return [ToolResult::error('This path is not a readable file.'), 0];
		}

		$stream = $source->fopen('rb');
		if ($stream === false) {
			return [ToolResult::error('The file could not be read.'), 0];
		}
		try {
			$attachment = $this->attachments->create($viewer, (int)$ticket->getId(), $source->getName(), $stream);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}

		return [ToolResult::ok(['attachment' => AddAttachment::attachmentSummary($attachment)]), (int)$ticket->getId()];
	}
}
