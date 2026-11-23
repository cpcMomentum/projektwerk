<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Comment;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Db\TicketMapper;

/**
 * Kommentare und Änderungen seit einem Zeitpunkt, neueste zuerst; dieselbe Sichtbarkeit wie im Verlauf des Vorgangs.
 */
class ActivityService {

	public const KIND_COMMENTS = 'comments';
	public const KIND_CHANGES = 'changes';
	public const KIND_ALL = 'all';

	public function __construct(
		private BoardAccess $access,
		private BoardMapper $boards,
		private TicketMapper $tickets,
		private CommentMapper $comments,
		private MemberMapper $members,
		private TicketLogService $log,
	) {
	}

	/** @return list<array<string, mixed>> neueste zuerst; wirft NotAMemberException für ein fremdes board_id */
	public function since(
		string $userId,
		\DateTimeInterface $since,
		?int $projectId = null,
		?int $boardId = null,
		string $kind = self::KIND_ALL,
		?string $side = null,
		?string $actorUserId = null,
	): array {
		$entries = [];
		foreach ($this->boardsFor($userId, $projectId, $boardId) as $board) {
			$viewer = $this->access->contextFor($userId, (int)$board->getId());
			foreach ($this->forBoard($viewer, $board, $since, $kind) as $entry) {
				if ($side !== null && $entry['actor']['side'] !== $side) {
					continue;
				}
				if ($actorUserId !== null && $entry['actor']['userId'] !== $actorUserId) {
					continue;
				}
				$entries[] = $entry;
			}
		}

		usort($entries, static fn (array $a, array $b): int => [$b['at'], $b['kind'], (int)substr($b['id'], 1)] <=> [$a['at'], $a['kind'], (int)substr($a['id'], 1)]);

		return $entries;
	}

	/** @return Board[] die abzufragenden Boards; wirft NotAMemberException für ein fremdes board_id */
	private function boardsFor(string $userId, ?int $projectId, ?int $boardId): array {
		if ($boardId !== null) {
			$this->access->contextFor($userId, $boardId);
		}

		// Ohne Eingrenzung nur Aktives; wer ein Projekt oder Board ausdrücklich nennt, bekommt es auch archiviert.
		return array_values(array_filter(
			$this->boards->findAllForUser($userId, $boardId !== null || $projectId !== null),
			static fn (Board $board): bool => ($boardId === null || (int)$board->getId() === $boardId)
				&& ($projectId === null || (int)$board->getProjectId() === $projectId),
		));
	}

	/** @return list<array<string, mixed>> */
	private function forBoard(ViewerContext $viewer, Board $board, \DateTimeInterface $since, string $kind): array {
		$tickets = [];
		foreach ($this->tickets->findVisibleInBoard($viewer) as $ticket) {
			$tickets[(int)$ticket->getId()] = $ticket;
		}
		if ($tickets === []) {
			return [];
		}
		$sides = [];
		foreach ($this->members->findForBoard($viewer) as $member) {
			$sides[(string)$member->getUserId()] = (string)$member->getRole();
		}

		$entries = [];
		if ($kind !== self::KIND_CHANGES) {
			foreach ($this->comments->findForTicketsSince(array_keys($tickets), $since) as $comment) {
				$entries[] = $this->commentEntry($comment, $tickets[(int)$comment->getTicketId()], $board, $sides);
			}
		}
		if ($kind !== self::KIND_COMMENTS) {
			foreach ($this->log->listForTickets($viewer, array_values($tickets), $since) as $change) {
				$entries[] = $this->changeEntry($change, $tickets[(int)$change->getTicketId()], $board, $sides);
			}
		}

		return $entries;
	}

	/** @param array<string, string> $sides */
	private function commentEntry(Comment $comment, Ticket $ticket, Board $board, array $sides): array {
		$author = (string)$comment->getAuthorUserId();

		return [
			'id' => 'c' . $comment->getId(),
			'at' => $comment->getCreatedAt()?->format(\DateTime::ATOM),
			'kind' => 'comment',
			'action' => 'comment',
			'ticket' => self::ticket($ticket, $board),
			'actor' => ['userId' => $author, 'side' => $sides[$author] ?? null],
			'via' => $comment->getCreatedVia() ?? 'ui',
			'text' => $comment->getBody(),
		];
	}

	/** @param array<string, string> $sides */
	private function changeEntry(TicketLog $change, Ticket $ticket, Board $board, array $sides): array {
		$actor = (string)$change->getActorUserId();

		return [
			'id' => 'l' . $change->getId(),
			'at' => $change->getCreatedAt()?->format(\DateTime::ATOM),
			'kind' => 'change',
			'action' => $change->getAction(),
			'ticket' => self::ticket($ticket, $board),
			'actor' => ['userId' => $actor, 'side' => $sides[$actor] ?? null],
			'via' => $change->getVia() ?? 'ui',
			'subject' => $change->getSubject(),
			'oldValue' => $change->getOldValue(),
			'newValue' => $change->getNewValue(),
		];
	}

	/** @return array<string, mixed> */
	private static function ticket(Ticket $ticket, Board $board): array {
		return [
			'id' => (int)$ticket->getId(),
			'number' => (int)$ticket->getNumber(),
			'title' => (string)$ticket->getTitle(),
			'visibility' => (string)$ticket->getVisibility(),
			'boardId' => (int)$board->getId(),
			'boardTitle' => (string)$board->getTitle(),
			'projectId' => (int)$board->getProjectId(),
		];
	}
}
