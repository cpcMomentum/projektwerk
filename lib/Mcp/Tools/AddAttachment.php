<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpAudit;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\PublicGuard;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\AttachmentService;

class AddAttachment extends WriteTool {

	public function __construct(
		BoardAccess $access,
		PublicGuard $guard,
		McpRateLimiter $rateLimiter,
		McpAudit $audit,
		private AttachmentService $attachments,
	) {
		parent::__construct($access, $guard, $rateLimiter, $audit);
	}

	public function name(): string {
		return 'add_attachment';
	}

	public function title(): string {
		return 'Attach a file you provide';
	}

	public function description(): string {
		return 'Attaches a file whose content you pass directly: text as is (encoding "text"), '
			. 'binary files such as PDF or images base64-encoded (encoding "base64"). The whole request is limited to 1 MB, '
			. 'so binary files up to about 700 KB. For larger files or files already in Nextcloud use attach_file. '
			. 'The file lands in the ticket\'s project folder; on a customer-visible ticket the customer can open it, '
			. 'so ask the user first and pass confirm_public: true.';
	}

	public function inputSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Board id from list_boards.'],
				'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ticket id from list_tickets or get_ticket.'],
				'file_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'File name with extension, e.g. "offer.pdf".'],
				'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000000, 'description' => 'File content, as text or base64 (see encoding).'],
				'encoding' => ['type' => 'string', 'enum' => ['text', 'base64'], 'description' => '"text" for plain text files, "base64" for binary files.'],
				'confirm_public' => self::CONFIRM_PUBLIC,
			],
			'required' => ['board_id', 'ticket_id', 'file_name', 'content', 'encoding'],
			'additionalProperties' => false,
		];
	}

	protected function bucket(): string {
		return 'attachment';
	}

	protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array {
		$name = self::safeName($arguments['file_name']);
		if ($name === null) {
			return [ToolResult::error('file_name must be a plain file name without a path.'), 0];
		}
		$content = $arguments['encoding'] === 'base64'
			? base64_decode($arguments['content'], true)
			: $arguments['content'];
		if ($content === false) {
			return [ToolResult::error('content is not valid base64.'), 0];
		}

		[$ticket, $refusal] = $this->guard->forTicket($viewer, $arguments['ticket_id'], $arguments);
		if ($refusal !== null) {
			return [$refusal, 0];
		}

		$attachment = $this->attachments->create($viewer, (int)$ticket->getId(), $name, $content);

		return [ToolResult::ok(['attachment' => self::attachmentSummary($attachment)]), (int)$ticket->getId()];
	}

	public static function safeName(string $name): ?string {
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
			return null;
		}

		return $name;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function attachmentSummary(Attachment $attachment): array {
		return [
			'id' => $attachment->getId(),
			'ticketId' => $attachment->getTicketId(),
			'fileName' => $attachment->getFileName(),
			'filePath' => $attachment->getFilePath(),
		];
	}
}
