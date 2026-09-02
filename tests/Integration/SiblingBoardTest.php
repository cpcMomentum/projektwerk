<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\NotManagerException;
use OCP\Server;

/**
 * **Ein zweites Board im selben Projekt** (#246 PR 5).
 *
 * Der Kern von „mehrere Boards pro Projekt" von der Datenseite: Ein weiteres
 * Board entsteht **ohne** neues Projekt und **ohne** neue Mitgliedszeile. Es
 * hängt am bestehenden Projekt, und weil die Mitgliedschaft seit PR 3 projekt-
 * scoped ist, teilt es sich Mitglieder, Rollen und den Nummernkreis automatisch
 * über `project_id`.
 *
 * Zwei Eigenschaften sichert diese Suite:
 *
 * 1. **Sichtbar ohne eigene Mitgliedszeile:** Das zweite Board erscheint in der
 *    Board-Liste jeder Person, die im Projekt Mitglied ist — {@see BoardMapper::findAllForUser()}
 *    verbindet über `project_id`. Ohne diese Umstellung bliebe es unsichtbar.
 * 2. **Nur Verwalter legen an:** dieselbe Regel wie bei Mitglieder- und
 *    Board-Pflege (§8).
 */
final class SiblingBoardTest extends IntegrationTestCase {

	private const ANNA = 'sib-anna';
	private const BERT = 'sib-bert';

	private BoardService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(BoardService::class);
	}

	private function viewer(string $userId, int $boardId): ViewerContext {
		return Server::get(BoardAccess::class)->contextFor($userId, $boardId);
	}

	/**
	 * **Ein zweites Board erbt das Projekt und wird in der Liste sichtbar.**
	 */
	public function testAddsASecondBoardSharingTheProjectAndMembers(): void {
		$boardA = $this->service->create(self::ANNA, 'Projekt X');
		$viewerA = $this->viewer(self::ANNA, (int)$boardA->getId());

		$boardB = $this->service->createInProject($viewerA, 'Zweites Board');

		// Dasselbe Projekt, ein anderes Board.
		$this->assertSame((int)$boardA->getProjectId(), (int)$boardB->getProjectId(), 'Das zweite Board hängt am selben Projekt.');
		$this->assertNotSame((int)$boardA->getId(), (int)$boardB->getId());

		// Beide Boards erscheinen in der Liste — obwohl Anna nur EINE
		// Mitgliedszeile (an Board A) hat.
		$ids = array_map(static fn (Board $b): int => (int)$b->getId(), Server::get(BoardMapper::class)->findAllForUser(self::ANNA));
		sort($ids);
		$this->assertSame([(int)$boardA->getId(), (int)$boardB->getId()], $ids, 'Beide Boards des Projekts sind sichtbar.');

		// Das zweite Board teilt sich die Mitglieder — Anna ist dort Mitglied,
		// ohne eine eigene Zeile an Board B.
		$viewerB = $this->viewer(self::ANNA, (int)$boardB->getId());
		$membersB = Server::get(MemberMapper::class)->findForBoard($viewerB);
		$this->assertCount(1, $membersB, 'Board B führt genau das Projektmitglied.');
		$this->assertSame(self::ANNA, (string)$membersB[0]->getUserId());
	}

	/**
	 * **Wer das Projekt nicht verwaltet, legt kein zweites Board an.**
	 */
	public function testANonManagerCannotAddASecondBoard(): void {
		$boardA = $this->service->create(self::ANNA, 'Projekt Y');
		$projectId = (int)$boardA->getProjectId();

		// Bert kommt roh in die Mitgliedertabelle — extern, kein Verwaltungsrecht.
		$bert = new Member();
		$bert->setBoardId((int)$boardA->getId());
		$bert->setProjectId($projectId);
		$bert->setUserId(self::BERT);
		$bert->setRole(ViewerContext::ROLE_EXTERNAL);
		$bert->setIsManager(0);
		$bert->setDisplayName(null);
		$bert->setAddedBy(self::ANNA);
		$bert->setAddedAt(new \DateTime());
		Server::get(MemberMapper::class)->insert($bert);

		$viewerBert = $this->viewer(self::BERT, (int)$boardA->getId());

		$this->expectException(NotManagerException::class);
		$this->service->createInProject($viewerBert, 'Verbotenes Board');
	}
}
