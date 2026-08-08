<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Controller\BoardController;
use OCA\Projektwerk\Controller\TicketController;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TaskFilter;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCA\Projektwerk\Service\TicketService;
use OCA\Projektwerk\Tests\ReadPathRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
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
		'TicketMapper::findLastPositionInColumn' => 'testLastPositionIsTheSameForEveryViewer',
		// zusaetzlich gefahren von testBothCompanyNamesReachEveryViewer
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

	/**
	 * Welcher Test deckt welche Lese-Route.
	 *
	 * @var array<string, string>
	 */
	private const ROUTE_COVERAGE = [
		'board#index' => 'testBoardIndexEndpointFollowsMembership',
		'board#show' => 'testBoardShowEndpointRefusesNonMembers',
		'ticket#index' => 'testTicketIndexEndpointMatchesTheVisibleSet',
		'ticket#show' => 'testTicketShowEndpointMatchesTheVisibleSet',
		'ticket#visibilityImpact' => 'testVisibilityImpactNamesWhoLosesAccess',
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
	 * **Beide Firmennamen gehen an jeden Betrachter — auch der eigene.**
	 *
	 * In der Personenauswahl eines öffentlichen Tickets stehen interne und
	 * externe Personen gemeinsam und ohne Trennung (§9). Bekäme ein Betrachter
	 * nur den Namen der *anderen* Seite, wäre die eigene stumm „der Normalfall"
	 * — und die Trennung wäre durch die Hintertür zurück.
	 *
	 * Das ist ausdrücklich **kein** Leck: Der Firmenname der Gegenseite ist
	 * keine geschützte Information, sondern steht im Projektnamen und auf jeder
	 * Rechnung.
	 */
	public function testBothCompanyNamesReachEveryViewer(): void {
		$boards = Server::get(BoardMapper::class);

		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$board = $boards->findForViewer($this->contextFor($userId));

			$this->assertSame(LeakMatrixFixture::ORG_INTERNAL, $board->getOrgInternal(), $userId);
			$this->assertSame(LeakMatrixFixture::ORG_EXTERNAL, $board->getOrgExternal(), $userId);
		}
	}

	/**
	 * Der Name für dieses Board übersteuert den aus Nextcloud — und `null`
	 * heißt „nimm den aus Nextcloud", nicht „kein Name".
	 *
	 * Bert trägt bewusst keinen. Ohne diesen Fall prüfte die Suite nur die
	 * Übersteuerung und nicht den Normalfall.
	 */
	public function testMembershipCarriesAnOptionalName(): void {
		$members = Server::get(MemberMapper::class);

		$byUser = [];
		foreach ($members->findForBoard($this->contextFor(self::CARLA)) as $member) {
			$byUser[(string)$member->getUserId()] = $member->getDisplayName();
		}

		$this->assertSame('Anna Reuter', $byUser[self::ANNA]);
		$this->assertSame('Carla Mueller', $byUser[self::CARLA]);
		$this->assertNull($byUser[self::BERT], 'Ohne Eintrag muss der Anzeigename aus Nextcloud gelten.');
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
	 * Die Boardliste am Endpunkt — dieselbe Erwartung wie am Mapper.
	 *
	 * Dass beide dasselbe liefern muessen, ist der Punkt: Der Endpunkt ist die
	 * Stelle, an der jemand spaeter „nur schnell" etwas dazunimmt.
	 */
	public function testBoardIndexEndpointFollowsMembership(): void {
		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$response = $this->boardController($userId)->index();

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $userId);
			$this->assertSame(
				['Leak-Matrix'],
				array_map(static fn ($b): string => (string)$b->getTitle(), $response->getData()),
				$userId . ' bekommt eine andere Boardliste als erwartet.',
			);
		}

		$response = $this->boardController(self::FREMD)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData(), 'Das Nichtmitglied bekommt eine Boardliste.');
	}

	/**
	 * Der Einzelabruf am Endpunkt — und die Probe auf die Fehlerform.
	 *
	 * Hier greift `BoardAccess`, anders als bei den Mapper-Pfaden mit selbst
	 * gebautem Kontext: Das Nichtmitglied bekommt **404**, nicht 403. Ein 403
	 * beantwortete die Frage, die die Abfrage nicht beantwortet — naemlich dass
	 * es dieses Projekt gibt.
	 */
	public function testBoardShowEndpointRefusesNonMembers(): void {
		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$response = $this->boardController($userId)->show($this->fixture->boardId);
			$data = $response->getData();

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $userId);
			$this->assertSame('Leak-Matrix', $data['board']->getTitle(), $userId);
			$this->assertCount(4, $data['members'], $userId . ': Mitgliederliste weicht ab.');
			$this->assertCount(2, $data['columns'], $userId . ': Spalten weichen ab.');
			$this->assertSame($userId, $data['viewer']['userId']);
		}

		$response = $this->boardController(self::FREMD)->show($this->fixture->boardId);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$response->getStatus(),
			'Das Nichtmitglied bekommt nicht 404 — die Fehlerform verrät, dass es das Board gibt.',
		);
		$this->assertSame([], $response->getData());
	}

	/**
	 * Ein Ticket geht ohne `position` über die Leitung (§5.8).
	 *
	 * Das Ticket ist die einzige je Betrachter gefilterte Entität; eine
	 * ausgelieferte Sortierposition verriete die Lücken. Der Test steht in der
	 * Matrix und nicht bei den Entities, weil er hier an einem **echten**
	 * gelesenen Ticket hängt und nicht an einem von Hand gebauten.
	 */
	public function testSerializedTicketsCarryNoPosition(): void {
		$tickets = Server::get(TicketMapper::class)
			->findVisibleInBoard($this->contextFor(self::ANNA));

		$this->assertNotEmpty($tickets);

		foreach ($tickets as $ticket) {
			$serialized = $ticket->jsonSerialize();

			$this->assertArrayNotHasKey(
				'position',
				$serialized,
				'Die Sortierposition geht über die Leitung — sie verrät genau das, was der Filter verbirgt.',
			);
			// Gegenprobe, dass der Test nicht einfach ein leeres Feld prueft.
			$this->assertArrayHasKey('number', $serialized);
		}
	}

	/**
	 * **Die ungefilterte Position ist für jeden Betrachter dieselbe.**
	 *
	 * `findLastPositionInColumn()` liest bewusst an `TicketScope` vorbei (§3.8).
	 * Die Erwartung dazu ist deshalb umgekehrt zu allen anderen in dieser Datei:
	 * nicht „jeder sieht seine Menge", sondern „alle sehen denselben Wert".
	 *
	 * Wäre der Wert betrachterabhängig, wäre genau das der Beweis, dass er etwas
	 * über die gefilterte Menge aussagt — und dann verriete die Position eines
	 * neuen Tickets, wie viele verborgene darüber liegen.
	 */
	public function testLastPositionIsTheSameForEveryViewer(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach ([LeakMatrixFixture::COLUMN_A, LeakMatrixFixture::COLUMN_B] as $columnTitle) {
			$columnId = $this->fixture->columnIds[$columnTitle];

			$values = [];
			foreach (array_keys(self::VISIBLE) as $userId) {
				$values[$userId] = $tickets->findLastPositionInColumn($this->contextFor($userId), $columnId);
			}

			$this->assertCount(
				1,
				array_unique($values),
				$columnTitle . ': Die ungefilterte Position unterscheidet sich je Betrachter — '
				. 'dann sagt sie etwas über die gefilterte Menge aus. ' . json_encode($values),
			);
			$this->assertNotNull(reset($values), $columnTitle . ' ist leer, der Test prüft nichts.');
		}
	}

	/**
	 * Der Listen-Endpunkt liefert dieselbe Menge wie der Mapper — und seine
	 * Zähler zählen nur die sichtbaren Tickets.
	 */
	public function testTicketIndexEndpointMatchesTheVisibleSet(): void {
		foreach (self::VISIBLE as $userId => $expected) {
			$response = $this->ticketController($userId)->index($this->fixture->boardId);

			if ($userId === self::FREMD) {
				// Der Endpunkt geht über BoardAccess; ein Nichtmitglied kommt
				// gar nicht erst zur Abfrage.
				$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
				continue;
			}

			$data = $response->getData();

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $userId);
			$this->assertTicketLabels($expected, $data['tickets'], $userId . ' am Endpunkt');

			foreach ($data['counts'] as $kind => $counts) {
				$this->assertSame(
					$this->fixture->idsFor($expected),
					$this->sortedKeys($counts),
					$userId . ': Zähler „' . $kind . '" nennt andere Tickets als die sichtbaren.',
				);
			}
		}
	}

	/**
	 * Der Einzel-Endpunkt, fünf Betrachter × neun Tickets — dieselben 45 Fälle
	 * wie am Mapper, eine Schicht höher.
	 */
	public function testTicketShowEndpointMatchesTheVisibleSet(): void {
		foreach (self::VISIBLE as $userId => $expected) {
			$controller = $this->ticketController($userId);

			foreach (LeakMatrixFixture::TICKETS as $label => $_) {
				$response = $controller->show($this->fixture->boardId, $this->fixture->ticketIds[$label]);

				if ($userId === self::FREMD) {
					$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $label);
					continue;
				}

				if (in_array($label, $expected, true)) {
					$this->assertSame(Http::STATUS_OK, $response->getStatus(), $userId . ' / ' . $label);
					$this->assertSame($label, $response->getData()['ticket']->getTitle());
					// Die Kinder folgen der Einermenge und sind genau eines je Art.
					$this->assertCount(1, $response->getData()['comments'], $userId . ' / ' . $label);
				} else {
					$this->assertSame(
						Http::STATUS_NOT_FOUND,
						$response->getStatus(),
						$userId . ' bekommt ' . $label . ', darf es aber nicht sehen.',
					);
				}
			}
		}
	}

	/**
	 * **Die beiden Fassungen der Sichtbarkeitsregel sagen dasselbe.**
	 *
	 * `TicketScope::apply()` ist die Regel als JOIN, `TicketScope::wouldSee()`
	 * dieselbe Regel als Prädikat. Die zweite gibt es, weil der
	 * Herunterstufen-Dialog eine Frage nach einem Zustand stellt, den es noch
	 * nicht gibt — das kann keine Abfrage über gespeicherte Werte beantworten.
	 *
	 * Zwei Fassungen einer Regel sind der Anfang jedes Lecks. Deshalb prüft
	 * dieser Test sie **gegeneinander**: für jedes der neun Tickets und jedes
	 * der vier Mitglieder muss das Prädikat dasselbe sagen wie der JOIN. 36
	 * Vergleiche, und keiner darf abweichen.
	 */
	public function testTheTwoFacesOfTheRuleAgree(): void {
		$scope = Server::get(TicketScope::class);
		$tickets = Server::get(TicketMapper::class);
		$members = Server::get(MemberMapper::class);

		$byUser = [];
		foreach ($members->findForBoard($this->contextFor(self::ANNA)) as $member) {
			$byUser[(string)$member->getUserId()] = (string)$member->getRole();
		}

		$vergleiche = 0;
		foreach (LeakMatrixFixture::TICKETS as $label => [$visibility, $creator, $creatorRole, $_col, $_closed]) {
			$ticketId = $this->fixture->ticketIds[$label];

			foreach ($byUser as $userId => $role) {
				$predicate = $scope->wouldSee($visibility, $creator, $creatorRole, $userId, $role);

				$join = true;
				try {
					$tickets->findVisible($this->contextFor($userId), $ticketId);
				} catch (DoesNotExistException) {
					$join = false;
				}

				$this->assertSame(
					$join,
					$predicate,
					'Die beiden Fassungen der Regel widersprechen sich bei ' . $label . ' / ' . $userId . '.',
				);
				$vergleiche++;
			}
		}

		$this->assertSame(36, $vergleiche, 'Neun Tickets mal vier Mitglieder — sonst prüft der Test zu wenig.');
	}

	/**
	 * Was ein Herunterstufen kostet: Namen und Zahlen, keine Warnung.
	 *
	 * Ueber den Controller, nicht den Service direkt — sonst bliebe die
	 * eigentliche Route (Parameterbindung, Fehlerabbildung auf HTTP-Status)
	 * ungeprueft, obwohl {@see ReadPathRegistry::ROUTE_PATHS} sie als
	 * gefahren fuehrt.
	 */
	public function testVisibilityImpactNamesWhoLosesAccess(): void {
		$controller = $this->ticketController(self::ANNA);
		$boardId = $this->fixture->boardId;
		$publicAnna = $this->fixture->ticketIds['public/anna'];

		// public/anna sehen alle vier. Auf 'internal' verlieren die beiden
		// Externen den Zugriff, auf 'private' zusaetzlich Bert.
		$response = $controller->visibilityImpact($boardId, $publicAnna, 'internal');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$internal = $response->getData();
		sort($internal['losing']);
		$this->assertSame([self::CARLA, self::DIRK], $internal['losing']);
		$this->assertSame(1, $internal['comments'], 'Jedes Ticket der Fixture hat genau einen Kommentar.');
		$this->assertSame(1, $internal['attachments']);

		$private = $controller->visibilityImpact($boardId, $publicAnna, 'private')->getData();
		sort($private['losing']);
		$this->assertSame([self::BERT, self::CARLA, self::DIRK], $private['losing']);

		// Hochstufen nimmt niemandem etwas.
		$public = $controller->visibilityImpact($boardId, $this->fixture->ticketIds['private/anna'], 'public')->getData();
		$this->assertSame([], $public['losing']);

		// Ein unbekannter Wert ist eine 400, kein durchgereichter Serverfehler.
		$badRequest = $controller->visibilityImpact($boardId, $publicAnna, 'gestohlen');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $badRequest->getStatus());

		// Das Nichtmitglied bekommt 404 wie an jedem anderen Lesepfad.
		$fremdResponse = $this->ticketController(self::FREMD)->visibilityImpact($boardId, $publicAnna, 'internal');
		$this->assertSame(Http::STATUS_NOT_FOUND, $fremdResponse->getStatus());
	}

	/**
	 * Jeder registrierte Lesepfad und jede registrierte Route werden von dieser
	 * Matrix auch wirklich gefahren.
	 *
	 * Ohne diesen Test koennte ein Eintrag in der Registry stehen (und damit den
	 * Vollstaendigkeitstest zufriedenstellen), ohne dass je eine Erwartung an ihn
	 * geprueft wuerde. Die Registry waere dann eine Liste, kein Waechter.
	 */
	public function testTheMatrixCoversEveryRegisteredPath(): void {
		$this->assertCoverage(
			ReadPathRegistry::MAPPER_PATHS,
			array_keys(self::COVERAGE),
			'Mapper-Lesepfade',
		);
		$this->assertCoverage(
			ReadPathRegistry::ROUTE_PATHS,
			array_keys(self::ROUTE_COVERAGE),
			'Lese-Routen',
		);

		$reflection = new ReflectionClass($this);
		foreach (self::COVERAGE + self::ROUTE_COVERAGE as $path => $method) {
			$this->assertTrue(
				$reflection->hasMethod($method),
				'Die Abdeckung nennt fuer ' . $path . ' die Methode ' . $method . ', die es nicht gibt.',
			);
		}
	}

	/**
	 * @param string[] $registered
	 * @param string[] $covered
	 */
	private function assertCoverage(array $registered, array $covered, string $what): void {
		sort($registered);
		sort($covered);

		$this->assertSame($registered, $covered, implode("\n", [
			'Registry und Matrix laufen auseinander (' . $what . ').',
			'Fehlt in der Matrix: ' . implode(', ', array_diff($registered, $covered)),
			'Zu viel in der Matrix: ' . implode(', ', array_diff($covered, $registered)),
		]));
	}

	/**
	 * Der Controller, wie ihn Nextcloud baut — nur mit der Benutzerkennung von
	 * Hand statt aus der Sitzung.
	 */
	private function boardController(string $userId): BoardController {
		return new BoardController(
			$this->createStub(IRequest::class),
			Server::get(BoardMapper::class),
			Server::get(MemberMapper::class),
			Server::get(ColumnMapper::class),
			Server::get(BoardAccess::class),
			$userId,
		);
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

	private function ticketController(string $userId): TicketController {
		return new TicketController(
			$this->createStub(IRequest::class),
			Server::get(TicketMapper::class),
			Server::get(CommentMapper::class),
			Server::get(StepMapper::class),
			Server::get(AttachmentMapper::class),
			Server::get(TicketUserMapper::class),
			Server::get(TicketService::class),
			Server::get(BoardAccess::class),
			$userId,
		);
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
