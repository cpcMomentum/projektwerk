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
use OCA\Projektwerk\Access\WaitStateCalculator;
use OCA\Projektwerk\Controller\BoardController;
use OCA\Projektwerk\Controller\DeepLinkController;
use OCA\Projektwerk\Controller\MemberSearchController;
use OCA\Projektwerk\Controller\TaskController;
use OCA\Projektwerk\Controller\TicketController;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\MailOutboxMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TaskFilter;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\NotifyPrefService;
use OCA\Projektwerk\Service\StepService;
use OCA\Projektwerk\Service\TicketService;
use OCA\Projektwerk\Tests\ReadPathRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
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
		// **Bert traegt den Kern des Akzeptanzkriteriums.** Er ist im ersten
		// Board intern, im zweiten extern — und bekommt aus **einer** Abfrage
		// je Board die dort geltende Menge. `b:internal/bert` ist dabei der
		// Beleg fuer die zweite Rolle: Ein `internal`-Ticket mit
		// Erzeugerrolle extern sieht er nur, weil er *in diesem Board* die
		// Kundenseite ist. Wer seine Rolle einmal global aufloest, verliert es
		// — oder bekommt stattdessen faelschlich `b:internal/erna`.
		self::BERT => ['public/bert', 'internal/bert', 'private/bert', 'b:internal/bert'],
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
		self::BERT => ['public/bert', 'internal/bert', 'private/bert', 'b:internal/bert'],
		self::CARLA => ['public/carla', 'internal/carla', 'private/carla'],
		self::DIRK => ['public/anna', 'public/bert', 'public/carla', 'internal/carla'],
		self::FREMD => [],
	];

	/**
	 * „Meine Arbeitsschritte" — die Vorgaenge, an denen mir ein **offener**
	 * Schritt gehoert.
	 *
	 * Eine ausgeschriebene Menge und keine Teilmengenpruefung: Ohne sie liesse
	 * sich `assigned_user_id = :uid` aus der Unterabfrage streichen, und jeder
	 * Betrachter saehe unter „Meine Arbeitsschritte" **alles**, was er sehen
	 * darf — die meisten davon ohne einen einzigen Schritt. Kein Test waere
	 * dabei rot geworden.
	 *
	 * Zwei Zeilen tragen die Last:
	 * - **Bert** bekommt `b:public/erna`, obwohl er dort weder verantwortlich
	 *   noch mitarbeitend ist — der Grund, warum dieser Lesepfad existiert.
	 * - **Bert bekommt `b:internal/bert` NICHT**, obwohl er das Ticket sieht
	 *   und der Schritt ihm gehoert: Er ist erledigt. Das ist die einzige
	 *   Stelle, an der `done = 0` ueberhaupt geprueft wird.
	 *
	 * @var array<string, string[]>
	 */
	private const MY_STEPS_OPEN = [
		self::ANNA => ['public/anna', 'internal/anna', 'private/anna'],
		self::BERT => ['public/bert', 'internal/bert', 'private/bert', 'b:public/erna'],
		self::CARLA => ['internal/carla', 'private/carla'],
		// Dirk arbeitet an allen neun Tickets mit, hat aber keinen Schritt.
		// Genau der Unterschied zwischen den beiden Abschnitten.
		self::DIRK => [],
		self::FREMD => [],
	];

	/**
	 * Die Boards in der Herkunftszeile — je Betrachter ausgeschrieben.
	 *
	 * Gegen das Universum aller Fixture-Boards zu pruefen faengt nichts: Wer
	 * versehentlich fremde Boards mitliefert, bleibt gruen, solange es die
	 * Fixture-Boards sind. Hier stehen sie einzeln, und dann faellt es auf.
	 *
	 * @var array<string, string[]>
	 */
	private const TASK_BOARDS = [
		self::ANNA => ['Leak-Matrix'],
		self::BERT => ['Leak-Matrix', 'Leak-Matrix Zweitboard'],
		self::CARLA => ['Leak-Matrix'],
		self::DIRK => ['Leak-Matrix'],
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
		'TicketMapper::findVisibleWithMyOpenSteps' => 'testMyStepsNeverWidensBeyondTheVisibleSet',
		'TicketMapper::findVisibleAnywhere' => 'testDeepLinkLookupMatchesTheVisibleSet',
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
		// **Zwei Pfade ohne Betrachter.** Ihre Erwartung ist eine andere Art von
		// Zusage — die Begruendung steht bei den Eintraegen in der Registry und
		// bei den beiden Tests selbst.
		'MailOutboxMapper::findRetryable' => 'testTheOutboxIsNotAViewerPath',
		'NotifyPrefMapper::findForUser' => 'testChannelPreferencesAreScopedToTheirOwner',
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
		'deepLink#ticket' => 'testDeepLinkTellsOnlyWhatTheViewerMaySee',
		'step#assignable' => 'testAssignableNeverOffersSomeoneWhoCannotSeeTheTicket',
		'memberSearch#search' => 'testMemberSearchRefusesEveryoneWithoutManagementRights',
		'notifyPref#index' => 'testEveryViewerSeesOnlyTheirOwnChannelSwitches',
		'task#index' => 'testTaskEndpointMatchesTheVisibleSetAcrossBoards',
	];

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
	}

	/**
	 * **Der Ausgangskorb ist kein Betrachterpfad — und das ist die Erwartung.**
	 *
	 * Jeder andere Eintrag dieser Matrix beantwortet „was sieht wer". Hier gibt
	 * es kein Wer: Am Ende steht der Nachlauf-Job, der eine Mail nachreicht. Er
	 * hat kein Board, keine Rolle und keine Sichtbarkeit.
	 *
	 * Geprueft wird deshalb das Gegenteil des Ueblichen: Die Abfrage liefert
	 * **absichtlich** die Zeilen aller Empfaenger. Wuerde sie filtern, waere das
	 * ein Hinweis darauf, dass hier doch jemand mit Rechten mitliest — und dann
	 * braeuchte es eine echte Erwartung je Betrachter statt dieser.
	 *
	 * Die strukturelle Haelfte der Zusage (kein `ViewerContext` in der Signatur)
	 * prueft `ReadPathCompletenessTest::testTheseMappersNeverTakeAViewer`.
	 */
	public function testTheOutboxIsNotAViewerPath(): void {
		$outbox = Server::get(MailOutboxMapper::class);
		$now = new \DateTime();

		foreach ([LeakMatrixFixture::ANNA, LeakMatrixFixture::CARLA] as $uid) {
			$zeile = new MailOutbox();
			$zeile->setRecipientUid($uid);
			$zeile->setTicketId($this->fixture->ticketIds['public/anna']);
			$zeile->setEvent(MailOutbox::EVENT_TICKET_ASSIGNED);
			$zeile->setStatus(MailOutbox::STATUS_PENDING);
			$zeile->setAttempts(0);
			$zeile->setCreatedAt($now);
			$outbox->insert($zeile);
		}

		$empfaenger = array_map(
			static fn (MailOutbox $z): string => (string)$z->getRecipientUid(),
			$outbox->findRetryable(),
		);

		$this->assertContains(LeakMatrixFixture::ANNA, $empfaenger);
		$this->assertContains(
			LeakMatrixFixture::CARLA,
			$empfaenger,
			'Der Nachlauf muss die Zeilen aller Empfaenger sehen — er ist niemandes Betrachter.',
		);
	}

	/**
	 * **Die Kanalschalter gehoeren ihrer Person.**
	 *
	 * Hier gibt es sehr wohl eine Grenze, sie verlaeuft nur nicht am Board,
	 * sondern an der Benutzerkennung: Wer nach den Schaltern von A fragt,
	 * bekommt nicht die von B. Das ist die ganze Erwartung — es gibt keine
	 * Projektinhalte in dieser Tabelle, nur „will ich Mails".
	 */
	public function testChannelPreferencesAreScopedToTheirOwner(): void {
		$prefs = Server::get(NotifyPrefMapper::class);

		$aus = new NotifyPref();
		$aus->setUserId(LeakMatrixFixture::ANNA);
		$aus->setPrefKey(NotifyPref::CHANNEL_MAIL);
		$aus->setEnabled(0);
		$prefs->insert($aus);

		$this->assertSame(
			[NotifyPref::CHANNEL_MAIL],
			array_map(static fn (NotifyPref $p): string => (string)$p->getPrefKey(), $prefs->findForUser(LeakMatrixFixture::ANNA)),
		);
		$this->assertSame(
			[],
			$prefs->findForUser(LeakMatrixFixture::CARLA),
			'Carla hat nichts eingestellt — sie darf auch nichts von Anna sehen.',
		);

		// Und die Vorgabe: keine Zeile heisst „an".
		$this->assertFalse($prefs->isEnabled(LeakMatrixFixture::ANNA, NotifyPref::CHANNEL_MAIL));
		$this->assertTrue($prefs->isEnabled(LeakMatrixFixture::ANNA, NotifyPref::CHANNEL_BELL));
		$this->assertTrue($prefs->isEnabled(LeakMatrixFixture::CARLA, NotifyPref::CHANNEL_MAIL));
	}

	/**
	 * **Die drei Stufen der Aufloesung** (Entscheidung mit Axel am 2026-08-11).
	 *
	 * Projektzeile schlaegt globale Zeile schlaegt Vorgabe. Der Fall, der sie
	 * noetig gemacht hat: Wer in vielen Projekten Mitglied ist, aber nur wenige
	 * davon fuehrt, muesste sonst alles abschalten — und verloere die
	 * Zuweisungen mit.
	 */
	public function testProjectSettingsBeatTheGlobalOne(): void {
		$prefs = Server::get(NotifyPrefMapper::class);
		$board = $this->fixture->boardId;

		// Global aus …
		$global = new NotifyPref();
		$global->setUserId(LeakMatrixFixture::ANNA);
		$global->setPrefKey(NotifyPref::EVENT_TICKET_CREATED);
		$global->setBoardId(NotifyPrefMapper::GLOBAL_SCOPE);
		$global->setEnabled(0);
		$prefs->insert($global);

		$this->assertFalse(
			$prefs->isEnabled(LeakMatrixFixture::ANNA, NotifyPref::EVENT_TICKET_CREATED, $board),
			'Ohne Projektzeile gilt die globale — auch fuer Projekte, die es beim Einstellen noch nicht gab.',
		);

		// … dieses eine Projekt aber an.
		$projekt = new NotifyPref();
		$projekt->setUserId(LeakMatrixFixture::ANNA);
		$projekt->setPrefKey(NotifyPref::EVENT_TICKET_CREATED);
		$projekt->setBoardId($board);
		$projekt->setEnabled(1);
		$prefs->insert($projekt);

		$this->assertTrue(
			$prefs->isEnabled(LeakMatrixFixture::ANNA, NotifyPref::EVENT_TICKET_CREATED, $board),
			'Die Projektzeile ist die Ausnahme und schlaegt die globale.',
		);
		$this->assertFalse(
			$prefs->isEnabled(LeakMatrixFixture::ANNA, NotifyPref::EVENT_TICKET_CREATED, $board + 9999),
			'Ein anderes Projekt bleibt bei der globalen Einstellung.',
		);
		$this->assertTrue(
			$prefs->isEnabled(LeakMatrixFixture::ANNA, NotifyPref::EVENT_TICKET_ASSIGNED, $board),
			'Ein anderer Anlass ist davon unberuehrt — der Rundruf abzuschalten heisst nicht, Zuweisungen abzuschalten.',
		);
	}

	/**
	 * **Jeder sieht nur seine eigenen Kanalschalter.**
	 *
	 * Die Route hat kein Board im Pfad und liefert keine Projektdaten — die
	 * Erwartung ist deshalb eine andere als sonst in dieser Matrix, aber eine
	 * echte: Die Grenze verlaeuft an der Benutzerkennung aus der Sitzung.
	 *
	 * Dass jemand in Projekt 7 keine Mails will, verraet nichts ueber Projekt 7.
	 * Es verraet etwas ueber die Person — und die fragt selbst.
	 */
	public function testEveryViewerSeesOnlyTheirOwnChannelSwitches(): void {
		$service = Server::get(NotifyPrefService::class);

		// Je Projekt sind nur die **Anlaesse** einstellbar; die Kanaele gelten
		// global (Entscheidung 2026-08-11).
		$service->set(LeakMatrixFixture::ANNA, NotifyPref::EVENT_TICKET_CREATED, $this->fixture->boardId, false);
		$service->set(LeakMatrixFixture::CARLA, NotifyPref::CHANNEL_BELL, NotifyPrefMapper::GLOBAL_SCOPE, false);

		$annas = $service->forUser(LeakMatrixFixture::ANNA);
		$carlas = $service->forUser(LeakMatrixFixture::CARLA);

		$this->assertSame([], $annas['global'], 'Anna hat global nichts gesetzt.');
		$this->assertSame(
			[NotifyPref::EVENT_TICKET_CREATED => false],
			$annas['boards'][$this->fixture->boardId] ?? [],
		);

		$this->assertSame([NotifyPref::CHANNEL_BELL => false], $carlas['global']);
		$this->assertSame([], $carlas['boards'], 'Carla hat keine Projekt-Ausnahme — und schon gar nicht Annas.');

		// Und der Urlaubsschalter: Ausnahmen weg, globale Zeile bleibt.
		$service->clearBoardOverrides(LeakMatrixFixture::CARLA);
		$this->assertSame(
			[NotifyPref::CHANNEL_BELL => false],
			$service->forUser(LeakMatrixFixture::CARLA)['global'],
			'Die globale Zeile ist der Rueckfallwert, keine der Ausnahmen — sie bleibt stehen.',
		);
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
		//
		// Verglichen wird gegen die Sichtmenge **beider** Boards, je Board mit
		// der dort geltenden Rolle. Gegen `VISIBLE` allein zu pruefen waere seit
		// dem Zweitboard zu eng — und ein Test, den man weiten muss, weil er
		// Richtiges anschlaegt, wird sonst leicht ganz gestrichen.
		foreach (self::MY_TASKS_WITH_CLOSED as $userId => $tasks) {
			$this->assertSame(
				[],
				array_values(array_diff($tasks, $this->visibleAnywhereFor($userId))),
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
	 * Der Endpunkt „Meine Aufgaben" — dieselbe Erwartung wie am Mapper, plus
	 * die zwei Dinge, die es nur hier gibt.
	 *
	 * **Erstens: kein 404 fuer den Fremden.** Jede andere Leseroute haengt an
	 * einem Board und weist ein Nichtmitglied mit 404 ab. Diese haengt an
	 * keinem — sie kann nichts verbergen, was es zu verbergen gaebe, und
	 * antwortet deshalb mit **leeren Listen**. Das ist kein Versehen, sondern
	 * die einzige ehrliche Antwort: „Du hast keine Aufgaben" ist wahr.
	 *
	 * **Zweitens: die Schritte kommen aus der gefilterten Menge.** Jeder
	 * gelieferte Schritt muss zu einem gelieferten Vorgang gehoeren. Ein
	 * Schritt ohne seinen Vorgang waere die Auskunft, dass es ihn gibt.
	 */
	public function testTaskEndpointMatchesTheVisibleSetAcrossBoards(): void {
		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$response = $this->taskController($userId)->index();
			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $userId);

			$data = $response->getData();

			$this->assertTicketLabels(
				self::MY_TASKS_OPEN[$userId],
				$data['tickets'],
				$userId . ': Meine Tickets am Endpunkt',
			);

			$this->assertTicketLabels(
				self::MY_STEPS_OPEN[$userId],
				$data['stepTickets'],
				$userId . ': Meine Arbeitsschritte am Endpunkt',
			);

			// **Die Anzahl zuerst.** Ohne sie waeren die Pruefungen darunter
			// leere Schleifen: Ein Filter, der nichts durchlaesst, liesse den
			// Abschnitt dauerhaft leer und den Test gruen.
			$this->assertCount(
				count(self::MY_STEPS_OPEN[$userId]),
				$data['steps'],
				$userId . ': Die Zahl der Schritte passt nicht zur Zahl ihrer Vorgaenge.',
			);

			// Jeder Schritt gehoert zu einem Vorgang, der mitgeliefert wurde.
			$geliefert = array_map(static fn ($t): int => (int)$t->getId(), $data['stepTickets']);
			foreach ($data['steps'] as $step) {
				$this->assertContains(
					(int)$step->getTicketId(),
					$geliefert,
					$userId . ': Ein Schritt kommt ohne seinen Vorgang — das verraet dessen Existenz.',
				);
				$this->assertSame(
					$userId,
					(string)$step->getAssignedUserId(),
					$userId . ': Der Endpunkt liefert einen fremden Arbeitsschritt.',
				);
				$this->assertFalse(
					$step->isDone(),
					$userId . ': Ein erledigter Schritt steht noch in „Meine Arbeitsschritte".',
				);
			}

			// Die Herkunftszeile — ausgeschrieben, nicht gegen das Universum
			// aller Fixture-Boards. Sonst faellt ein fremdes Board nicht auf.
			$this->assertSame(
				self::TASK_BOARDS[$userId],
				array_values(array_map(static fn ($b): string => $b['title'], $data['boards'])),
				$userId . ': Ein fremdes Board in der Herkunftszeile.',
			);
		}

		// Und das Nichtmitglied: leere Listen, kein 404.
		$fremd = $this->taskController(self::FREMD)->index();
		$this->assertSame(Http::STATUS_OK, $fremd->getStatus());
		$this->assertSame([], $fremd->getData()['tickets']);
		$this->assertSame([], $fremd->getData()['steps']);
		$this->assertSame([], $fremd->getData()['stepTickets']);
		$this->assertSame([], $fremd->getData()['boards']);
	}

	/**
	 * **Das Akzeptanzkriterium von Phase 4, woertlich:** Eine Person, die in
	 * Board A intern und in Board B extern ist, sieht in **einer** Abfrage die
	 * je Board korrekte Menge.
	 *
	 * Bert ist diese Person. Der Test steht neben
	 * {@see testMyTasksNeverWidensBeyondTheVisibleSet()}, weil der die Mengen
	 * vergleicht — dieser hier benennt, **warum** sie so aussehen, und faellt
	 * mit einer Meldung, die die Ursache nennt statt einer ID-Liste.
	 *
	 * Die beiden entscheidenden Zeilen kann keine Implementierung zugleich
	 * erfuellen, die die Rolle global bestimmt: `b:internal/erna` (Erzeuger
	 * intern) bleibt ihm verborgen, `b:internal/bert` (Erzeuger extern) nicht —
	 * im ersten Board ist es genau andersherum.
	 */
	public function testTheSameuserHasADifferentRoleInEachBoard(): void {
		$tickets = Server::get(TicketMapper::class);
		$bert = self::BERT;

		$sichtbarInB = $this->fixture->labelsOfTickets(
			$tickets->findVisibleInBoard($this->fixture->contextFor($bert, $this->fixture->otherBoardId)),
		);

		$this->assertSame(
			['b:internal/bert', 'b:public/erna'],
			$sichtbarInB,
			'Bert ist im Zweitboard die Kundenseite. Er darf dort das interne Ticket der '
			. 'internen Seite NICHT sehen — im ersten Board sieht er das Gegenstueck sehr wohl.',
		);

		// Und die Gegenprobe im ersten Board, in derselben Pruefung: Dort ist er
		// intern und sieht `internal/anna`, das interne Ticket der anderen
		// internen Person. Beides zusammen schliesst die globale Rollenaufloesung
		// aus — eine der beiden Zeilen faellt bei ihr immer.
		$this->assertContains(
			'internal/anna',
			$this->fixture->labelsOfTickets(
				$tickets->findVisibleInBoard($this->fixture->contextFor($bert)),
			),
			'Im ersten Board ist Bert intern und muss das interne Ticket der internen Seite sehen.',
		);
	}

	/**
	 * „Meine Arbeitsschritte" ist eine **andere Menge** als „Meine Tickets".
	 *
	 * Der Beleg fuer den eigenen Lesepfad: Berts Schritt haengt an
	 * `b:public/erna`, einem Vorgang, fuer den er weder verantwortlich noch
	 * mitarbeitend ist. `findVisibleAcrossBoards()` liefert ihn deshalb nicht —
	 * und liefert stattdessen Vorgaenge, an denen Bert kein Schritt gehoert.
	 * Waeren die beiden Mengen gleich, braeuchte es den zweiten Pfad nicht.
	 */
	public function testMyStepsAreADifferentSetThanMyTickets(): void {
		$tickets = Server::get(TicketMapper::class);

		$mitSchritt = $this->fixture->labelsOfTickets(
			$tickets->findVisibleWithMyOpenSteps(self::BERT, TaskFilter::openOnly()),
		);
		$meineTickets = $this->fixture->labelsOfTickets(
			$tickets->findVisibleAcrossBoards(self::BERT, TaskFilter::openOnly()),
		);

		$this->assertContains('b:public/erna', $mitSchritt, 'Der zugewiesene Schritt fehlt.');
		$this->assertNotContains(
			'b:public/erna',
			$meineTickets,
			'Wenn dieser Vorgang auch in „Meine Tickets" stuende, waere der zweite Lesepfad ueberfluessig — '
			. 'dann waere die Fixture stumpf geworden, nicht der Code richtig.',
		);
	}

	/**
	 * Und derselbe Pfad verraet nichts: Niemand bekommt ueber einen
	 * Arbeitsschritt einen Vorgang zu sehen, den die Regel verbirgt.
	 *
	 * Das ist die eigentliche Zusage dieses Lesepfades. Ein Schritt wird zwar
	 * nur an jemanden vergeben, der den Vorgang sehen darf — aber Rollen
	 * wechseln, und `assigned_role` ist eingefroren. Die Sichtbarkeit muss
	 * deshalb aus dem JOIN kommen und nicht aus der Zuweisung.
	 */
	public function testMyStepsNeverWidensBeyondTheVisibleSet(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::MY_STEPS_OPEN as $userId => $expected) {
			// Erst die **ausgeschriebene** Menge: Eine Teilmengenpruefung allein
			// bliebe gruen, wenn der Schrittfilter ganz entfiele.
			$this->assertTicketLabels(
				$expected,
				$tickets->findVisibleWithMyOpenSteps($userId, TaskFilter::openOnly()),
				$userId . ': Meine Arbeitsschritte',
			);

			// Und die Zusage des Lesepfades: nichts davon liegt ausserhalb der
			// Sichtmenge. Ein Schritt wird zwar nur an jemanden vergeben, der
			// den Vorgang sehen darf — aber Rollen wechseln, und
			// `assigned_role` ist eingefroren.
			foreach ($this->fixture->labelsOfTickets(
				$tickets->findVisibleWithMyOpenSteps($userId, TaskFilter::withClosed()),
			) as $label) {
				$this->assertContains(
					$label,
					$this->visibleAnywhereFor($userId),
					$userId . ': „Meine Arbeitsschritte" zeigt ' . $label . ', obwohl die Regel es verbirgt.',
				);
			}
		}
	}

	/**
	 * Alles, was dieser Betrachter irgendwo sehen darf — ueber beide Boards.
	 *
	 * @return string[]
	 */
	private function visibleAnywhereFor(string $userId): array {
		$tickets = Server::get(TicketMapper::class);

		$labels = $this->fixture->labelsOfTickets(
			$tickets->findVisibleInBoard($this->contextFor($userId)),
		);

		if ($userId === self::BERT) {
			$labels = array_merge($labels, $this->fixture->labelsOfTickets(
				$tickets->findVisibleInBoard($this->fixture->contextFor($userId, $this->fixture->otherBoardId)),
			));
		}

		return $labels;
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
		// Bert ist in **beiden** Boards Mitglied (im zweiten als Kundenseite),
		// alle uebrigen nur im ersten. Die Liste folgt der Mitgliedschaft und
		// sonst nichts.
		$expected = [
			self::ANNA => ['Leak-Matrix'],
			self::BERT => ['Leak-Matrix', 'Leak-Matrix Zweitboard'],
			self::CARLA => ['Leak-Matrix'],
			self::DIRK => ['Leak-Matrix'],
		];

		foreach ($expected as $userId => $titles) {
			$response = $this->boardController($userId)->index();

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $userId);
			$this->assertSame(
				$titles,
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
	 * **Ein geloeschter Vorgang verschwindet aus JEDEM Lesepfad.**
	 *
	 * Das ist die ganze Zusicherung des weichen Loeschens: `deleted_at` wird
	 * allein von `TicketScope::apply()` ausgewertet, und jeder Lesepfad geht
	 * dort durch. Der Test faehrt deshalb nicht einen Pfad, sondern **alle
	 * registrierten** — Board-, Einzel-, Deep-Link- und projektuebergreifende
	 * Abfrage plus die Zaehler.
	 *
	 * Er prueft ausserdem den Weg, der `wouldSee()` benutzt (Schrittzuweisung).
	 * Dort steht **keine** eigene Loeschpruefung, und das ist Absicht: Er
	 * bekommt sein Ticket aus einer bereits gefilterten Abfrage. Dieser Test
	 * belegt, dass die Annahme traegt — ohne ihn waere sie bloss eine
	 * Behauptung im Kommentar.
	 *
	 * Bis #103 stand hier ein zweiter solcher Weg, der Herunterstufen-Dialog
	 * (`visibilityImpact`). Er ist mit der Rueckfrage aufgegeben.
	 */
	public function testADeletedTicketLeavesEveryReadPath(): void {
		$tickets = Server::get(TicketMapper::class);
		$steps = Server::get(StepService::class);
		$service = Server::get(TicketService::class);
		$viewer = $this->contextFor(self::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		// Vorher da, in allen Pfaden.
		$this->assertNotNull($tickets->findVisible($viewer, $ticketId));

		$service->delete($viewer, $ticketId, (int)$tickets->findVisible($viewer, $ticketId)->getVersion());

		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$kontext = $this->contextFor($userId);

			$imBoard = array_map(
				static fn ($ticket): string => (string)$ticket->getTitle(),
				$tickets->findVisibleInBoard($kontext),
			);
			$this->assertNotContains('public/anna', $imBoard, $userId . ': Boardansicht');

			$this->assertNotContains(
				'public/anna',
				array_map(
					static fn ($ticket): string => (string)$ticket->getTitle(),
					$tickets->findVisibleAcrossBoards($userId, TaskFilter::withClosed()),
				),
				$userId . ': projektuebergreifend',
			);

			foreach ([
				fn (): mixed => $tickets->findVisible($kontext, $ticketId),
				fn (): mixed => $tickets->findVisibleAnywhere($userId, $ticketId),
				fn (): mixed => $steps->assignableFor($kontext, $ticketId),
			] as $index => $pfad) {
				try {
					$pfad();
					$this->fail($userId . ' erreicht den geloeschten Vorgang ueber Pfad ' . $index . '.');
				} catch (DoesNotExistException) {
					$this->addToAssertionCount(1);
				}
			}

			// Der Zaehler darf ihn ebenso wenig mitzaehlen (§5.8).
			$this->assertSame(
				count($imBoard),
				$tickets->countVisibleInBoard($kontext),
				$userId . ': Zaehler und Liste weichen ab.',
			);
		}
	}

	/**
	 * Die Kontensuche steht nur internen Verwaltern offen.
	 *
	 * Geprüft wird der **Statuscode**, nicht die Trefferliste: Eine leere Liste
	 * sähe aus wie „niemand gefunden", und wer den Unterschied nicht kennt,
	 * sucht den Fehler bei sich statt bei seinen Rechten. Deshalb 403 für jeden
	 * ohne Verwaltungsrecht — und 404 für das Nichtmitglied, dieselbe Antwort
	 * wie für ein Board, das es nicht gibt.
	 */
	public function testMemberSearchRefusesEveryoneWithoutManagementRights(): void {
		$erwartet = [
			// Anna ist Eigentuemerin und interne Verwalterin.
			self::ANNA => Http::STATUS_OK,
			self::BERT => Http::STATUS_FORBIDDEN,
			self::CARLA => Http::STATUS_FORBIDDEN,
			self::DIRK => Http::STATUS_FORBIDDEN,
			self::FREMD => Http::STATUS_NOT_FOUND,
		];

		foreach ($erwartet as $userId => $status) {
			$response = $this->memberSearchController($userId)->search($this->fixture->boardId, 'lm-');

			$this->assertSame($status, $response->getStatus(), $userId . ' bei der Kontensuche');
		}
	}

	/**
	 * **Wem ein Schritt gegeben werden darf, deckt sich mit „wer das Ticket
	 * sieht".**
	 *
	 * Der Test dreht die Matrix um: Nicht „was sieht dieser Betrachter", sondern
	 * „wer taucht in der Vorschlagsliste dieses Tickets auf". Die Erwartung
	 * entsteht deshalb aus derselben Konstante, nur transponiert — und genau
	 * dieser Vergleich ist der Punkt: Eine Zuweisung an jemanden, der das Ticket
	 * nicht oeffnen kann, ergaebe in „Meine Aufgaben" eine Zeile, die beim
	 * Anklicken 404 liefert.
	 */
	public function testAssignableNeverOffersSomeoneWhoCannotSeeTheTicket(): void {
		$service = Server::get(StepService::class);

		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$viewer = $this->contextFor($userId);

			foreach (self::VISIBLE[$userId] as $label) {
				$vorschlaege = $service->assignableFor($viewer, $this->fixture->ticketIds[$label]);

				// Aus der Matrix abgeleitet: Wer dieses Ticket laut VISIBLE
				// sieht, gehoert in die Liste — und sonst niemand.
				$erwartet = [];
				foreach (self::VISIBLE as $kandidat => $sichtbar) {
					if ($kandidat !== self::FREMD && in_array($label, $sichtbar, true)) {
						$erwartet[] = $kandidat;
					}
				}

				sort($erwartet);
				$tatsaechlich = $vorschlaege;
				sort($tatsaechlich);

				$this->assertSame(
					$erwartet,
					$tatsaechlich,
					$userId . ' bekommt bei ' . $label . ' eine Vorschlagsliste, die von der Sichtbarkeit abweicht.',
				);
			}
		}
	}

	/**
	 * Der Deep-Link-Lesepfad: dieselbe Menge, nur ohne Board-Einschraenkung.
	 *
	 * Der wichtigste Fall steht hier nicht als eigener Zweig, sondern als
	 * Erwartung: **Auch ohne `boardId` faellt niemand durch.** `findVisible()`
	 * bekommt das Board vom Aufrufer und stuetzt sich darauf; hier gibt es
	 * keins, die Regel muss allein aus dem Mitgliedschaftsverbund entstehen.
	 * Genau das ist die Stelle, an der eine Abkuerzung („erst das Board holen,
	 * dann pruefen") unbemerkt zu weit oeffnen wuerde.
	 */
	public function testDeepLinkLookupMatchesTheVisibleSet(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::VISIBLE as $userId => $expected) {
			foreach (LeakMatrixFixture::TICKETS as $label => $_) {
				$ticketId = $this->fixture->ticketIds[$label];

				if (in_array($label, $expected, true)) {
					$this->assertSame(
						$label,
						$tickets->findVisibleAnywhere($userId, $ticketId)->getTitle(),
						$userId . ' / ' . $label,
					);
					continue;
				}

				try {
					$tickets->findVisibleAnywhere($userId, $ticketId);
					$this->fail($userId . ' erreicht ' . $label . ' ueber den Deep-Link-Pfad.');
				} catch (DoesNotExistException) {
					$this->addToAssertionCount(1);
				}
			}
		}
	}

	/**
	 * Der Deep-Link-Endpunkt, fuenf Betrachter x neun Tickets.
	 *
	 * Geprueft wird **der Initial State**, nicht der Statuscode: Die Route
	 * liefert immer dieselbe Huelle und immer 200 — auch fuer ein Ticket, das
	 * es nicht gibt. Ein 404 waere hier die Auskunft „diese Nummer existiert,
	 * du darfst sie nur nicht sehen" bzw. deren Gegenteil, und beides waere
	 * genau die Frage, die die Sichtbarkeitsregel nicht beantworten soll.
	 *
	 * Deshalb ausdruecklich auch: **Nichtmitglied und unbekannte Nummer sehen
	 * gleich aus.** Wer eine Zahl im Link hochzaehlt, lernt daraus nichts.
	 */
	public function testDeepLinkTellsOnlyWhatTheViewerMaySee(): void {
		foreach (self::VISIBLE as $userId => $expected) {
			$state = new CollectingInitialState();
			$controller = new DeepLinkController(
				$this->createStub(IRequest::class),
				Server::get(TicketMapper::class),
				$state,
				$userId,
			);

			foreach (LeakMatrixFixture::TICKETS as $label => $_) {
				$controller->ticket($this->fixture->ticketIds[$label]);
				$target = $state->last();

				if (in_array($label, $expected, true)) {
					$this->assertTrue($target['available'], $userId . ' / ' . $label);
					$this->assertSame($this->fixture->boardId, $target['boardId'], $userId . ' / ' . $label);
				} else {
					$this->assertFalse(
						$target['available'],
						$userId . ' bekommt ' . $label . ' ueber den Deep-Link, darf es aber nicht sehen.',
					);
					$this->assertArrayNotHasKey(
						'boardId',
						$target,
						$userId . ' erfaehrt bei ' . $label . ', in welchem Projekt es liegt.',
					);
				}
			}

			// Eine Nummer, die es nicht gibt, sieht aus wie eine verborgene.
			$controller->ticket(999999);
			$this->assertFalse($state->last()['available'], $userId . ' / unbekannte Nummer');
			$this->assertArrayNotHasKey('boardId', $state->last(), $userId . ' / unbekannte Nummer');
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
	 * **Die Anhaenge-Absage nennt ihre Zahl im Rumpf** (§3.10 Stufe 1).
	 *
	 * Steht hier als Ersatz fuer `testVisibilityImpactNamesWhoLosesAccess`, das
	 * mit `visibility-impact` weggefallen ist (#103). Der Lesepfad, der die
	 * Anhaenge vorab zaehlte, ist aufgegeben — die Oberflaeche erfaehrt den Fall
	 * seither allein aus dieser Antwort.
	 *
	 * **Geprueft wird die Form, nicht nur die Ablehnung.** Der Server
	 * beantwortet zwei verschiedene Faelle mit 409: den Versionskonflikt und
	 * diesen. Wer sie unterscheiden will, hat nur das Feld `attachments` — faellt
	 * es weg, meldet die Oberflaeche der Person mit Anhaengen „bitte neu laden",
	 * und Neuladen hilft nichts. `TicketWritePathTest` prueft die Ausnahme am
	 * Dienst; hier steht, was ueber die Leitung geht.
	 */
	public function testTheAttachmentRefusalCarriesItsCountOverTheWire(): void {
		$controller = $this->ticketController(self::ANNA);
		$boardId = $this->fixture->boardId;
		$publicAnna = $this->fixture->ticketIds['public/anna'];

		// Die Fixture haengt genau einen Anhang an dieses Ticket.
		$response = $controller->visibility($boardId, $publicAnna, 1, 'internal');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$daten = $response->getData();
		$this->assertArrayHasKey('attachments', $daten, 'Ohne die Zahl ist die Absage vom Versionskonflikt nicht zu trennen.');
		$this->assertSame(1, $daten['attachments']);
		$this->assertNotSame('', (string)($daten['error'] ?? ''));

		// Und der Vorgang steht unveraendert da.
		$this->assertSame(
			TicketScope::VISIBILITY_PUBLIC,
			Server::get(TicketMapper::class)->findVisible($this->contextFor(self::ANNA), $publicAnna)->getVisibility(),
		);
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
	private function memberSearchController(string $userId): MemberSearchController {
		return new MemberSearchController(
			$this->createStub(IRequest::class),
			Server::get(IUserManager::class),
			Server::get(IConfig::class),
			Server::get(MemberMapper::class),
			Server::get(BoardAccess::class),
			$userId,
		);
	}

	private function taskController(string $userId): TaskController {
		return new TaskController(
			$this->createStub(IRequest::class),
			Server::get(TicketMapper::class),
			Server::get(StepMapper::class),
			Server::get(BoardMapper::class),
			$userId,
		);
	}

	private function boardController(string $userId): BoardController {
		return new BoardController(
			$this->createStub(IRequest::class),
			Server::get(BoardMapper::class),
			Server::get(MemberService::class),
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
			Server::get(WaitStateCalculator::class),
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
