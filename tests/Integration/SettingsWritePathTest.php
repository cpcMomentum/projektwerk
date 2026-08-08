<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\ColumnService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\NotManagerException;
use OCP\Server;

/**
 * Die Verwaltung: Projekt, Spalten, Mitglieder.
 *
 * Der rote Faden ist eine einzige Regel aus §8 — pflegen darf nur ein
 * **internes Mitglied mit Verwaltungsrecht** — und drei Stellen, an denen sie
 * gilt. Jede wird einzeln geprüft: Eine Sperre, die nur an zwei von drei
 * Stellen greift, ist keine.
 */
class SettingsWritePathTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;
	private BoardService $boardService;
	private ColumnService $columnService;
	private MemberService $memberService;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
		$this->boardService = Server::get(BoardService::class);
		$this->columnService = Server::get(ColumnService::class);
		$this->memberService = Server::get(MemberService::class);
	}

	/**
	 * **Anlegen erzeugt Board und Mitgliedschaft zusammen.**
	 *
	 * Bliebe ein Board ohne Mitglied zurück, käme mangels Admin-Ausnahme
	 * niemand mehr heran — es wäre für immer unerreichbar und ließe sich nicht
	 * einmal löschen.
	 */
	public function testCreatingABoardMakesTheCreatorAnInternalManager(): void {
		$board = $this->boardService->create('lm-neu', 'Neues Projekt', null, 'cpcMomentum', 'Kunde GmbH');

		$viewer = Server::get(BoardAccess::class)->contextFor('lm-neu', (int)$board->getId());

		$this->assertSame('lm-neu', $board->getOwnerUserId());
		$this->assertSame(ViewerContext::ROLE_INTERNAL, $viewer->role);
		$this->assertTrue($viewer->isManager, 'Wer anlegt, muss verwalten dürfen.');
		$this->assertSame('cpcMomentum', $board->getOrgInternal());
		$this->assertSame('Kunde GmbH', $board->getOrgExternal());
	}

	public function testABoardNeedsATitle(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->boardService->create('lm-neu', '   ');
	}

	/**
	 * Leere Felder werden zu `null`, nicht zu leeren Zeichenketten.
	 *
	 * Sonst müsste jede Anzeige zwei Formen von „nichts" unterscheiden — und
	 * der Knopf „Zum Projektchat" entfällt laut §9 genau dann, wenn keine
	 * Adresse hinterlegt ist.
	 */
	public function testEmptyFieldsBecomeNull(): void {
		$board = $this->boardService->update($this->manager(), ['chatUrl' => '   ', 'orgExternal' => '']);

		$this->assertNull($board->getChatUrl());
		$this->assertNull($board->getOrgExternal());
	}

	public function testUpdatingTheBoardKeepsUntouchedFields(): void {
		$before = Server::get(BoardMapper::class)->findForViewer($this->manager());

		$board = $this->boardService->update($this->manager(), ['title' => 'Umbenannt']);

		$this->assertSame('Umbenannt', $board->getTitle());
		$this->assertSame($before->getOrgInternal(), $board->getOrgInternal(), 'Nicht genanntes Feld verändert.');
	}

	public function testArchivingAndBack(): void {
		$this->assertSame(1, (int)$this->boardService->setArchived($this->manager(), true)->getArchived());
		$this->assertSame(0, (int)$this->boardService->setArchived($this->manager(), false)->getArchived());
	}

	/**
	 * Bert ist intern, aber ohne Verwaltungsrecht — an allen drei Stellen
	 * abgewiesen.
	 */
	public function testWithoutTheManagementRightEverythingIsRefused(): void {
		$bert = $this->fixture->contextFor(LeakMatrixFixture::BERT);
		$this->assertFalse($bert->isManager);

		$refused = 0;

		foreach ([
			fn () => $this->boardService->update($bert, ['title' => 'Fremd']),
			fn () => $this->boardService->setArchived($bert, true),
			fn () => $this->columnService->create($bert, 'Neue Spalte'),
			fn () => $this->columnService->rename($bert, $this->columnId(LeakMatrixFixture::COLUMN_A), 'Anders'),
			fn () => $this->columnService->reorder($bert, []),
			fn () => $this->memberService->add($bert, 'lm-neu', ViewerContext::ROLE_INTERNAL),
			fn () => $this->memberService->update($bert, LeakMatrixFixture::CARLA, ['role' => ViewerContext::ROLE_INTERNAL]),
		] as $attempt) {
			try {
				$attempt();
				$this->fail('Ein Schreibvorgang ohne Verwaltungsrecht ging durch.');
			} catch (NotManagerException) {
				$refused++;
			}
		}

		$this->assertSame(7, $refused);
	}

	/**
	 * **Ein externes Mitglied kann kein Verwalter sein — auch nicht auf dem
	 * Umweg über einen Rollenwechsel.**
	 *
	 * §8: Das Recht ist nur an interne Mitglieder vergebbar. Der Kontext
	 * entschärft ein falsch gesetztes Flag bereits beim Bauen; hier wird es gar
	 * nicht erst geschrieben. Einmal richtig schreiben ist besser als überall
	 * entschärfen.
	 */
	public function testTheManagementRightNeverSticksToAnExternalMember(): void {
		$manager = $this->manager();

		$carla = $this->memberService->update($manager, LeakMatrixFixture::CARLA, ['isManager' => true]);
		$this->assertSame(0, (int)$carla->getIsManager(), 'Extern mit Verwaltungsrecht in der Datenbank.');

		// Und der umgekehrte Weg: erst intern und Verwalter, dann extern.
		$this->memberService->update($manager, LeakMatrixFixture::CARLA, [
			'role' => ViewerContext::ROLE_INTERNAL,
			'isManager' => true,
		]);
		$demoted = $this->memberService->update($manager, LeakMatrixFixture::CARLA, [
			'role' => ViewerContext::ROLE_EXTERNAL,
		]);

		$this->assertSame(ViewerContext::ROLE_EXTERNAL, $demoted->getRole());
		$this->assertSame(0, (int)$demoted->getIsManager(), 'Das Recht hat den Rollenwechsel überlebt.');
	}

	/**
	 * Der Eigentümer behält das Verwaltungsrecht — auf beiden Wegen (§8).
	 */
	public function testTheOwnerKeepsTheManagementRight(): void {
		$manager = $this->manager();

		try {
			$this->memberService->update($manager, LeakMatrixFixture::ANNA, ['isManager' => false]);
			$this->fail('Dem Eigentümer wurde das Verwaltungsrecht entzogen.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}

		try {
			$this->memberService->update($manager, LeakMatrixFixture::ANNA, ['role' => ViewerContext::ROLE_EXTERNAL]);
			$this->fail('Der Eigentümer wurde extern — und verlöre damit dasselbe Recht.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}
	}

	public function testAddingSomebodyTwiceIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->memberService->add($this->manager(), LeakMatrixFixture::BERT, ViewerContext::ROLE_INTERNAL);
	}

	public function testUnknownRoleIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->memberService->update($this->manager(), LeakMatrixFixture::BERT, ['role' => 'kunde']);
	}

	/**
	 * Der Name an der Mitgliedschaft lässt sich setzen und wieder leeren.
	 *
	 * Leer heißt „Anzeigename aus Nextcloud", nicht „leerer Name" — sonst stünde
	 * auf der Karte gar nichts.
	 */
	public function testTheMembershipNameCanBeSetAndCleared(): void {
		$manager = $this->manager();

		$named = $this->memberService->update($manager, LeakMatrixFixture::BERT, ['displayName' => ' Bert König ']);
		$this->assertSame('Bert König', $named->getDisplayName());

		$cleared = $this->memberService->update($manager, LeakMatrixFixture::BERT, ['displayName' => '']);
		$this->assertNull($cleared->getDisplayName());
	}

	public function testColumnsAreAppendedAndRenamed(): void {
		$manager = $this->manager();

		$created = $this->columnService->create($manager, ' Wartet auf Kunde ');
		$this->assertSame('Wartet auf Kunde', $created->getTitle());
		$this->assertSame(2, (int)$created->getPosition(), 'Die neue Spalte gehört ans Ende.');

		$renamed = $this->columnService->rename($manager, (int)$created->getId(), 'Abgestimmt');
		$this->assertSame('Abgestimmt', $renamed->getTitle());
	}

	/**
	 * **Eine unvollständige Reihenfolge wird abgewiesen, nicht still ergänzt.**
	 *
	 * Sonst entschiede über die nicht genannten Spalten der Zufall, und niemand
	 * könnte erklären, warum eine Spalte gewandert ist, die niemand angefasst
	 * hat.
	 */
	public function testReorderingDemandsEveryColumn(): void {
		$manager = $this->manager();
		$a = $this->columnId(LeakMatrixFixture::COLUMN_A);
		$b = $this->columnId(LeakMatrixFixture::COLUMN_B);

		$ordered = $this->columnService->reorder($manager, [$b, $a]);
		$this->assertSame([$b, $a], array_map(static fn ($c): int => (int)$c->getId(), $ordered));
		$this->assertSame(
			[$b, $a],
			array_map(
				static fn ($c): int => (int)$c->getId(),
				Server::get(ColumnMapper::class)->findForBoard($manager),
			),
			'Die gespeicherte Reihenfolge weicht ab.',
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->columnService->reorder($manager, [$a]);
	}

	/**
	 * Ein Board hat nach dem Anlegen keine Spalten.
	 *
	 * Bewusst keine erfundene Vorgabe: Spaltennamen stehen beim Kunden auf dem
	 * Schirm, und die Produktbeschreibung nennt keine. Die Ansicht führt
	 * stattdessen zur ersten Spalte.
	 */
	public function testANewBoardStartsWithoutColumns(): void {
		$board = $this->boardService->create('lm-neu', 'Ohne Spalten');
		$viewer = Server::get(BoardAccess::class)->contextFor('lm-neu', (int)$board->getId());

		$this->assertSame([], Server::get(ColumnMapper::class)->findForBoard($viewer));
		$this->assertCount(1, Server::get(MemberMapper::class)->findForBoard($viewer));
	}

	private function manager(): ViewerContext {
		// Anna ist Eigentuemerin und interne Verwalterin.
		return $this->fixture->contextFor(LeakMatrixFixture::ANNA);
	}

	private function columnId(string $title): int {
		return $this->fixture->columnIds[$title];
	}
}
