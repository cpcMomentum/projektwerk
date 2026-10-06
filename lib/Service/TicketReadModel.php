<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ChangeHighlighter;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Access\WaitStateCalculator;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketReadMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Der eine Lesepfad auf Vorgänge, den REST und MCP gemeinsam nutzen.
 *
 * Keine Berechtigungslogik: Jede Menge kommt aus einem `TicketMapper`-Pfad mit
 * `ViewerContext`, Kinder nur über die bereits gefilterte ID-Menge.
 */
class TicketReadModel {

	public function __construct(
		private TicketMapper $tickets,
		private CommentMapper $comments,
		private StepMapper $steps,
		private AttachmentMapper $attachments,
		private TicketUserMapper $ticketUsers,
		private TicketReadMapper $reads,
		private AttachmentService $attachmentService,
		private WaitStateCalculator $waitState,
		private ChangeHighlighter $highlighter,
	) {
	}

	/**
	 * Die sichtbaren Tickets eines Boards; Zähler aus derselben gefilterten ID-Menge.
	 *
	 * @return array{tickets: Ticket[], waiting: array<int, mixed>, counts: array<string, array<int, int>>, changed: array<int, true>}
	 */
	public function index(ViewerContext $viewer, ?int $columnId = null): array {
		$tickets = $this->tickets->findVisibleInBoard($viewer, $columnId);
		$ids = array_map(static fn (Ticket $ticket): int => (int)$ticket->getId(), $tickets);
		$steps = $this->steps->findForTickets($ids);

		return [
			'tickets' => $tickets,
			// Aus denselben Schritten wie die Zähler, keine zweite Abfrage.
			'waiting' => $this->waitState->forTickets($tickets, $steps),
			'counts' => [
				'comments' => $this->comments->countForTickets($ids),
				'steps' => $this->steps->countForTickets($ids),
				'stepsDone' => $this->doneCounts($steps),
				'attachments' => $this->attachments->countForTickets($ids),
				'collaborators' => $this->ticketUsers->countForTickets($ids),
			],
			'changed' => $this->changedSince($viewer, $tickets, $ids),
		];
	}

	/**
	 * Ein Ticket mit seinen Kindern, geladen über die Einermenge.
	 *
	 * @throws DoesNotExistException verborgen, gelöscht oder nie angelegt — nicht unterscheidbar
	 * @return array<string, mixed>
	 */
	public function show(ViewerContext $viewer, int $ticketId): array {
		return $this->detailFor($viewer, $this->tickets->findVisible($viewer, $ticketId));
	}

	/**
	 * Wie {@see show()}, über die Vorgangsnummer, die Menschen (und Modelle) nennen.
	 *
	 * @throws DoesNotExistException
	 * @return array<string, mixed>
	 */
	public function showByNumber(ViewerContext $viewer, int $number): array {
		return $this->detailFor($viewer, $this->tickets->findVisibleByNumber($viewer, $number));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function detailFor(ViewerContext $viewer, Ticket $ticket): array {
		$ids = [(int)$ticket->getId()];
		$steps = $this->steps->findForTickets($ids);

		return [
			'ticket' => $ticket,
			'waiting' => $this->waitState->forTicket($ticket, $steps),
			'comments' => $this->comments->findForTickets($ids),
			'steps' => $steps,
			'attachments' => $this->attachmentService->withPresence($viewer, $this->attachments->findForTickets($ids)),
			'collaborators' => $this->ticketUsers->findForTickets($ids),
		];
	}

	/**
	 * Erledigte je Ticket aus derselben Schrittmenge wie die Gesamtzahl.
	 *
	 * @param \OCA\Projektwerk\Db\Step[] $steps
	 * @return array<int, int>
	 */
	private function doneCounts(array $steps): array {
		$done = [];
		foreach ($steps as $step) {
			$ticketId = (int)$step->getTicketId();
			$done[$ticketId] ??= 0;
			if ($step->isDone()) {
				$done[$ticketId]++;
			}
		}

		return $done;
	}

	/**
	 * „Seit deinem Blick geändert"; die Regel steht im {@see ChangeHighlighter}.
	 *
	 * @param Ticket[] $tickets
	 * @param int[] $ids
	 * @return array<int, true> Nur die hervorzuhebenden Vorgänge.
	 */
	private function changedSince(ViewerContext $viewer, array $tickets, array $ids): array {
		return $this->highlighter->detect(
			$tickets,
			$this->reads->findSeenForTickets($viewer->userId, $ids),
			$this->comments->findNewestForTickets($ids),
			$viewer->userId,
		);
	}
}
