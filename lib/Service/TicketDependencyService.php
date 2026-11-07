<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketDep;
use OCA\Projektwerk\Db\TicketDepMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Abhängigkeiten zwischen Vorgängen eines Boards; beide Seiten müssen für den Handelnden sichtbar sein.
 */
class TicketDependencyService {

	public function __construct(
		private TicketMapper $tickets,
		private TicketDepMapper $deps,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/**
	 * `$ticketId` wartet künftig auf `$dependsOnId`; ein schon bestehendes Paar ist kein Fehler.
	 *
	 * @throws DoesNotExistException einer der beiden Vorgänge ist nicht sichtbar oder liegt in einem anderen Board
	 * @throws \InvalidArgumentException Selbstbezug oder direkte Gegenrichtung
	 * @return array{ticket: Ticket, dependsOn: Ticket[], neededBy: Ticket[]}
	 */
	public function add(ViewerContext $viewer, int $ticketId, int $dependsOnId): array {
		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$this->tickets->findVisible($viewer, $dependsOnId);

		if ($ticketId === $dependsOnId) {
			throw new \InvalidArgumentException($this->l10n->t('Ein Vorgang kann nicht von sich selbst abhängen.'));
		}
		foreach ($this->tickets->findVisibleDependents($viewer, $ticketId) as $waiting) {
			if ((int)$waiting->getId() === $dependsOnId) {
				throw new \InvalidArgumentException($this->l10n->t('Dieser Vorgang wartet bereits auf den anderen; beide können nicht aufeinander warten.'));
			}
		}

		// Vorab prüfen statt nur am Unique-Index scheitern: Postgres bricht sonst eine umgebende Transaktion ab.
		foreach ($this->tickets->findVisibleDependencies($viewer, $ticketId) as $existing) {
			if ((int)$existing->getId() === $dependsOnId) {
				return $this->linksOf($viewer, $ticket);
			}
		}

		$dep = new TicketDep();
		$dep->setBoardId($viewer->boardId);
		$dep->setTicketId($ticketId);
		$dep->setDependsOnId($dependsOnId);
		$dep->setCreatedBy($viewer->userId);
		$dep->setCreatedVia($viewer->via);
		$dep->setCreatedAt($this->time->getDateTime());
		$this->deps->insertPair($dep);

		return $this->linksOf($viewer, $ticket);
	}

	/**
	 * @throws DoesNotExistException der wartende Vorgang ist nicht sichtbar
	 * @return array{ticket: Ticket, dependsOn: Ticket[], neededBy: Ticket[]}
	 */
	public function remove(ViewerContext $viewer, int $ticketId, int $dependsOnId): array {
		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$this->tickets->findVisible($viewer, $dependsOnId);
		$this->deps->deletePair($ticketId, $dependsOnId);

		return $this->linksOf($viewer, $ticket);
	}

	/**
	 * @return array{ticket: Ticket, dependsOn: Ticket[], neededBy: Ticket[]}
	 */
	private function linksOf(ViewerContext $viewer, Ticket $ticket): array {
		$id = (int)$ticket->getId();

		return [
			'ticket' => $ticket,
			'dependsOn' => $this->tickets->findVisibleDependencies($viewer, $id),
			'neededBy' => $this->tickets->findVisibleDependents($viewer, $id),
		];
	}
}
