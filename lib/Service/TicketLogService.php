<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Db\TicketLogMapper;

class TicketLogService {

	private const MAX_LENGTH = 255;

	public function __construct(
		private TicketLogMapper $log,
		private TicketScope $scope,
	) {
	}

	/**
	 * Im selben Schreibvorgang aufrufen wie die Änderung, mit dem Vorgang im neuen Stand.
	 */
	public function record(
		ViewerContext $viewer,
		Ticket $ticket,
		string $action,
		?string $subject = null,
		?string $oldValue = null,
		?string $newValue = null,
	): TicketLog {
		$entry = new TicketLog();
		$entry->setTicketId((int)$ticket->getId());
		$entry->setActorUserId($viewer->userId);
		$entry->setVia($viewer->via);
		$entry->setAction($action);
		$entry->setSubject($this->cut($subject));
		$entry->setOldValue($this->cut($oldValue));
		$entry->setNewValue($this->cut($newValue));
		$entry->setVisibility((string)$ticket->getVisibility());
		$entry->setCreatedAt(new \DateTime());

		return $this->log->insert($entry);
	}

	/** @return TicketLog[] älteste zuerst; `$ticket` stammt aus findVisible() */
	public function listFor(ViewerContext $viewer, Ticket $ticket): array {
		$visible = [];
		foreach ($this->log->findForTickets([(int)$ticket->getId()]) as $entry) {
			if (!$this->wouldSee($viewer, $ticket, $entry->getVisibility())) {
				continue;
			}
			// Der alte Stand eines Sichtbarkeitswechsels verriete den Vorlauf.
			if ($entry->getAction() === TicketLog::VISIBILITY
				&& !$this->wouldSee($viewer, $ticket, (string)$entry->getOldValue())) {
				$entry->setOldValue(null);
			}
			$visible[] = $entry;
		}

		return $visible;
	}

	private function wouldSee(ViewerContext $viewer, Ticket $ticket, string $visibility): bool {
		return $this->scope->wouldSee(
			$visibility,
			(string)$ticket->getCreatorUserId(),
			(string)$ticket->getCreatorRole(),
			$viewer->userId,
			$viewer->role,
		);
	}

	private function cut(?string $value): ?string {
		return $value === null ? null : mb_substr($value, 0, self::MAX_LENGTH);
	}
}
