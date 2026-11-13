<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;

/**
 * Kundensichtbares nur mit Admin-Schalter und `confirm_public: true`; verschärft nur, erlaubt nie.
 */
class PublicGuard {

	public const VISIBILITY_PUBLIC = 'public';

	public function __construct(
		private TicketMapper $tickets,
		private McpConfig $config,
	) {
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return array{0: Ticket, 1: ?ToolResult}
	 */
	public function forTicket(ViewerContext $viewer, int $ticketId, array $arguments): array {
		$ticket = $this->tickets->findVisible($viewer, $ticketId);

		return [$ticket, $this->refusal((string)$ticket->getVisibility(), 'Ticket #' . $ticket->getNumber(), $arguments)];
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	public function forNewTicket(string $visibility, array $arguments): ?ToolResult {
		return $this->refusal($visibility, 'The new ticket', $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	public static function confirmed(array $arguments): bool {
		return ($arguments['confirm_public'] ?? false) === true;
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	private function refusal(string $visibility, string $label, array $arguments): ?ToolResult {
		if ($visibility !== self::VISIBILITY_PUBLIC) {
			return null;
		}
		if (!$this->config->publicWriteAllowed()) {
			return ToolResult::error($label . ' is customer-visible, and the administrator has switched off AI writes to customer-visible tickets. '
				. 'Tell the user to make this change in ProjektWerk directly. Changes that touch only internal or private tickets still work.');
		}
		if (self::confirmed($arguments)) {
			return null;
		}

		return ToolResult::error($label . ' is customer-visible: the customer sees this change and may get an e-mail. '
			. 'Show the user exactly what you are about to do and ask for confirmation. '
			. 'Only after the user agrees, call again with confirm_public: true.');
	}
}
