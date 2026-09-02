<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Db\TaskFilter;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\Server;

/**
 * **Vertraulichkeit INNERHALB eines Mehr-Board-Projekts** (#246 PR 2).
 *
 * Die Leak-Matrix beweist mit ihrem zweiten Board die Rolle *je Board* und, weil
 * jenes Board an einem **eigenen** Projekt haengt, die Vertraulichkeit *zwischen*
 * Projekten. Den Fall, um den es in #246 eigentlich geht — **mehrere Boards unter
 * demselben Projekt** — deckt sie nicht ab. Genau das ist die Luecke, die der
 * Review von PR 2 markiert hat, und genau die schliesst diese Suite.
 *
 * Der gerahmte Entwurf (von Axel entschieden): Ein Projekt umfasst mehrere Boards;
 * **Mitglieder und Sichtbarkeit sind ueber alle Boards eines Projekts identisch.**
 * Seit PR 2 joint {@see TicketScope} die Mitgliedschaft ueber `project_id`, nicht
 * mehr ueber `board_id`. Daraus folgt die Eigenschaft, die hier bewiesen wird:
 *
 * > **Eine einzige Mitgliedszeile traegt durch das ganze Projekt.** Wer im Projekt
 * > Mitglied ist, ist fuer *jedes* Board des Projekts berechtigt — auch fuer eines,
 * > dessen `board_id` in keiner seiner Mitgliedszeilen steht. Wer nicht Mitglied
 * > ist, erreicht *kein* Board des Projekts.
 *
 * Deshalb steht die Fixture bewusst **neben** der Leak-Matrix und teilt ihre Zahlen
 * nicht: Die Mitglieder bekommen **genau eine** Zeile mit `board_id` = Board A. Ihr
 * Zugriff auf Board B laeuft damit ausschliesslich ueber `project_id` — faellt der
 * Verbund auf `board_id` zurueck, sehen sie auf Board B nichts, und diese Suite
 * wird rot.
 *
 * **Zwei interne Mitglieder** (Anna, Bert), damit die Symmetrie von `internal`
 * ueber die Boardgrenze hinweg pruefbar ist: Bert — der auf Board B **keine**
 * eigene Mitgliedszeile hat — muss Annas internen Vorgang auf Board B sehen, weil
 * seine Projektrolle intern ist. Saehe er ihn nicht, waere `internal` an die
 * erzeugende Person gebunden statt an die Rolle; saehe ein Externer ihn, waere die
 * Regel ganz gebrochen.
 */
final class MultiBoardProjectLeakTest extends IntegrationTestCase {

	private const ANNA = 'mbp-anna';
	private const BERT = 'mbp-bert';
	private const CARLA = 'mbp-carla';

	/** Ein Nichtmitglied — steht in keiner Zeile von `pwerk_members`. */
	private const FREMD = 'mbp-fremd';

	/**
	 * Mitglied => [Rolle, Verwaltungsrecht]. Genau eine Zeile je Person, alle mit
	 * `board_id` = Board A. Der Zugriff auf Board B haengt allein an `project_id`.
	 */
	private const MEMBERS = [
		self::ANNA => [ViewerContext::ROLE_INTERNAL, true],
		self::BERT => [ViewerContext::ROLE_INTERNAL, false],
		self::CARLA => [ViewerContext::ROLE_EXTERNAL, false],
	];

	/** Board A: Bezeichnung => [Sichtbarkeit, Erzeuger, Erzeugerrolle]. */
	private const A_TICKETS = [
		'A:public/anna' => [TicketScope::VISIBILITY_PUBLIC, self::ANNA, ViewerContext::ROLE_INTERNAL],
		'A:private/bert' => [TicketScope::VISIBILITY_PRIVATE, self::BERT, ViewerContext::ROLE_INTERNAL],
	];

