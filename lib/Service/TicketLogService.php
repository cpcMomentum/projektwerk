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
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Reference\IReferenceManager;

class TicketLogService {

	private const MAX_LENGTH = 255;

	/** @var array<int, true> */
	private array $pending = [];

	public function __construct(
		private TicketLogMapper $log,
		private TicketMapper $tickets,
		private TicketScope $scope,
		private IReferenceManager $references,
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
		return $this->recordFor($viewer->userId, (int)$ticket->getId(), (string)$ticket->getVisibility(), $action, $subject, $oldValue, $newValue, $viewer->via);
	}

	/**
	 * Für Wege ohne Betrachter oder geladenen Vorgang: Spalte entfernen, Mitglied entfernen, `occ`.
	 */
	public function recordFor(
		string $actorUserId,
		int $ticketId,
		string $visibility,
		string $action,
		?string $subject = null,
		?string $oldValue = null,
		?string $newValue = null,
		?string $via = null,
	): TicketLog {
		$entry = new TicketLog();
		$entry->setTicketId($ticketId);
		$entry->setActorUserId($actorUserId);
		$entry->setVia($via);
		$entry->setAction($action);
		$entry->setSubject($this->cut($subject));
		$entry->setOldValue($this->cut($oldValue));
		$entry->setNewValue($this->cut($newValue));
		$entry->setVisibility($visibility);
		$entry->setCreatedAt(new \DateTime());
		// Jede Änderung läuft hier durch; eine veraltete Vorschaukarte zeigte sonst bis zu einer Stunde den alten Stand.
		$this->references->invalidateCache((string)$entry->getTicketId());
		$this->invalidateAgainAfterCommit($ticketId);

		return $this->log->insert($entry);
	}

	/**
	 * Der Eintrag entsteht meist in einer offenen Transaktion: Wer dazwischen auflöst, legte den alten Stand wieder in den Cache.
	 */
	private function invalidateAgainAfterCommit(int $ticketId): void {
		if ($this->pending === []) {
			$this->defer($this->invalidatePending(...));
		}
		$this->pending[$ticketId] = true;
	}

	protected function defer(callable $callback): void {
		register_shutdown_function($callback);
	}

	/** Am Ende der Anfrage sind alle Transaktionen abgeschlossen. */
	public function invalidatePending(): void {
		foreach (array_keys($this->pending) as $ticketId) {
			$this->references->invalidateCache((string)$ticketId);
		}
		$this->pending = [];
	}

	/** @return TicketLog[] älteste zuerst; `$ticket` stammt aus findVisible() */
	public function listFor(ViewerContext $viewer, Ticket $ticket): array {
		return $this->listForTickets($viewer, [$ticket]);
	}

	/** @return TicketLog[] je Vorgang älteste zuerst; die Vorgänge stammen aus der gefilterten Menge */
	public function listForTickets(ViewerContext $viewer, array $tickets, ?\DateTimeInterface $since = null): array {
		$byId = [];
		foreach ($tickets as $ticket) {
			$byId[(int)$ticket->getId()] = $ticket;
		}
		$visible = [];
		$partner = [];
		$entries = $since === null ? $this->log->findForTickets(array_keys($byId)) : $this->log->findForTicketsSince(array_keys($byId), $since);
		foreach ($entries as $entry) {
			$ticket = $byId[(int)$entry->getTicketId()] ?? null;
			if ($ticket === null || !$this->wouldSee($viewer, $ticket, $entry->getVisibility())) {
				continue;
			}
			if (in_array($entry->getAction(), TicketLog::DEPENDENCY_ACTIONS, true) && !$this->namePartner($viewer, $entry, $partner)) {
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

	/**
	 * Den anderen Vorgang nur nennen, wenn der Betrachter ihn heute sieht und ihn beim Eintrag schon gesehen hätte; sonst verriete der Eintrag seinen verborgenen Vorlauf.
	 *
	 * @param array<int, ?Ticket> $partner Ergebnisse dieses Durchlaufs, je Vorgang nur eine Abfrage.
	 */
	private function namePartner(ViewerContext $viewer, TicketLog $entry, array &$partner): bool {
		$id = (int)$entry->getNewValue();
		if (!array_key_exists($id, $partner)) {
			try {
				$partner[$id] = $this->tickets->findVisible($viewer, $id);
			} catch (DoesNotExistException) {
				$partner[$id] = null;
			}
		}
		$ticket = $partner[$id];
		if ($ticket === null || !$this->wouldSee($viewer, $ticket, (string)$entry->getOldValue())) {
			return false;
		}

		$entry->setSubject('#' . $ticket->getNumber() . ' ' . $ticket->getTitle());
		$entry->setOldValue(null);

		return true;
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
