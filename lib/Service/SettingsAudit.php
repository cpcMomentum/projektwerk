<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/** Wer welche Einstellung geändert hat, ins Audit-Log und ins Nextcloud-Log; nie die Werte selbst. */
class SettingsAudit {

	public function __construct(
		private IEventDispatcher $events,
		private LoggerInterface $logger,
	) {
	}

	/** @param list<string> $fields Namen der geänderten Felder. */
	public function record(?string $userId, string $what, array $fields = []): void {
		$user = $userId ?? '-';
		// admin_audit verwirft die Zeile, wenn ein Parameter null ist.
		$list = $fields === [] ? '-' : implode(', ', $fields);

		$this->events->dispatchTyped(new CriticalActionPerformedEvent(
			'ProjektWerk: %s by %s, fields: %s',
			['what' => $what, 'user' => $user, 'fields' => $list],
		));
		$this->logger->warning('ProjektWerk: {what} by {user}, fields: {fields}', [
			'app' => Application::APP_ID,
			'what' => $what,
			'user' => $user,
			'fields' => $list,
		]);
	}
}