	/** Board B: dieselben Regeln, ein anderes Board desselben Projekts. */
	private const B_TICKETS = [
		'B:public/carla' => [TicketScope::VISIBILITY_PUBLIC, self::CARLA, ViewerContext::ROLE_EXTERNAL],
		'B:internal/anna' => [TicketScope::VISIBILITY_INTERNAL, self::ANNA, ViewerContext::ROLE_INTERNAL],
		'B:private/carla' => [TicketScope::VISIBILITY_PRIVATE, self::CARLA, ViewerContext::ROLE_EXTERNAL],
	];

	/**
	 * Was jeder Betrachter auf **Board B** sieht — der Kern.
	 *
	 * Anna und Bert (beide intern) sehen dasselbe, obwohl nur Anna Board B je
	 * angelegt hat und Bert dort keine Mitgliedszeile besitzt: den oeffentlichen
	 * Vorgang und Annas internen. Carla (extern) sieht den oeffentlichen und ihren
	 * eigenen privaten, aber **nicht** den internen.
	 */
	private const VISIBLE_IN_B = [
		self::ANNA => ['B:public/carla', 'B:internal/anna'],
		self::BERT => ['B:public/carla', 'B:internal/anna'],
		self::CARLA => ['B:public/carla', 'B:private/carla'],
	];

	/**
	 * Was jeder Betrachter **ueber beide Boards** sieht (Ueberblick, offen).
	 *
	 * Die Vereinigung ueber Board A und B nach derselben Regel — der Beweis, dass
	 * der projekt-scoped Verbund die Boardgrenze ueberspannt statt an ihr zu enden.
	 */
	private const VISIBLE_ACROSS = [
		self::ANNA => ['A:public/anna', 'B:public/carla', 'B:internal/anna'],
		self::BERT => ['A:public/anna', 'A:private/bert', 'B:public/carla', 'B:internal/anna'],
		self::CARLA => ['A:public/anna', 'B:public/carla', 'B:private/carla'],
	];

	private int $projectId;
	private int $boardAId;
	private int $boardBId;

	/** @var array<string, int> Bezeichnung => Ticket-ID */
	private array $ids = [];

	protected function setUp(): void {
		parent::setUp();

		$now = new \DateTime('2026-09-02 12:00:00');
		$projects = Server::get(ProjectMapper::class);
		$boards = Server::get(BoardMapper::class);
		$members = Server::get(MemberMapper::class);
		$columns = Server::get(ColumnMapper::class);
		$tickets = Server::get(TicketMapper::class);

		$project = new Project();
		$project->setTitle('Mehr-Board-Projekt');
		$project->setOwnerUserId(self::ANNA);
		$project->setArchived(0);
		$project->setOrgInternal('cpcMomentum');
		$project->setOrgExternal('Mueller Elektrotechnik');
		$project->setTicketCounter(0);
		$project->setCreatedAt($now);
		$project->setUpdatedAt($now);
		$this->projectId = (int)$projects->insert($project)->getId();

		$this->boardAId = $this->insertBoard($boards, 'Board A', $now);
		$this->boardBId = $this->insertBoard($boards, 'Board B', $now);

		// Genau **eine** Mitgliedszeile je Person, alle an Board A gebunden. Der
		// Zugriff auf Board B darf danach nur ueber `project_id` zustande kommen.
		foreach (self::MEMBERS as $userId => [$role, $isManager]) {
			$member = new Member();
			$member->setBoardId($this->boardAId);
			$member->setProjectId($this->projectId);
			$member->setUserId($userId);
			$member->setRole($role);
			$member->setIsManager($isManager ? 1 : 0);
			$member->setDisplayName(null);
			$member->setAddedBy(self::ANNA);
			$member->setAddedAt($now);
			$members->insert($member);
		}

		$columnA = $this->insertColumn($columns, $this->boardAId, $now);
		$columnB = $this->insertColumn($columns, $this->boardBId, $now);

		$this->insertTickets($tickets, self::A_TICKETS, $this->boardAId, $columnA, $now);
		$this->insertTickets($tickets, self::B_TICKETS, $this->boardBId, $columnB, $now);
	}

