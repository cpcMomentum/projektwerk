<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TaskFilter;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCA\Projektwerk\Tests\ReadPathRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;
use ReflectionClass;

/**
 * Die Leak-Matrix: jeder Lesepfad, gefahren von jedem Betrachter, verglichen
 * gegen eine erwartete Menge.
 *
 * **Die Erwartungen stehen als Literale in dieser Datei.** Sie werden nicht aus
 * der Fixture abgeleitet und schon gar nicht aus der Sichtbarkeitsregel — sonst
 * pruefte die Matrix den Code gegen eine zweite Umsetzung desselben Denkfehlers.
 * Wer eine Erwartung anpassen muss, weil der Code sich geaendert hat, aendert
 * damit sichtbar eine Zusage.
 *
 * Fuenf Betrachter, nicht vier: Zu den vier Mitgliedern kommt ein
 * **Nichtmitglied mit selbst gebautem Kontext**, an `BoardAccess` vorbei. Bei
 * den Ticketpfaden ist das die Probe auf die zweite, unabhaengige Sperre (der
 * INNER JOIN in `TicketScope`). Bei Board, Mitgliedern und Spalten faellt sie
 * anders aus — siehe
 * {@see testBoardMetadataPathsTrustTheContextAlone()}.
 *
 * Die Zuordnung „welcher Test deckt welchen Lesepfad" steht in
 * {@see COVERAGE} und wird selbst geprueft. Damit ist die Kette geschlossen:
 * `ReadPathCompletenessTest` erzwingt, dass jeder Lesepfad in der Registry
 * steht; {@see testTheMatrixCoversEveryRegisteredPath()} erzwingt, dass jeder
 * Eintrag der Registry hier auch wirklich gefahren wird.
 */
class LeakMatrixTest extends IntegrationTestCase {

	private const ANNA = LeakMatrixFixture::ANNA;
	private const BERT = LeakMatrixFixture::BERT;
	private const CARLA = LeakMatrixFixture::CARLA;
	private const DIRK = LeakMatrixFixture::DIRK;
	private const FREMD = LeakMatrixFixture::FREMD;

	/**
	 * Was jeder Betrachter im Board sehen darf. Die Kernzusage des Produkts.
	 *
	 * Anna und Bert sehen beide sechs Tickets — aber **nicht dieselben**. Genau
	 * deshalb vergleicht die Matrix Mengen und nicht Anzahlen: Ein Zaehlvergleich
	 * haette hier nichts gemerkt.
	 *
	 * @var array<string, string[]>
	 */
	private const VISIBLE = [
		self::ANNA => ['public/anna', 'public/bert', 'public/carla', 'internal/anna', 'internal/bert', 'private/anna'],
		self::BERT => ['public/anna', 'public/bert', 'public/carla', 'internal/anna', 'internal/bert', 'private/bert'],
		self::CARLA => ['public/anna', 'public/bert', 'public/carla', 'internal/carla', 'private/carla'],
		self::DIRK => ['public/anna', 'public/bert', 'public/carla', 'internal/carla'],
		self::FREMD => [],
	];

	/** @var array<string, string[]> */
	private const VISIBLE_IN_COLUMN_A = [
		self::ANNA => ['public/anna', 'public/carla', 'internal/bert', 'private/anna'],
		self::BERT => ['public/anna', 'public/carla', 'internal/bert'],
		self::CARLA => ['public/anna', 'public/carla', 'private/carla'],
		self::DIRK => ['public/anna', 'public/carla'],
		self::FREMD => [],
	];

	/** @var array<string, string[]> */
	private const VISIBLE_IN_COLUMN_B = [
		self::ANNA => ['public/bert', 'internal/anna'],
		self::BERT => ['public/bert', 'internal/anna', 'private/bert'],
		self::CARLA => ['public/bert', 'internal/carla'],
		self::DIRK => ['public/bert', 'internal/carla'],
		self::FREMD => [],
	];

	/**
	 * „Meine Aufgaben", nur Offenes.
	 *
	 * Dirk ist auf allen neun Tickets als Mitarbeiter eingetragen. Ohne die
	 * Sichtbarkeitsregel stuenden hier neun — mit ihr drei: die beiden offenen
	 * oeffentlichen und das interne der Kundenseite. Annas privates Ticket, an
	 * dem er nachweislich mitarbeitet, bleibt verborgen.
	 *
	 * @var array<string, string[]>
	 */
	private const MY_TASKS_OPEN = [
		self::ANNA => ['public/anna', 'internal/anna', 'private/anna'],
		self::BERT => ['public/bert', 'internal/bert', 'private/bert'],
		self::CARLA => ['internal/carla', 'private/carla'],
		self::DIRK => ['public/anna', 'public/bert', 'internal/carla'],
		self::FREMD => [],
	];

