<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ChangeHighlighter;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Access\WaitStateCalculator;
use OCA\Projektwerk\Controller\BoardController;
use OCA\Projektwerk\Controller\DeepLinkController;
use OCA\Projektwerk\Controller\MemberSearchController;
use OCA\Projektwerk\Controller\SettingsController;
use OCA\Projektwerk\Controller\OverviewController;
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
use OCA\Projektwerk\Db\TicketReadMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCA\Projektwerk\Service\AttachmentService;
use OCA\Projektwerk\Service\BoardPinService;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\ColumnService;
use OCA\Projektwerk\Service\GithubService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\NotifyPrefService;
use OCA\Projektwerk\Service\ProjectFolderService;
use OCA\Projektwerk\Service\StepService;
use OCA\Projektwerk\Service\TicketService;
use OCA\Projektwerk\Tests\ReadPathRegistry;
use OCA\Projektwerk\AppInfo\Application;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\Config\IUserConfig;
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

	/**
	 * Was jeder Betrachter **wiederherstellen** darf (#167) — und warum das
	 * anders geschnitten ist als {@see VISIBLE}.
	 *
	 * `findForRestore` bindet an **Board + Erzeugerrolle**, nicht an die
	 * Sichtbarkeitsregel: Es findet auch Geloeschtes und joint bewusst nicht auf
	 * die Mitgliedschaft. Deshalb sind Annas und Berts Mengen hier **identisch**
	 * — beide sind intern, und es zaehlt die Rolle, nicht die Person; eine
	 * interne Person stellt jeden intern erzeugten Vorgang ihres Boards wieder
	 * her, auch einen fremden. Carla und Dirk als Kundenseite ebenso ueber die
	 * externe Erzeugerrolle.
	 *
	 * FREMD steht hier **nicht**: Sein Betrachterkontext existiert im Betrieb
	 * nicht (`BoardAccess::contextFor` wirft fuer ein Nichtmitglied, der
	 * Restore-Endpunkt endet mit 404, bevor der Mapper laeuft). Die
	 * Mitgliedschaftsgrenze ist eine Schicht hoeher, nicht in diesem Finder — ihn
	 * hier von Hand zu bauen pruefte einen Zustand, den es nicht gibt.
	 *
	 * @var array<string, string[]>
	 */
	private const RESTORABLE = [
		self::ANNA => ['public/anna', 'public/bert', 'internal/anna', 'internal/bert', 'private/anna', 'private/bert'],
		self::BERT => ['public/anna', 'public/bert', 'internal/anna', 'internal/bert', 'private/anna', 'private/bert'],
		self::CARLA => ['public/carla', 'internal/carla', 'private/carla'],
		self::DIRK => ['public/carla', 'internal/carla', 'private/carla'],
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
		'TicketMapper::findVisibleAcrossBoardsAll' => 'testTheOverviewMapperNeverWidensBeyondTheVisibleSet',
		'MemberMapper::findForUserBoards' => 'testMemberNamesCoverOnlyMyOwnBoards',
		'TicketMapper::findVisibleWithMyOpenSteps' => 'testMyStepsNeverWidensBeyondTheVisibleSet',
		'TicketMapper::findVisibleAnywhere' => 'testDeepLinkLookupMatchesTheVisibleSet',
		'TicketMapper::findForRestore' => 'testRestoreLookupIsScopedToBoardAndOwningRole',
		'TicketMapper::countVisibleInBoard' => 'testCountersNeverCountWhatIsHidden',
		'TicketMapper::findLastPositionInColumn' => 'testLastPositionIsTheSameForEveryViewer',
		// zusaetzlich gefahren von testBothCompanyNamesReachEveryViewer
		'BoardMapper::findForViewer' => 'testBoardMetadataPathsTrustTheContextAlone',
		'BoardMapper::findAllForUser' => 'testBoardListFollowsMembership',
		'MemberMapper::findForBoard' => 'testBoardMetadataPathsTrustTheContextAlone',
		'ColumnMapper::findForBoard' => 'testBoardMetadataPathsTrustTheContextAlone',
		'CommentMapper::findForTickets' => 'testChildrenFollowTheFilteredTicketSet',
		'CommentMapper::countForTickets' => 'testChildCountersFollowTheFilteredTicketSet',
		'CommentMapper::findNewestForTickets' => 'testNewestCommentFollowsTheFilteredTicketSet',
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
		'TicketReadMapper::findSeenForTickets' => 'testReadStateIsScopedToItsOwner',
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
		'step#assignableForNew' => 'testAssignableForNewFollowsTheChosenVisibility',
		'memberSearch#search' => 'testMemberSearchRefusesEveryoneWithoutManagementRights',
		'settings#memberRemovalImpact' => 'testRemovalImpactRefusesEveryoneWithoutManagementRights',
		'notifyPref#index' => 'testEveryViewerSeesOnlyTheirOwnChannelSwitches',
		'privateFolder#index' => 'testThePrivateFolderPathIsScopedToItsOwner',
		'githubToken#index' => 'testTheGithubTokenPresenceIsScopedToItsOwner',
		'task#index' => 'testTaskEndpointMatchesTheVisibleSetAcrossBoards',
		'overview#index' => 'testOverviewEndpointMatchesTheVisibleSetAcrossBoards',
	];

	/**
	 * Der Ueberblick (#76) — **alles Sichtbare** ueber alle Boards, nur Offenes.
	 *
	 * Die breiteste Menge dieser Matrix und deshalb die wichtigste Zeile: Jede
	 * andere Leseroute engt zusaetzlich ein (auf ein Board, auf meine Vorgaenge,
	 * auf meine Schritte). Diese engt **nur** ueber die Sichtbarkeitsregel ein.
	 * Faellt die Regel aus, faellt sie hier zuerst auf.
	 *
	 * Der Unterschied zu {@see VISIBLE} ist genau `public/carla` — geschlossen,
	 * und der Ueberblick zeigt, wo etwas hakt, nicht was erledigt ist.
	 *
	 * **Bert traegt wieder den Kern.** Er ist im ersten Board intern, im zweiten
	 * extern, und bekommt aus einer Abfrage je Board die dort geltende Menge:
	 * `b:internal/bert` sieht er nur, weil er *dort* die Kundenseite ist;
	 * `b:internal/erna` sieht er aus demselben Grund **nicht**. Wer die Rolle
	 * einmal global aufloest, vertauscht die beiden.
	 *
	 * @var array<string, string[]>
	 */
	private const OVERVIEW_OPEN = [
		self::ANNA => ['public/anna', 'public/bert', 'internal/anna', 'internal/bert', 'private/anna'],
		self::BERT => ['public/anna', 'public/bert', 'internal/anna', 'internal/bert', 'private/bert', 'b:public/erna', 'b:internal/bert'],
		self::CARLA => ['public/anna', 'public/bert', 'internal/carla', 'private/carla'],
		self::DIRK => ['public/anna', 'public/bert', 'internal/carla'],
		self::FREMD => [],
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
	 * Der Lesestand (#79) gehört seiner Person — niemand sieht den einer anderen.
	 *
	 * Dieselbe Art Zusage wie bei den Kanalschaltern: Der erste Parameter ist
	 * eine Benutzerkennung, und die ist die Grenze. Ein Stand entsteht nur beim
	 * Öffnen, und öffnen kann jemand nur, was er sieht.
	 */
	public function testReadStateIsScopedToItsOwner(): void {
		$reads = Server::get(TicketReadMapper::class);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$reads->markSeen(LeakMatrixFixture::ANNA, $ticketId);

		// Anna sieht ihren eigenen Stand …
		$this->assertSame(
			[$ticketId],
			array_keys($reads->findSeenForTickets(LeakMatrixFixture::ANNA, [$ticketId])),
		);
		// … Carla, die denselben öffentlichen Vorgang sieht, aber nie geöffnet
		// hat, bekommt nichts — schon gar nicht Annas Stand.
		$this->assertSame(
			[],
			$reads->findSeenForTickets(LeakMatrixFixture::CARLA, [$ticketId]),
			'Carla darf Annas Lesestand nicht sehen.',
		);
	}

	/**
	 * Der jüngste Kommentar je Vorgang (#79) folgt der gefilterten Menge — kein
	 * Zeitstempel zu einem Vorgang, den der Betrachter nicht sieht.
	 *
	 * Gefüttert mit den sichtbaren IDs, wie die übrigen Kinder-Pfade; die
	 * Antwort darf keine ID enthalten, die nicht darin steht.
	 */
	public function testNewestCommentFollowsTheFilteredTicketSet(): void {
		$comments = Server::get(CommentMapper::class);
		$tickets = Server::get(TicketMapper::class);

		foreach (array_keys(self::VISIBLE) as $userId) {
			$visibleIds = array_map(
				static fn ($ticket): int => (int)$ticket->getId(),
				$tickets->findVisibleInBoard($this->contextFor($userId)),
			);

			foreach (array_keys($comments->findNewestForTickets($visibleIds)) as $id) {
				$this->assertContains(
					$id,
					$visibleIds,
					$userId . ': jüngster Kommentar zu einem nicht sichtbaren Vorgang',
				);
			}
		}
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
	 * **Der eigene Ordner für private Anhänge ist an die Person gebunden** (#184).
	 *
	 * Wie die Kanalschalter: kein Board im Pfad, die Grenze ist die
	 * Benutzerkennung. Jede Person liest nur ihren eigenen Pfad; wer keinen
	 * gewählt hat, bekommt die Vorgabe — nie den Ordner einer anderen.
	 *
	 * Gesetzt wird direkt über `IUserConfig`, nicht über `setPrivatePath()`: Das
	 * legte einen echten Ordner im Dateibaum an, den die Fixture-Mitglieder gar
	 * nicht haben. Geprüft wird die **Zuordnung** des Werts zur Person, nicht die
	 * Ordner-Auflösung — die steht im AttachmentRelocationTest gegen echte Ordner.
	 */
	public function testThePrivateFolderPathIsScopedToItsOwner(): void {
		$folders = Server::get(ProjectFolderService::class);
		$config = Server::get(IUserConfig::class);

		$config->setValueString(self::ANNA, Application::APP_ID, 'private_attachment_folder', 'Anna/Privat');
		$config->setValueString(self::CARLA, Application::APP_ID, 'private_attachment_folder', 'Carla/Geheim');

		$this->assertSame('Anna/Privat', $folders->privatePath(self::ANNA));
		$this->assertSame('Carla/Geheim', $folders->privatePath(self::CARLA), 'Carla sieht ihren eigenen Pfad, nicht Annas.');

		// Bert hat nichts gesetzt — die Vorgabe, nicht der Ordner einer anderen.
		$this->assertSame(
			ProjectFolderService::DEFAULT_PRIVATE_FOLDER,
			$folders->privatePath(self::BERT),
		);
	}

	/**
	 * Der GitHub-Token (#12) — dieselbe Art Grenze wie beim privaten Ordner:
	 * kein Board, keine Rolle, nur die Benutzerkennung. Jeder sieht **nur
	 * seinen eigenen** Stand, und der Endpunkt verrät ohnehin nur, OB ein Token
	 * hinterlegt ist, nie den Token selbst.
	 */
	public function testTheGithubTokenPresenceIsScopedToItsOwner(): void {
		$github = Server::get(GithubService::class);

		$github->storeToken(self::ANNA, 'ghp_anna_secret');

		$this->assertTrue($github->hasToken(self::ANNA));
		$this->assertFalse(
			$github->hasToken(self::BERT),
			'Bert sieht Annas Token nicht — nicht einmal, dass es ihn gibt.',
		);

		// Nicht über den Lauf hinaus stehen lassen.
		$github->deleteToken(self::ANNA);
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
	 * Der Wiederherstell-Abruf (#167) — vier Betrachter x **alle** Fixture-Tickets.
	 *
	 * `findForRestore` geht bewusst am Sichtbarkeits-Scope vorbei: kein Join auf
	 * die Mitgliedschaft (sonst faende es Geloeschtes nie wieder), kein
	 * Deleted-Filter. Es traegt stattdessen eine engere, andere Bedingung —
	 * **Board + Erzeugerrolle**. Jede Kombination muss das Ticket liefern **oder**
	 * `DoesNotExistException` werfen; die Fehlerform verraet nicht, ob es das
	 * Ticket nicht gibt oder es nur nicht das eigene Board/die eigene Rolle ist.
	 *
	 * Zwei Belege stecken in der Iteration ueber **alle** Tickets: Die
	 * `b:`-Tickets liegen auf dem Zweitboard, jeder Kontext hier traegt das erste
	 * — sie muessen ausnahmslos werfen (Beleg fuer die Board-Bedingung). Und dass
	 * Anna Berts intern erzeugte Vorgaenge findet, aber keinen der Kundenseite,
	 * ist der Beleg fuer die Rollen-Bedingung.
	 *
	 * FREMD fehlt bewusst — Begruendung bei {@see RESTORABLE}.
	 */
	public function testRestoreLookupIsScopedToBoardAndOwningRole(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::RESTORABLE as $userId => $expected) {
			$context = $this->fixture->contextFor($userId);

			foreach ($this->fixture->ticketIds as $label => $ticketId) {
				$mayRestore = in_array($label, $expected, true);

				try {
					$ticket = $tickets->findForRestore($context, $ticketId);
					$this->assertTrue(
						$mayRestore,
						$userId . ' hat ' . $label . ' zum Wiederherstellen geladen, darf es aber nicht.',
					);
					$this->assertSame($label, $ticket->getTitle());
				} catch (DoesNotExistException) {
					$this->assertFalse(
						$mayRestore,
						$userId . ' bekam DoesNotExistException auf ' . $label . ', darf es aber wiederherstellen.',
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
	 * Der Mapper hinter dem Ueberblick — **dieselbe Erwartung wie am Endpunkt,
	 * eine Schicht tiefer** (#76).
	 *
	 * Beide stehen da, und das ist Absicht: Der Endpunkt koennte nachtraeglich
	 * filtern und damit einen zu breiten Mapper verdecken. Genau das tut er
	 * sogar — er laesst archivierte Projekte weg. Die Fixture hat keine, die
	 * Mengen sind hier also gleich; waere nur der Endpunkt geprueft, liesse
	 * sich die Regel im Mapper aufweichen, ohne dass ein Test rot wird.
	 */
	public function testTheOverviewMapperNeverWidensBeyondTheVisibleSet(): void {
		$tickets = Server::get(TicketMapper::class);

		foreach (self::OVERVIEW_OPEN as $userId => $expected) {
			$this->assertTicketLabels(
				$expected,
				$tickets->findVisibleAcrossBoardsAll($userId, TaskFilter::openOnly()),
				$userId . ': Der Ueberblick am Mapper',
			);
		}
	}

	/**
	 * **Die Namensliste des Ueberblicks deckt nur die eigenen Projekte** (#76).
	 *
	 * `findForUserBoards()` liest ueber alle Boards des Betrachters auf einmal —
	 * noetig, weil die Wartemarke Namen nennt (#104) und ein Aufruf je Board bei
	 * ueber zwanzig Projekten keine Loesung waere.
	 *
	 * Die Gefahr steckt in der Unterabfrage: Faellt sie weg, liefert die Methode
	 * **jede** Mitgliedschaft der Instanz. Das waeren keine Ticketdaten, aber
	 * die Mitgliederliste fremder Projekte — namentlich, auf der Startseite.
	 *
	 * Anna traegt die Zusicherung: Sie ist nur im ersten Board und darf Erna,
	 * die es nur im zweiten gibt, nirgends sehen. Bert ist in beiden und
	 * bekommt beide.
	 */
	public function testMemberNamesCoverOnlyMyOwnBoards(): void {
		$members = Server::get(MemberMapper::class);
		$erwartet = [
			self::ANNA => [$this->fixture->boardId],
			self::BERT => [$this->fixture->boardId, $this->fixture->otherBoardId],
			self::CARLA => [$this->fixture->boardId],
			self::DIRK => [$this->fixture->boardId],
			self::FREMD => [],
		];

		foreach ($erwartet as $userId => $boards) {
			$gefunden = $members->findForUserBoards($userId);

			$boardIds = array_values(array_unique(array_map(
				static fn ($m): int => (int)$m->getBoardId(),
				$gefunden,
			)));
			sort($boardIds);
			sort($boards);

			$this->assertSame($boards, $boardIds, $userId . ': fremde Projekte in der Namensliste');

			// Und die Gegenprobe zur Gegenprobe: Wer Boards hat, bekommt auch
			// Mitglieder. Ohne sie waere eine Methode, die immer nichts
			// liefert, dauerhaft gruen.
			if ($boards !== []) {
				$this->assertNotEmpty($gefunden, $userId . ': keine Mitglieder trotz eigener Projekte');
			}
		}

		// Anna sieht Erna nicht — namentlich benannt, damit ein Fehlschlag die
		// Ursache nennt statt einer ID-Liste.
		$annasLeute = array_map(
			static fn ($m): string => (string)$m->getUserId(),
			$members->findForUserBoards(self::ANNA),
		);
		$this->assertNotContains(
			LeakMatrixFixture::ERNA,
			$annasLeute,
			'Anna bekommt ein Mitglied aus Ernas Zweitboard in ihre Namensliste.',
		);
	}

	/**
	 * Der Ueberblick (#76) — **die breiteste Menge dieser Matrix**.
	 *
	 * Jede andere Leseroute engt zusaetzlich ein: auf ein Board, auf meine
	 * Vorgaenge, auf meine Schritte. Diese engt allein ueber die
	 * Sichtbarkeitsregel ein — sie ist damit die Route, an der ein Ausfall der
	 * Regel als Erstes und am deutlichsten sichtbar wuerde. Und sie ist die
	 * **Startseite**: Was hier durchscheint, sieht man, ohne etwas anzuklicken.
	 *
	 * Drei Zusicherungen, von denen die letzten beiden neu sind:
	 *
	 * 1. **Die Ticketmenge** ist ausgeschrieben, nicht als Teilmenge geprueft.
	 * 2. **Kein Wartezustand ohne seinen Vorgang.** Der Wartezustand nennt
	 *    Kennungen von Personen und ein Datum; stuende er zu einem Vorgang da,
	 *    den der Betrachter nicht sehen darf, waere das die Auskunft, dass es
	 *    ihn gibt — und wer daran arbeitet.
	 * 3. **Die Namen decken nur die eigenen Projekte.** `namesForUserBoards()`
	 *    ist mit #76 dazugekommen und liest ueber alle Boards des Betrachters
	 *    auf einmal. Eine Zuordnung, die ein fremdes Projekt mitbringt,
	 *    verriete dessen Mitglieder — namentlich. Anna darf Ernas Zweitboard
	 *    nicht in ihrer Namensliste haben, Bert schon.
	 */
	public function testOverviewEndpointMatchesTheVisibleSetAcrossBoards(): void {
		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$response = $this->overviewController($userId)->index();
			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $userId);

			$data = $response->getData();

			$this->assertTicketLabels(
				self::OVERVIEW_OPEN[$userId],
				$data['tickets'],
				$userId . ': Der Ueberblick am Endpunkt',
			);

			// (2) Jeder Wartezustand gehoert zu einem gelieferten Vorgang.
			$geliefert = array_map(static fn ($t): int => (int)$t->getId(), $data['tickets']);
			foreach (array_keys($data['waiting']) as $ticketId) {
				$this->assertContains(
					(int)$ticketId,
					$geliefert,
					$userId . ': Ein Wartezustand ohne seinen Vorgang — das verraet dessen Existenz.',
				);
			}

			// (3) Die Namen decken genau die eigenen Projekte.
			$this->assertSame(
				self::TASK_BOARDS[$userId],
				array_values(array_map(static fn ($b): string => $b['title'], $data['boards'])),
				$userId . ': Ein fremdes Board in der Herkunftszeile.',
			);
			$this->assertSame(
				array_keys($data['boards']),
				array_keys($data['names']),
				$userId . ': Die Namensliste deckt andere Projekte als die Herkunftszeile — '
				. 'eines von beidem liefert ein fremdes Projekt mit.',
			);
		}

		// Und das Nichtmitglied: leere Listen, kein 404 — wie bei `task#index`
		// und aus demselben Grund. Diese Route haengt an keinem Board, sie kann
		// nichts verbergen, was es zu verbergen gaebe.
		$fremd = $this->overviewController(self::FREMD)->index();
		$this->assertSame(Http::STATUS_OK, $fremd->getStatus());
		$this->assertSame([], $fremd->getData()['tickets']);
		$this->assertSame([], $fremd->getData()['waiting']);
		$this->assertSame([], $fremd->getData()['boards']);
		$this->assertSame([], $fremd->getData()['names']);
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
				// Seit #115 liefert index() Arrays (jsonSerialize + pinned), keine
				// Entities — die HTTP-Ausgabe ist dieselbe, die Rohform hier nicht.
				array_map(static fn (array $b): string => (string)$b['title'], $response->getData()),
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
	 * Die bezifferte Vorschau vorm Entfernen (§5.29) steht nur internen
	 * Verwaltern offen — wie das Entfernen selbst.
	 *
	 * Gezählt werden nur die privaten Vorgänge der **Zielperson**; die Zahl an
	 * sich verriete einem Fremden nichts über andere Vorgänge, aber der Weg
	 * dorthin ist eine Verwaltungshandlung, und nur die trägt sie. Deshalb 403
	 * für jeden ohne Verwaltungsrecht und 404 für das Nichtmitglied — dieselbe
	 * Grenze wie bei der Kontensuche. Zielperson ist Bert (ein Mitglied, nicht
	 * der Eigentümer), damit die Rechteprüfung greift und nicht der
	 * Eigentümerschutz.
	 */
	public function testRemovalImpactRefusesEveryoneWithoutManagementRights(): void {
		$erwartet = [
			self::ANNA => Http::STATUS_OK,
			self::BERT => Http::STATUS_FORBIDDEN,
			self::CARLA => Http::STATUS_FORBIDDEN,
			self::DIRK => Http::STATUS_FORBIDDEN,
			self::FREMD => Http::STATUS_NOT_FOUND,
		];

		foreach ($erwartet as $userId => $status) {
			$response = $this->settingsController($userId)->memberRemovalImpact($this->fixture->boardId, self::BERT);

			$this->assertSame($status, $response->getStatus(), $userId . ' bei der Entfernen-Vorschau');
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
	 * **Der Verantwortlichen-Picker im Anlege-Dialog folgt der gewaehlten
	 * Sichtbarkeit (#146).**
	 *
	 * Bevor es ein Ticket gibt, kann der Picker nicht am geladenen Vorgang
	 * pruefen — er fragt gegen einen **gedachten** Vorgang, dessen Ersteller der
	 * Betrachter ist. Die Erwartung ist genau die Sichtbarkeitsregel, angewandt
	 * auf diese Lage:
	 *
	 * - `public`   → alle Mitglieder, intern und extern ohne Trennung,
	 * - `internal` → nur die eigene Seite des Anlegenden,
	 * - `private`  → nur er selbst.
	 *
	 * Faellt das auseinander, boete der Dialog jemanden an, den der Schreibpfad
	 * danach mit `mayBecomeResponsible` ablehnte — dieselbe Luecke wie eine
	 * zweite Fassung der Regel im Frontend.
	 */
	public function testAssignableForNewFollowsTheChosenVisibility(): void {
		$service = Server::get(StepService::class);

		// Die Mitglieder des Hauptboards mit ihrer eingefrorenen Rolle.
		$intern = [self::ANNA, self::BERT];
		$extern = [self::CARLA, self::DIRK];
		$alle = array_merge($intern, $extern);

		foreach ([self::ANNA, self::BERT, self::CARLA, self::DIRK] as $userId) {
			$viewer = $this->contextFor($userId);
			$eigeneSeite = in_array($userId, $intern, true) ? $intern : $extern;

			$erwartung = [
				TicketScope::VISIBILITY_PUBLIC => $alle,
				TicketScope::VISIBILITY_INTERNAL => $eigeneSeite,
				TicketScope::VISIBILITY_PRIVATE => [$userId],
			];

			foreach ($erwartung as $visibility => $soll) {
				$tatsaechlich = $service->assignableForNew($viewer, $visibility);
				sort($tatsaechlich);
				sort($soll);

				$this->assertSame(
					$soll,
					$tatsaechlich,
					$userId . ' bekommt fuer einen neuen ' . $visibility . '-Vorgang eine '
						. 'Zustaendigen-Auswahl, die von der Sichtbarkeit abweicht.',
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
	 * **Fehlt der Zielordner, kommt die Absage als 400 mit Meldung über die
	 * Leitung** (#185).
	 *
	 * Seit #185 zieht ein Anhang mit der Sichtbarkeit um, statt den Wechsel zu
	 * sperren. Geht das nicht — die Zielstufe hat keinen Ablageort, hier weil das
	 * Fixture-Board keine Ordner hinterlegt hat —, weist der Server mit **400**
	 * ab und legt die Meldung bei. **Nicht 409**: Ein 409 läse die Oberfläche als
	 * Versionskonflikt („bitte neu laden") und verschluckte die eigentliche
	 * Meldung. Und **kein** `attachments`-Feld mehr — die alte Zahl-im-Rumpf-Form
	 * ist mit der Sperre weg. `TicketWritePathTest` prüft die Ausnahme am Dienst;
	 * hier steht, was über die Leitung geht.
	 */
	public function testAVisibilityChangeWithoutATargetFolderIsRefusedOverTheWire(): void {
		$controller = $this->ticketController(self::ANNA);
		$boardId = $this->fixture->boardId;
		$publicAnna = $this->fixture->ticketIds['public/anna'];

		// Die Fixture haengt genau einen Anhang an dieses Ticket, das Board hat
		// aber keinen internen Ordner — der Umzug hat kein Ziel.
		$response = $controller->visibility($boardId, $publicAnna, 1, 'internal');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$daten = $response->getData();
		$this->assertArrayNotHasKey('attachments', $daten, 'Die Zahl-im-Rumpf-Form ist mit der Sperre weg (#185).');
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

	private function settingsController(string $userId): SettingsController {
		return new SettingsController(
			$this->createStub(IRequest::class),
			Server::get(BoardService::class),
			Server::get(ColumnService::class),
			Server::get(MemberService::class),
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

	private function overviewController(string $userId): OverviewController {
		return new OverviewController(
			$this->createStub(IRequest::class),
			Server::get(TicketMapper::class),
			Server::get(StepMapper::class),
			Server::get(BoardMapper::class),
			Server::get(WaitStateCalculator::class),
			Server::get(MemberService::class),
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
			Server::get(BoardPinService::class),
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
			Server::get(TicketReadMapper::class),
			Server::get(TicketService::class),
			Server::get(AttachmentService::class),
			Server::get(WaitStateCalculator::class),
			Server::get(ChangeHighlighter::class),
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