	private function insertBoard(BoardMapper $boards, string $title, \DateTime $now): int {
		$board = new Board();
		$board->setTitle($title);
		$board->setProjectId($this->projectId);
		$board->setOwnerUserId(self::ANNA);
		$board->setArchived(0);
		$board->setOrgInternal('cpcMomentum');
		$board->setOrgExternal('Mueller Elektrotechnik');
		$board->setCreatedAt($now);
		$board->setUpdatedAt($now);

		return (int)$boards->insert($board)->getId();
	}

	private function insertColumn(ColumnMapper $columns, int $boardId, \DateTime $now): int {
		$column = new Column();
		$column->setBoardId($boardId);
		$column->setTitle('Offen');
		$column->setPosition(0);
		$column->setColor('#0082c9');

		return (int)$columns->insert($column)->getId();
	}

	/**
	 * @param array<string, array{0: string, 1: string, 2: string}> $rows
	 */
	private function insertTickets(TicketMapper $tickets, array $rows, int $boardId, int $columnId, \DateTime $now): void {
		$number = 0;
		foreach ($rows as $label => [$visibility, $creator, $creatorRole]) {
			$number++;

			$ticket = new Ticket();
			$ticket->setBoardId($boardId);
			$ticket->setProjectId($this->projectId);
			$ticket->setColumnId($columnId);
			$ticket->setNumber($number);
			$ticket->setTitle($label);
			$ticket->setVisibility($visibility);
			$ticket->setCreatorUserId($creator);
			$ticket->setCreatorRole($creatorRole);
			$ticket->setResponsibleUserId($creator);
			$ticket->setPosition($number * 65536);
			$ticket->setVersion(1);
			$ticket->setCreatedAt($now);
			$ticket->setUpdatedAt($now);
			$this->ids[$label] = (int)$tickets->insert($ticket)->getId();
		}
	}

	/**
	 * **Der Kern: eine Projekt-Mitgliedszeile berechtigt fuer jedes Board.**
	 *
	 * `BoardAccess::contextFor` loest fuer Board B einen gueltigen Kontext auf,
	 * obwohl keine Mitgliedszeile dieser Person `board_id` = Board B traegt — die
	 * Berechtigung kommt ueber `project_id`. Der aufgeloeste Kontext nennt das
	 * Projekt und die **Projektrolle**; genau diese Rolle traegt dann die
	 * Sichtbarkeit auf Board B.
	 */
	public function testAProjectMembershipAuthorizesEverySiblingBoard(): void {
		$access = Server::get(BoardAccess::class);

		foreach (self::MEMBERS as $userId => [$role, $isManager]) {
			$context = $access->contextFor($userId, $this->boardBId);

			$this->assertSame($this->boardBId, $context->boardId, $userId . ' bekommt den Kontext von Board B.');
			$this->assertSame($this->projectId, $context->projectId, $userId . ' bekommt das Projekt des Boards.');
			$this->assertSame($role, $context->role, $userId . ' traegt seine Projektrolle auch auf Board B.');
			$this->assertSame($isManager, $context->isManager, $userId . ' behaelt sein Verwaltungsrecht projektweit.');
		}
	}