	/**
	 * Dasselbe mit geschlossenen Tickets. Der Unterschied ist genau
	 * `public/carla` — Carla sieht es als Erzeugerin, Dirk als Mitarbeiter.
	 *
	 * @var array<string, string[]>
	 */
	private const MY_TASKS_WITH_CLOSED = [
		self::ANNA => ['public/anna', 'internal/anna', 'private/anna'],
		self::BERT => ['public/bert', 'internal/bert', 'private/bert'],
		self::CARLA => ['public/carla', 'internal/carla', 'private/carla'],
		self::DIRK => ['public/anna', 'public/bert', 'public/carla', 'internal/carla'],
		self::FREMD => [],
	];

	/**
	 * Welcher Test deckt welchen Lesepfad.
	 *
	 * @var array<string, string>
	 */
	private const COVERAGE = [
		'TicketMapper::findVisibleInBoard' => 'testEveryViewerSeesExactlyTheirTickets',
		'TicketMapper::findVisible' => 'testSingleTicketAccessMatchesTheSameSet',
		'TicketMapper::findVisibleAcrossBoards' => 'testMyTasksNeverWidensBeyondTheVisibleSet',
		'TicketMapper::countVisibleInBoard' => 'testCountersNeverCountWhatIsHidden',
		'BoardMapper::findForViewer' => 'testBoardMetadataPathsTrustTheContextAlone',
		'BoardMapper::findAllForUser' => 'testBoardListFollowsMembership',
		'MemberMapper::findForBoard' => 'testBoardMetadataPathsTrustTheContextAlone',
		'ColumnMapper::findForBoard' => 'testBoardMetadataPathsTrustTheContextAlone',
		'CommentMapper::findForTickets' => 'testChildrenFollowTheFilteredTicketSet',
		'CommentMapper::countForTickets' => 'testChildCountersFollowTheFilteredTicketSet',
		'StepMapper::findForTickets' => 'testChildrenFollowTheFilteredTicketSet',
		'StepMapper::countForTickets' => 'testChildCountersFollowTheFilteredTicketSet',
		'AttachmentMapper::findForTickets' => 'testChildrenFollowTheFilteredTicketSet',
		'AttachmentMapper::countForTickets' => 'testChildCountersFollowTheFilteredTicketSet',
		'TicketUserMapper::findForTickets' => 'testChildrenFollowTheFilteredTicketSet',
		'TicketUserMapper::countForTickets' => 'testChildCountersFollowTheFilteredTicketSet',
	];

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
	}

	/**
	 * Der Hauptfall: die Boardansicht, fuer jeden der fuenf Betrachter.
	 */
	public function testEveryViewerSeesExactlyTheirTickets(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::VISIBLE as $userId => $expected) {
			$this->assertTicketLabels(
				$expected,
				$tickets->findVisibleInBoard($this->contextFor($userId)),
				$userId . ' im Board',
			);
		}
	}

	/**
	 * Dieselbe Menge, spaltenweise — und die Summe stimmt.
	 *
	 * Die Spalteneinschraenkung ist **keine** zweite Berechtigungsfrage. Wenn sie
	 * versehentlich eine waere, faellt es hier auf: Die Vereinigung beider
	 * Spalten muss genau die Boardmenge ergeben.
	 */
	public function testColumnFilterDoesNotChangeVisibility(): void {
		$tickets = Server::get(TicketMapper::class);
		$columnA = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A];
		$columnB = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_B];

		foreach (self::VISIBLE as $userId => $whole) {
			$context = $this->contextFor($userId);

			$this->assertTicketLabels(
				self::VISIBLE_IN_COLUMN_A[$userId],
				$tickets->findVisibleInBoard($context, $columnA),
				$userId . ' in Spalte A',
			);
			$this->assertTicketLabels(
				self::VISIBLE_IN_COLUMN_B[$userId],
				$tickets->findVisibleInBoard($context, $columnB),
				$userId . ' in Spalte B',
			);

			$union = array_merge(self::VISIBLE_IN_COLUMN_A[$userId], self::VISIBLE_IN_COLUMN_B[$userId]);
			sort($union);
			$expectedWhole = $whole;
			sort($expectedWhole);

			$this->assertSame(
				$expectedWhole,
				$union,
				$userId . ': Die Spalten zusammen ergeben nicht die Boardmenge — '
				. 'dann filtert die Spaltenwahl an der Sichtbarkeit mit.',
			);
		}
	}

	/**
	 * Der Einzelabruf, fuenf Betrachter x neun Tickets — 45 Faelle.
	 *
	 * Der schaerfste Test der Matrix: Jede Kombination muss entweder das Ticket
	 * liefern **oder** eine `DoesNotExistException` werfen. Ein verborgenes und
	 * ein nicht existierendes Ticket erzeugen dieselbe Ausnahme; die Fehlerform
	 * darf nicht verraten, was die Abfrage nicht verraet.
	 */
	public function testSingleTicketAccessMatchesTheSameSet(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::VISIBLE as $userId => $expected) {
			$context = $this->contextFor($userId);

			foreach (LeakMatrixFixture::TICKETS as $label => $_) {
				$ticketId = $this->fixture->ticketIds[$label];
				$maySee = in_array($label, $expected, true);

				try {
					$ticket = $tickets->findVisible($context, $ticketId);
					$this->assertTrue(
						$maySee,
						$userId . ' hat ' . $label . ' geladen, darf es aber nicht sehen.',
					);
					$this->assertSame($label, $ticket->getTitle());
				} catch (DoesNotExistException) {
					$this->assertFalse(
						$maySee,
						$userId . ' bekam DoesNotExistException auf ' . $label . ', darf es aber sehen.',
					);
				}
			}
		}
	}

	/**
	 * Zaehler zaehlen genau die sichtbare Menge (§5.8).
	 */
	public function testCountersNeverCountWhatIsHidden(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::VISIBLE as $userId => $expected) {
			$this->assertSame(
				count($expected),
				$tickets->countVisibleInBoard($this->contextFor($userId)),
				$userId . ': Zaehler weicht von der sichtbaren Menge ab.',
			);
		}
	}

	/**
	 * „Meine Aufgaben" — der Fall, in dem die Mitarbeit die Regel nicht aushebelt.
	 */
	public function testMyTasksNeverWidensBeyondTheVisibleSet(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::MY_TASKS_OPEN as $userId => $expected) {
			$this->assertTicketLabels(
				$expected,
				$tickets->findVisibleAcrossBoards($userId, TaskFilter::openOnly()),
				$userId . ': Meine Aufgaben (nur Offenes)',
			);
		}

		foreach (self::MY_TASKS_WITH_CLOSED as $userId => $expected) {
			$this->assertTicketLabels(
				$expected,
				$tickets->findVisibleAcrossBoards($userId, TaskFilter::withClosed()),
				$userId . ': Meine Aufgaben (mit Geschlossenem)',
			);
		}

		// Jede Aufgabenmenge ist Teilmenge der sichtbaren Menge. Das ist die
		// Zusage, die auch dann noch gilt, wenn Phase 4 Sortierung und
		// Faelligkeit dazunimmt — ein neuer Filter darf sie nur verkleinern.
		foreach (self::MY_TASKS_WITH_CLOSED as $userId => $tasks) {
			$this->assertSame(
				[],
				array_values(array_diff($tasks, self::VISIBLE[$userId])),
				$userId . ': Meine Aufgaben enthaelt Tickets ausserhalb der sichtbaren Menge.',
			);
		}
	}

	/**
	 * Die Kinder folgen der gefilterten Ticket-Menge — alle vier Mapper.
	 */
	public function testChildrenFollowTheFilteredTicketSet(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach ($this->childMappers() as $name => $mapper) {
			foreach (self::VISIBLE as $userId => $expected) {
				$visibleIds = array_map(
					static fn ($ticket): int => (int)$ticket->getId(),
					$tickets->findVisibleInBoard($this->contextFor($userId)),
				);

				$this->assertChildLabels(
					$expected,
					$mapper->findForTickets($visibleIds),
					$userId . ': ' . $name,
				);
			}
		}
	}

	/**
	 * Und ihre Zaehler ebenso.
	 *
	 * Jedes Ticket hat genau ein Kind je Tabelle. Der Zaehler muss deshalb fuer
	 * jedes sichtbare Ticket 1 melden und fuer kein anderes einen Schluessel
	 * fuehren — ein Schluessel allein waere schon die Auskunft „hier gibt es
	 * etwas".
	 */
	public function testChildCountersFollowTheFilteredTicketSet(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach ($this->childMappers() as $name => $mapper) {
			foreach (self::VISIBLE as $userId => $expected) {
				$visibleIds = array_map(
					static fn ($ticket): int => (int)$ticket->getId(),
					$tickets->findVisibleInBoard($this->contextFor($userId)),
				);

				$counts = $mapper->countForTickets($visibleIds);

				$this->assertSame(
					$this->fixture->idsFor($expected),
					$this->sortedKeys($counts),
					$userId . ': ' . $name . ' zaehlt andere Tickets als die sichtbaren.',
				);

				foreach ($counts as $ticketId => $count) {
					$this->assertSame(
						1,
						$count,
						$userId . ': ' . $name . ' meldet ' . $count . ' statt 1 fuer Ticket ' . $ticketId . '.',
					);
				}
			}
		}
	}

	/**
	 * **Die Kinder-Mapper filtern nicht selbst — sie vertrauen der ID-Menge.**
	 *
	 * Dieser Test haelt eine Eigenschaft fest, die keine Schwaeche ist, aber eine
	 * Annahme: Wer `findForTickets()` mit ungefilterten IDs aufruft, bekommt
	 * ungefilterte Kinder. Der Schutz liegt **allein** darin, dass die IDs aus
	 * `TicketMapper` stammen — durchgesetzt von `MapperArchitectureTest`, der
	 * eine nackte ID als erstes Argument verbietet.
	 *
	 * Das steht hier ausdruecklich, damit niemand es fuer eine zweite Sperre
	 * haelt. Wer die Bauform spaeter aendert, aendert diesen Test — und merkt
	 * dabei, was er aufgibt.
	 */
	public function testChildMappersTrustTheIdSetTheyAreGiven(): void {
		$allIds = $this->fixture->idsFor(array_keys(LeakMatrixFixture::TICKETS));

		foreach ($this->childMappers() as $name => $mapper) {
			$this->assertCount(
				count(LeakMatrixFixture::TICKETS),
				$mapper->findForTickets($allIds),
				$name . ' filtert selbst — das war nie die Bauform, und der Rest der '
				. 'Matrix verlaesst sich darauf, dass TicketMapper der einzige Filter ist.',
			);
		}
	}

	/**
	 * Board, Mitglieder und Spalten haengen **allein** am Kontext.
	 *
	 * Anders als bei den Ticketpfaden gibt es hier keinen Verbund auf
	 * `pwerk_members` und damit keine zweite Laufzeitsperre: Ein selbst gebauter
	 * `ViewerContext` bekommt Board, Mitgliederliste und Spalten. Die einzige
	 * Sperre ist `BoardAccess` — und der Architekturtest, der
	 * `ViewerContext::forMember(` ausserhalb von `BoardAccess.php` verbietet.
	 *
	 * Das ist vertretbar (es sind Projektstammdaten, keine Ticketinhalte), aber
	 * es ist eine **andere** Zusage als bei den Tickets. Sie steht hier als
	 * Erwartung, damit sie eine Entscheidung bleibt und keine Annahme wird.
	 *
	 * Die Mitgliederliste zeigt allen Mitgliedern beide Seiten — das ist
	 * ausdruecklich gewollt (Personenauswahl), nicht ein Versehen.
	 */
	public function testBoardMetadataPathsTrustTheContextAlone(): void {
		$boards = Server::get(BoardMapper::class);
		$members = Server::get(MemberMapper::class);
		$columns = Server::get(ColumnMapper::class);

		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK, self::FREMD] as $userId) {
			$context = $this->contextFor($userId);

			$this->assertSame(
				'Leak-Matrix',
				$boards->findForViewer($context)->getTitle(),
				$userId . ' bekommt das Board nicht.',
			);

			$memberIds = array_map(static fn ($m): string => (string)$m->getUserId(), $members->findForBoard($context));
			sort($memberIds);
			$this->assertSame(
				[self::ANNA, self::BERT, self::CARLA, self::DIRK],
				$memberIds,
				$userId . ': Mitgliederliste weicht ab. Alle Mitglieder sehen beide Seiten (Personenauswahl).',
			);

			$columnTitles = array_map(static fn ($c): string => (string)$c->getTitle(), $columns->findForBoard($context));
			$this->assertSame(
				[LeakMatrixFixture::COLUMN_A, LeakMatrixFixture::COLUMN_B],
				$columnTitles,
				$userId . ': Spalten weichen ab.',
			);
		}
	}

	/**
	 * Die Boardliste folgt der Mitgliedschaft — hier greift der Verbund wieder.
	 *
	 * `findAllForUser()` nimmt eine Benutzerkennung statt eines Kontexts und
	 * verbindet selbst auf `pwerk_members`. Fuer das Nichtmitglied ist die Liste
	 * deshalb leer, obwohl es beim Einzelabruf oben das Board bekommt. Der
	 * Unterschied ist keine Unstimmigkeit, sondern zeigt, wo die Sperre sitzt.
	 */
	public function testBoardListFollowsMembership(): void {
		$boards = Server::get(BoardMapper::class);

		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$titles = array_map(static fn ($b): string => (string)$b->getTitle(), $boards->findAllForUser($userId));
			$this->assertContains('Leak-Matrix', $titles, $userId . ' findet sein Board nicht.');
		}

		$this->assertSame(
			[],
			$boards->findAllForUser(self::FREMD),
			'Das Nichtmitglied bekommt eine Boardliste.',
		);
	}

	/**
	 * Jeder registrierte Lesepfad wird von dieser Matrix auch wirklich gefahren.
	 *
	 * Ohne diesen Test koennte ein Pfad in der Registry stehen (und damit den
	 * Vollstaendigkeitstest zufriedenstellen), ohne dass je eine Erwartung an ihn
	 * geprueft wuerde. Die Registry waere dann eine Liste, kein Waechter.
	 */
	public function testTheMatrixCoversEveryRegisteredPath(): void {
		$covered = array_keys(self::COVERAGE);
		$registered = ReadPathRegistry::MAPPER_PATHS;
		sort($covered);
		sort($registered);

		$this->assertSame($registered, $covered, implode("\n", [
			'Registry und Matrix laufen auseinander.',
			'Fehlt hier: ' . implode(', ', array_diff($registered, $covered)),
			'Zu viel hier: ' . implode(', ', array_diff($covered, $registered)),
		]));

		$reflection = new ReflectionClass($this);
		foreach (self::COVERAGE as $path => $method) {
			$this->assertTrue(
				$reflection->hasMethod($method),
				'COVERAGE nennt fuer ' . $path . ' die Methode ' . $method . ', die es nicht gibt.',
			);
		}
	}

	/**
	 * Der Kontext eines Betrachters.
	 *
	 * Fuer die vier Mitglieder ueber `BoardAccess`, also wie im Betrieb. Fuer das
	 * Nichtmitglied von Hand — und zwar mit der **staerksten** Rolle und
	 * gesetztem Verwaltungsrecht. Wenn selbst das nichts nuetzt, nuetzt nichts.
	 */
	private function contextFor(string $userId): ViewerContext {
		if ($userId === self::FREMD) {
			return ViewerContext::forMember(
				self::FREMD,
				$this->fixture->boardId,
				ViewerContext::ROLE_INTERNAL,
				true,
			);
		}

		return $this->fixture->contextFor($userId);
	}

	/**
	 * @return array<string, \OCA\Projektwerk\Db\TicketChildMapper>
	 */
	private function childMappers(): array {
		return [
			'Kommentare' => Server::get(CommentMapper::class),
			'Arbeitsschritte' => Server::get(StepMapper::class),
			'Anhaenge' => Server::get(AttachmentMapper::class),
			'Mitarbeitende' => Server::get(TicketUserMapper::class),
		];
	}

	/**
	 * @param string[] $expected
	 * @param \OCA\Projektwerk\Db\Ticket[] $actual
	 */
	private function assertTicketLabels(array $expected, array $actual, string $case): void {
		$sorted = $expected;
		sort($sorted);

		$this->assertSame($sorted, $this->fixture->labelsOfTickets($actual), $case);
	}

	/**
	 * @param string[] $expected
	 * @param object[] $actual
	 */
	private function assertChildLabels(array $expected, array $actual, string $case): void {
		$sorted = $expected;
		sort($sorted);

		$this->assertSame($sorted, $this->fixture->labelsOfChildren($actual), $case);
	}

	/**
	 * @param array<int, int> $counts
	 * @return int[]
	 */
	private function sortedKeys(array $counts): array {
		$keys = array_map('intval', array_keys($counts));
		sort($keys);

		return $keys;
	}
}
