<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;

/**
 * Der eine Lesepfad auf Boards, den REST und MCP gemeinsam nutzen.
 */
class BoardReadModel {

	public function __construct(
		private BoardMapper $boards,
		private BoardService $boardService,
		private MemberService $members,
		private ColumnMapper $columns,
		private BoardPinService $pins,
	) {
	}

	/**
	 * Alle Boards, in denen diese Person Mitglied ist, mit Pin und eigener Rolle.
	 *
	 * Ein Nichtmitglied bekommt eine leere Liste: `findAllForUser()` verbindet selbst
	 * auf `pwerk_members`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listFor(string $userId, bool $includeArchived = false): array {
		$boards = $this->boards->findAllForUser($userId, $includeArchived);
		$pinned = $this->pins->pinnedIds($userId);
		$roles = $this->members->rolesForUserBoards($userId);

		return array_map(
			static fn ($board): array => $board->jsonSerialize()
				+ ['pinned' => in_array((int)$board->getId(), $pinned, true)]
				+ ['viewerRole' => $roles[(int)$board->getId()] ?? null],
			$boards,
		);
	}

	/**
	 * Nur die Spalten, ohne Mitglieder und Projektfelder.
	 *
	 * @return \OCA\Projektwerk\Db\Column[]
	 */
	public function columns(ViewerContext $viewer): array {
		return $this->columns->findForBoard($viewer);
	}

	/**
	 * Ein Board mit Mitgliedern, Spalten und der eigenen Rolle.
	 *
	 * @return array<string, mixed>
	 */
	public function show(ViewerContext $viewer): array {
		return [
			'board' => $this->boardService->forViewerWithProjectFields($viewer),
			'members' => $this->members->listForBoard($viewer),
			'columns' => $this->columns->findForBoard($viewer),
			'viewer' => [
				'userId' => $viewer->userId,
				'role' => $viewer->role,
				'isManager' => $viewer->isManager,
				'isBoardCreator' => $viewer->isBoardCreator,
			],
			'memberBoardsAllowed' => $this->boardService->projectAllowsMemberBoards($viewer),
		];
	}
}