	/**
	 * **Ein Nichtmitglied wird auf JEDEM Board des Projekts abgewiesen.**
	 *
	 * Nicht nur auf dem Board, an dem die (nicht vorhandene) Mitgliedschaft
	 * haengen wuerde — auf beiden. `contextFor` wirft, weil der Verbund fuer
	 * `lm-fremd` keine Zeile findet.
	 */
	public function testANonMemberIsRefusedOnEveryBoardOfTheProject(): void {
		$access = Server::get(BoardAccess::class);

		foreach ([$this->boardAId, $this->boardBId] as $boardId) {
			try {
				$access->contextFor(self::FREMD, $boardId);
				$this->fail('Ein Nichtmitglied darf auf Board ' . $boardId . ' keinen Kontext bekommen.');
			} catch (NotAMemberException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * **Auf Board B sieht jeder genau seine Tickets** — obwohl seine
	 * Mitgliedszeile an Board A haengt.
	 *
	 * Der scharfe Fall ist Bert: Er hat auf Board B keine eigene Zeile und sieht
	 * dort trotzdem Annas internen Vorgang, weil seine Projektrolle intern ist.
	 */
	public function testEachMemberSeesExactlyTheirTicketsOnTheSiblingBoard(): void {
		$access = Server::get(BoardAccess::class);
		$tickets = Server::get(TicketMapper::class);

		foreach (self::VISIBLE_IN_B as $userId => $expected) {
			$context = $access->contextFor($userId, $this->boardBId);
			$this->assertLabels(
				$expected,
				$tickets->findVisibleInBoard($context),
				$userId . ' auf Board B',
			);
		}
	}

	/**
	 * **Der gefaelschte Kontext hilft dem Nichtmitglied nicht.**
	 *
	 * Selbst mit einem von Hand gebauten, maximal privilegierten Kontext (intern,
	 * Verwalter) auf Board B sieht `lm-fremd` nichts: `findVisibleInBoard` leitet
	 * die Mitgliedschaft ueber die `userId` im Verbund neu her und vertraut der
	 * Rolle im Kontext nicht. Die zweite Sperre, ueber die Boardgrenze hinweg.
	 */
	public function testAForgedContextStillLeaksNothingToANonMember(): void {
		$tickets = Server::get(TicketMapper::class);

		$forged = ViewerContext::forMember(
			self::FREMD,
			$this->boardBId,
			$this->projectId,
			ViewerContext::ROLE_INTERNAL,
			true,
		);

		$this->assertLabels([], $tickets->findVisibleInBoard($forged), 'Fremder mit gefaelschtem Kontext auf Board B');
	}

	/**
	 * **Die Sichtbarkeit spannt ueber alle Boards des Projekts.**
	 *
	 * Der Ueberblick ({@see TicketMapper::findVisibleAcrossBoardsAll()}) liefert
	 * jedem Mitglied die Vereinigung ueber Board A und B nach derselben Regel —
	 * ueber die eine Mitgliedszeile, die per `project_id` beide Boards traegt.
	 */
	public function testVisibilitySpansEveryBoardOfTheProject(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::VISIBLE_ACROSS as $userId => $expected) {
			$this->assertLabels(
				$expected,
				$tickets->findVisibleAcrossBoardsAll($userId, TaskFilter::openOnly()),
				$userId . ' ueber beide Boards',
			);
		}
	}

	/**
	 * **Das Nichtmitglied sieht auch ueber Boards hinweg nichts.**
	 *
	 * Der Ueberblick ist ueber die `userId` gescoped; ohne Mitgliedszeile im
	 * Projekt bleibt die Menge leer — kein Board leckt ueber diesen Pfad.
	 */
	public function testTheOverviewLeaksNothingToANonMember(): void {
		$tickets = Server::get(TicketMapper::class);

		$this->assertLabels(
			[],
			$tickets->findVisibleAcrossBoardsAll(self::FREMD, TaskFilter::openOnly()),
			'Fremder ueber beide Boards',
		);
	}

	/**
	 * Erwartete Bezeichnungen gegen die tatsaechlich gelieferten Tickets.
	 *
	 * @param string[] $expected
	 * @param Ticket[] $actual
	 */
	private function assertLabels(array $expected, array $actual, string $case): void {
		$sorted = $expected;
		sort($sorted);

		$this->assertSame($sorted, $this->labelsOf($actual), $case);
	}

	/**
	 * Bezeichnungen zu einer Menge von Tickets. Ein Ticket, das nicht zur Fixture
	 * gehoert, wird benannt statt verschluckt — es hiesse, eine Abfrage habe
	 * Fremdes geliefert.
	 *
	 * @param Ticket[] $tickets
	 * @return string[] aufsteigend sortiert
	 */
	private function labelsOf(array $tickets): array {
		$byId = array_flip($this->ids);

		$labels = [];
		foreach ($tickets as $ticket) {
			$id = (int)$ticket->getId();
			$labels[] = $byId[$id] ?? ('FREMDE-TICKET-ID(' . $id . ')');
		}
		sort($labels);

		return $labels;
	}
}
