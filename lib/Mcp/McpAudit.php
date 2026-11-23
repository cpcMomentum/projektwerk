<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * Jede Änderung über MCP ins Audit-Log von Nextcloud, ohne Inhalte.
 */
class McpAudit {

	public function __construct(
		private IEventDispatcher $events,
	) {
	}

	public function recordVisibility(AuthenticatedCaller $caller, int $boardId, int $ticketId, string $from, string $to): void {
		$this->events->dispatchTyped(new CriticalActionPerformedEvent(
			'ProjektWerk MCP: visibility of ticket %s on board %s changed from %s to %s by %s via client %s',
			[
				'ticket' => (string)$ticketId,
				'board' => (string)$boardId,
				'from' => $from,
				'to' => $to,
				'user' => $caller->userId,
				'client' => $caller->clientId,
			],
		));
	}

	public function record(AuthenticatedCaller $caller, string $tool, int $boardId, int $ticketId): void {
		$this->events->dispatchTyped(new CriticalActionPerformedEvent(
			'ProjektWerk MCP: %s by %s on board %s ticket %s via client %s',
			[
				'tool' => $tool,
				'user' => $caller->userId,
				'board' => (string)$boardId,
				'ticket' => (string)$ticketId,
				'client' => $caller->clientId,
			],
		));
	}
}
