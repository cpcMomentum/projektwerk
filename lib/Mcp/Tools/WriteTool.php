<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpAudit;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\PublicGuard;
use OCA\Projektwerk\Mcp\Tool;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Service\ConflictException;
use OCA\Projektwerk\Service\GithubTransferException;
use OCA\Projektwerk\Service\NoFolderException;
use OCA\Projektwerk\Service\NotOwningSideException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\InvalidPathException;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;

/**
 * Gemeinsamer Ablauf aller Schreibwerkzeuge: Budget, Betrachter, Sperre, Dienst, Audit.
 */
abstract class WriteTool implements Tool {

	public const CONFIRM_PUBLIC = [
		'type' => 'boolean',
		'description' => 'Set to true only after the user explicitly confirmed a change to a customer-visible ticket.',
	];

	public function __construct(
		protected BoardAccess $access,
		protected PublicGuard $guard,
		private McpRateLimiter $rateLimiter,
		private McpAudit $audit,
	) {
	}

	/** @return key-of<McpRateLimiter::WRITE_LIMITS> */
	protected function bucket(): string {
		return 'write';
	}

	public function annotations(): array {
		return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	public function call(AuthenticatedCaller $caller, array $arguments): ToolResult {
		if (!$this->rateLimiter->allowWrite($caller, $this->bucket())) {
			return ToolResult::error('Rate limit for this kind of change exceeded. Try again later.');
		}

		try {
			$viewer = $this->access->contextFor($caller->userId, $arguments['board_id'])->viaTool();
			[$result, $ticketId] = $this->write($caller, $viewer, $arguments);
		} catch (NotAMemberException|DoesNotExistException) {
			return ToolResult::notFound();
		} catch (ConflictException) {
			return ToolResult::error('Someone changed this ticket at the same time. Read it again with get_ticket and retry.');
		} catch (NotOwningSideException|GithubTransferException|NoFolderException|NotPermittedException|\InvalidArgumentException $e) {
			return ToolResult::error($e->getMessage());
		} catch (LockedException|NotEnoughSpaceException|InvalidPathException) {
			return ToolResult::error('The file could not be stored in the project folder.');
		}

		if (!$result->isError()) {
			$this->audit->record($caller, $this->name(), $viewer->boardId, $ticketId);
		}

		return $result;
	}

	/** @return array{0: ToolResult, 1: int} Ergebnis und betroffener Vorgang (0 = keiner) */
	abstract protected function write(AuthenticatedCaller $caller, ViewerContext $viewer, array $arguments): array;

	/** Ohne mitgegebene Version: aktuelle Version, bei Konflikt genau ein zweiter Versuch. */
	protected function withVersion(Ticket $ticket, array $arguments, callable $change): Ticket {
		if (isset($arguments['version'])) {
			return $change($arguments['version']);
		}

		try {
			return $change((int)$ticket->getVersion());
		} catch (ConflictException $e) {
			return $change((int)$e->current->getVersion());
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	protected static function ticketSummary(Ticket $ticket): array {
		return [
			'id' => $ticket->getId(),
			'number' => $ticket->getNumber(),
			'title' => $ticket->getTitle(),
			'columnId' => $ticket->getColumnId(),
			'visibility' => $ticket->getVisibility(),
			'responsibleUserId' => $ticket->getResponsibleUserId(),
			'dueDate' => $ticket->getDueDate()?->format('Y-m-d'),
			'closedOutcome' => $ticket->getClosedOutcome(),
			'version' => $ticket->getVersion(),
		];
	}
}
