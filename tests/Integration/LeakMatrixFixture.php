<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Comment;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketUser;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCP\Server;

/**
 * Die Datenlage der Leak-Matrix: ein Board, vier Mitglieder, neun Tickets.
 *
 * Die Zahlen stammen aus #5 und sind nicht beliebig gewaehlt:
 *
 * **Neun Tickets** = drei Sichtbarkeiten x drei Erzeuger-Konstellationen. Die
 * dritte Konstellation ist der Punkt: Es genuegt nicht, „intern" und „extern"
 * als Erzeuger zu haben — es braucht **zwei interne**, weil nur dann sichtbar
 * wird, ob `internal` symmetrisch ist (Bert sieht Annas internes Ticket) oder
 * versehentlich an die erzeugende Person gebunden (dann saehe er es nicht).
 *
 * **Vier Mitglieder** aus demselben Grund, gespiegelt: zwei interne und zwei
 * externe. Dirk ist der externe Nicht-Erzeuger und damit Carlas Spiegelbild von
 * Bert zu Anna. Ohne ihn liesse sich nicht unterscheiden, ob ein externes
 * Mitglied ein internes Ticket der Kundenseite sieht, weil die Regel stimmt,
 * oder weil es zufaellig selbst der Erzeuger war.
 *
 * **Dirk arbeitet an allen neun Tickets mit** (`pwerk_ticket_users`). Das ist
 * der schaerfste Einzelfall der ganzen Matrix: „Meine Aufgaben" verbindet auf
 * `pwerk_ticket_users` und muesste ihm ohne die Regel alle neun liefern —
 * einschliesslich Annas privatem Ticket. Er darf vier sehen.
 *
 * **Ein geschlossenes Ticket** (`public/carla`), damit `TaskFilter` nicht
 * ungeprueft bleibt: Ein Filter, der versehentlich nichts filtert, faellt sonst
 * nicht auf.
 *
 * Angelegt wird ueber die **Mapper**, nicht per rohem SQL — so laeuft die
 * Fixture durch denselben Entity-Pfad wie der Produktivcode. Ein Feldname, der
 * in der Entity nicht zur Migrationsspalte passt, faellt hier auf und nicht
 * erst beim ersten Schreibpfad.
 */
final class LeakMatrixFixture {

	public const ANNA = 'lm-anna';
	public const BERT = 'lm-bert';
	public const CARLA = 'lm-carla';
	public const DIRK = 'lm-dirk';

	/**
	 * Ein Nichtmitglied. Taucht in keiner Zeile von `pwerk_members` auf.
	 *
	 * Die Matrix baut ihm trotzdem einen `ViewerContext` — von Hand, an
	 * `BoardAccess` vorbei. Das ist kein realistischer Ablauf, sondern die Probe
	 * auf die **zweite** Sperre: Faellt ein Nichtmitglied auch dann noch aus dem
	 * Ergebnis, wenn die erste umgangen wurde?
	 */
	public const FREMD = 'lm-fremd';

	public const COLUMN_A = 'Offen';
	public const COLUMN_B = 'Erledigt';

	/**
	 * Die neun Tickets: Bezeichnung => [Sichtbarkeit, Erzeuger, Erzeugerrolle, Spalte, geschlossen].
	 *
	 * Die Bezeichnung ist `sichtbarkeit/erzeuger` und zugleich der Titel — eine
	 * fehlgeschlagene Erwartung liest sich damit als Satz und nicht als
	 * ID-Liste.
	 */
	public const TICKETS = [
		'public/anna' => [TicketScope::VISIBILITY_PUBLIC, self::ANNA, ViewerContext::ROLE_INTERNAL, self::COLUMN_A, false],
		'public/bert' => [TicketScope::VISIBILITY_PUBLIC, self::BERT, ViewerContext::ROLE_INTERNAL, self::COLUMN_B, false],
		'public/carla' => [TicketScope::VISIBILITY_PUBLIC, self::CARLA, ViewerContext::ROLE_EXTERNAL, self::COLUMN_A, true],
		'internal/anna' => [TicketScope::VISIBILITY_INTERNAL, self::ANNA, ViewerContext::ROLE_INTERNAL, self::COLUMN_B, false],
		'internal/bert' => [TicketScope::VISIBILITY_INTERNAL, self::BERT, ViewerContext::ROLE_INTERNAL, self::COLUMN_A, false],
		'internal/carla' => [TicketScope::VISIBILITY_INTERNAL, self::CARLA, ViewerContext::ROLE_EXTERNAL, self::COLUMN_B, false],
		'private/anna' => [TicketScope::VISIBILITY_PRIVATE, self::ANNA, ViewerContext::ROLE_INTERNAL, self::COLUMN_A, false],
		'private/bert' => [TicketScope::VISIBILITY_PRIVATE, self::BERT, ViewerContext::ROLE_INTERNAL, self::COLUMN_B, false],
		'private/carla' => [TicketScope::VISIBILITY_PRIVATE, self::CARLA, ViewerContext::ROLE_EXTERNAL, self::COLUMN_A, false],
	];

	public const ORG_INTERNAL = 'cpcMomentum';
	public const ORG_EXTERNAL = 'Mueller Elektrotechnik';

	/**
	 * Mitglied => [Rolle, Verwaltungsrecht, Name fuer dieses Board].
	 *
	 * Bert traegt bewusst **keinen** Namen: Das ist der Normalfall (Anzeigename
	 * aus Nextcloud) und muss neben dem uebersteuerten Fall stehen, sonst
	 * prueft die Suite nur den Sonderfall.
	 */
	private const MEMBERS = [
		self::ANNA => [ViewerContext::ROLE_INTERNAL, true, 'Anna Reuter'],
		self::BERT => [ViewerContext::ROLE_INTERNAL, false, null],
		self::CARLA => [ViewerContext::ROLE_EXTERNAL, false, 'Carla Mueller'],
		self::DIRK => [ViewerContext::ROLE_EXTERNAL, false, 'Dirk Sommer'],
	];

	/**
	 * **Das zweite Board — und der einzige Grund, warum es existiert.**
	 *
	 * Bert ist hier **extern**, waehrend er im ersten Board **intern** ist. Das
	 * ist die Konstellation aus dem Akzeptanzkriterium von Phase 4: Wer Berts
	 * Rolle *einmal* aufloest statt je Board, bekommt hier das falsche Ergebnis
	 * — und zwar in **beide** Richtungen zugleich:
	 *
	 * | Ticket | Bert sieht es | weil |
	 * |---|---|---|
	 * | `b:internal/erna` | **nein** | `internal` + Erzeugerrolle intern; Bert ist hier die Kundenseite |
	 * | `b:internal/bert` | **ja** | `internal` + Erzeugerrolle extern, und das ist hier seine |
	 *
	 * Genau diese beiden Zeilen kann keine Implementierung erfuellen, die die
	 * Rolle global bestimmt: Wer ihn ueberall intern nimmt, sieht Zeile 1
	 * faelschlich und Zeile 2 nicht; wer ihn ueberall extern nimmt, umgekehrt
	 * — und im ersten Board fiele dann `internal/anna` weg.
	 *
	 * Erna ist die interne Seite dieses Boards. Sie taucht im ersten Board
	 * nicht auf: Ein Mitglied, das in beiden Boards dieselbe Rolle traegt,
	 * beantwortet die Frage nicht, um die es hier geht.
	 */
	public const ERNA = 'lm-erna';

	public const B_COLUMN = 'Offen';

	/**
	 * Die Tickets des zweiten Boards: Bezeichnung => [Sichtbarkeit, Erzeuger, Erzeugerrolle].
	 *
	 * Alle offen, alle in einer Spalte — dieses Board prueft **nur** die Rolle
	 * je Board, nicht noch einmal Spalten, Zaehler und Schliessen. Das erste
	 * Board deckt das ab, und eine Fixture, die alles zweimal prueft, verdoppelt
	 * die Wartung ohne eine Frage mehr zu beantworten.
	 */
	public const B_TICKETS = [
		'b:public/erna' => [TicketScope::VISIBILITY_PUBLIC, self::ERNA, ViewerContext::ROLE_INTERNAL],
		'b:internal/erna' => [TicketScope::VISIBILITY_INTERNAL, self::ERNA, ViewerContext::ROLE_INTERNAL],
		'b:internal/bert' => [TicketScope::VISIBILITY_INTERNAL, self::BERT, ViewerContext::ROLE_EXTERNAL],
		'b:private/erna' => [TicketScope::VISIBILITY_PRIVATE, self::ERNA, ViewerContext::ROLE_INTERNAL],
	];

	/** Mitglied => [Rolle, Verwaltungsrecht] im zweiten Board. */
	private const B_MEMBERS = [
		self::ERNA => [ViewerContext::ROLE_INTERNAL, true],
		self::BERT => [ViewerContext::ROLE_EXTERNAL, false],
	];

	public int $boardId;

	/** Das Projekt des ersten Boards (#246 PR 2). */
	public int $projectId;

	/** Das zweite Board (siehe {@see ERNA}). */
	public int $otherBoardId;

	/** Das Projekt des zweiten Boards (#246 PR 2). */
	public int $otherProjectId;

	/** @var array<string, int> Bezeichnung => Ticket-ID */
	public array $ticketIds = [];

	/** @var array<string, int> Spaltentitel => Spalten-ID */
	public array $columnIds = [];

	public function __construct() {
		$now = new \DateTime('2026-08-08 12:00:00');

		$boards = Server::get(BoardMapper::class);
		$members = Server::get(MemberMapper::class);
		$columns = Server::get(ColumnMapper::class);
		$tickets = Server::get(TicketMapper::class);
		$comments = Server::get(CommentMapper::class);
		$steps = Server::get(StepMapper::class);
		$attachments = Server::get(AttachmentMapper::class);
		$ticketUsers = Server::get(TicketUserMapper::class);
		$projects = Server::get(ProjectMapper::class);

		// #246 PR 2: Das Projekt-Dach. Die Fixture umgeht die Services, also legt
		// sie das Projekt selbst an und trägt `project_id` an Board, Mitgliedern
		// und Tickets — sonst fände der jetzt projekt-scoped Sichtbarkeitsverbund
		// nichts.
		$project = new Project();
		$project->setTitle('Leak-Matrix');
		$project->setOwnerUserId(self::ANNA);
		$project->setArchived(0);
		$project->setOrgInternal(self::ORG_INTERNAL);
		$project->setOrgExternal(self::ORG_EXTERNAL);
		$project->setTicketCounter(0);
		$project->setCreatedAt($now);
		$project->setUpdatedAt($now);
		$this->projectId = (int)$projects->insert($project)->getId();

		$board = new Board();
		$board->setTitle('Leak-Matrix');
		$board->setProjectId($this->projectId);
		$board->setOwnerUserId(self::ANNA);
		// Ausdruecklich gesetzt, obwohl die Spalte 0 als Vorgabe traegt:
		// findAllForUser() filtert auf archived = 0, und eine Erwartung soll
		// nicht an einer Vorgabe haengen, die jemand spaeter aendert.
		$board->setArchived(0);
		$board->setOrgInternal(self::ORG_INTERNAL);
		$board->setOrgExternal(self::ORG_EXTERNAL);
		$board->setCreatedAt($now);
		$board->setUpdatedAt($now);
		$board = $boards->insert($board);
		$this->boardId = (int)$board->getId();

		foreach (self::MEMBERS as $userId => [$role, $isManager, $displayName]) {
			$member = new Member();
			$member->setBoardId($this->boardId);
			$member->setProjectId($this->projectId);
			$member->setUserId($userId);
			$member->setRole($role);
			$member->setIsManager($isManager ? 1 : 0);
			$member->setDisplayName($displayName);
			$member->setAddedBy(self::ANNA);
			$member->setAddedAt($now);
			$members->insert($member);
		}

		foreach ([self::COLUMN_A, self::COLUMN_B] as $index => $title) {
			$column = new Column();
			$column->setBoardId($this->boardId);
			$column->setTitle($title);
			$column->setPosition($index);
			$column->setColor('#0082c9');
			$this->columnIds[$title] = (int)$columns->insert($column)->getId();
		}

		$number = 0;
		foreach (self::TICKETS as $label => [$visibility, $creator, $creatorRole, $columnTitle, $closed]) {
			$number++;

			$ticket = new Ticket();
			$ticket->setBoardId($this->boardId);
			$ticket->setProjectId($this->projectId);
			$ticket->setColumnId($this->columnIds[$columnTitle]);
			$ticket->setNumber($number);
			$ticket->setTitle($label);
			$ticket->setVisibility($visibility);
			$ticket->setCreatorUserId($creator);
			$ticket->setCreatorRole($creatorRole);
			$ticket->setResponsibleUserId($creator);
			$ticket->setPosition($number * 65536);
			$ticket->setVersion(1);
			$ticket->setClosedAt($closed ? $now : null);
			$ticket->setCreatedAt($now);
			$ticket->setUpdatedAt($now);
			$ticketId = (int)$tickets->insert($ticket)->getId();
			$this->ticketIds[$label] = $ticketId;

			// Genau ein Kind je Tabelle und Ticket. Damit ist jeder Zaehler
			// entweder 1 (sichtbar) oder gar nicht erst vorhanden (verborgen) —
			// eine 2 oder eine 0 waere ein Befund, keine Auslegungsfrage.
			$comment = new Comment();
			$comment->setTicketId($ticketId);
			$comment->setAuthorUserId($creator);
			$comment->setBody('Kommentar zu ' . $label);
			$comment->setCreatedAt($now);
			$comment->setUpdatedAt($now);
			$comments->insert($comment);

			$step = new Step();
			$step->setTicketId($ticketId);
			$step->setTitle('Schritt zu ' . $label);
			$step->setAssignedUserId($creator);
			$step->setAssignedRole($creatorRole);
			$step->setAssignedAt($now);
			$step->setDone(0);
			$step->setPosition(0);
			$step->setCreatedAt($now);
			$steps->insert($step);

			$attachment = new Attachment();
			$attachment->setTicketId($ticketId);
			$attachment->setFileId(100000 + $number);
			$attachment->setFilePath('/Projekte/Leak-Matrix/' . $label . '.pdf');
			$attachment->setFileName($label . '.pdf');
			$attachment->setLocation(
				$visibility === TicketScope::VISIBILITY_PUBLIC ? 'public' : 'internal',
			);
			$attachment->setUploadedBy($creator);
			$attachment->setCreatedAt($now);
			$attachments->insert($attachment);

			// Dirk auf jedem Ticket — siehe Klassenkommentar. Ohne die Regel
			// liefert „Meine Aufgaben" ihm alle neun.
			$ticketUser = new TicketUser();
			$ticketUser->setTicketId($ticketId);
			$ticketUser->setUserId(self::DIRK);
			$ticketUser->setAddedAt($now);
			$ticketUsers->insert($ticketUser);
		}

		// Der Zaehler muss mitziehen, weil die Fixture die Nummern selbst
		// vergibt und dabei am Dienst vorbeigeht.
		//
		// Das ist nicht Kosmetik: Ohne diese Zeile stuende der Zaehler auf 0,
		// waehrend die Nummern 1 bis 9 bereits vergeben sind — das naechste
		// ueber TicketService angelegte Ticket bekaeme die 1 und liefe in den
		// eindeutigen Index. Genau so ist es beim ersten Lauf passiert. Eine
		// Fixture, die einen Zustand herstellt, den der Produktivcode nie
		// erzeugen kann, prueft den Produktivcode gegen eine Fiktion.
		//
		// Seit #246 PR 4a zaehlt die Nummer am **Projekt** — also traegt der
		// Projektzaehler den Stand, genau wie die Migration ihn aus dem Board
		// nachzieht. Der Board-Zaehler bleibt als Bestandsspiegel gesetzt.
		$board->setTicketCounter($number);
		$boards->update($board);
		$project->setTicketCounter($number);
		$projects->update($project);

		$this->buildOtherBoard($now);
	}

	/**
	 * Das zweite Board — sparsam, mit genau einer Frage im Blick.
	 *
	 * Es traegt keine Kinder (Kommentare, Anhaenge, Mitarbeit): Die pruefen die
	 * Kinder-Lesepfade am ersten Board, und hier wuerden sie nur die Zahlen
	 * verschieben, ohne eine Erwartung zu schaerfen.
	 *
	 * **Ein Arbeitsschritt steht doch drin**, und der ist der Punkt: Er gehoert
	 * Bert an `b:public/erna` — einem Vorgang, fuer den er **weder
	 * verantwortlich noch mitarbeitend** ist. Damit ist belegt, dass „Meine
	 * Arbeitsschritte" eine andere Menge ist als „Meine Tickets", und genau das
	 * ist die Begruendung fuer den eigenen Lesepfad
	 * {@see TicketMapper::findVisibleWithMyOpenSteps()}.
	 */
	private function buildOtherBoard(\DateTime $now): void {
		$boards = Server::get(BoardMapper::class);
		$members = Server::get(MemberMapper::class);
		$columns = Server::get(ColumnMapper::class);
		$tickets = Server::get(TicketMapper::class);
		$steps = Server::get(StepMapper::class);
		$projects = Server::get(ProjectMapper::class);

		// Ein eigenes Projekt — das zweite Board prüft die Rolle je Board (Bert
		// hier extern, im ersten Board intern), das verlangt getrennte
		// Mitgliedschaften und damit getrennte Projekte.
		$project = new Project();
		$project->setTitle('Leak-Matrix Zweitboard');
		$project->setOwnerUserId(self::ERNA);
		$project->setArchived(0);
		$project->setOrgInternal('Erna Elektronik');
		$project->setOrgExternal(self::ORG_INTERNAL);
		$project->setTicketCounter(0);
		$project->setCreatedAt($now);
		$project->setUpdatedAt($now);
		$this->otherProjectId = (int)$projects->insert($project)->getId();

		$board = new Board();
		$board->setTitle('Leak-Matrix Zweitboard');
		$board->setProjectId($this->otherProjectId);
		$board->setOwnerUserId(self::ERNA);
		$board->setArchived(0);
		$board->setOrgInternal('Erna Elektronik');
		$board->setOrgExternal(self::ORG_INTERNAL);
		$board->setCreatedAt($now);
		$board->setUpdatedAt($now);
		$this->otherBoardId = (int)$boards->insert($board)->getId();

		foreach (self::B_MEMBERS as $userId => [$role, $isManager]) {
			$member = new Member();
			$member->setBoardId($this->otherBoardId);
			$member->setProjectId($this->otherProjectId);
			$member->setUserId($userId);
			$member->setRole($role);
			$member->setIsManager($isManager ? 1 : 0);
			$member->setDisplayName(null);
			$member->setAddedBy(self::ERNA);
			$member->setAddedAt($now);
			$members->insert($member);
		}

		$column = new Column();
		$column->setBoardId($this->otherBoardId);
		$column->setTitle(self::B_COLUMN);
		$column->setPosition(0);
		$columnId = (int)$columns->insert($column)->getId();

		$number = 0;
		foreach (self::B_TICKETS as $label => [$visibility, $creator, $creatorRole]) {
			$number++;

			$ticket = new Ticket();
			$ticket->setBoardId($this->otherBoardId);
			$ticket->setProjectId($this->otherProjectId);
			$ticket->setColumnId($columnId);
			$ticket->setNumber($number);
			$ticket->setTitle($label);
			$ticket->setVisibility($visibility);
			$ticket->setCreatorUserId($creator);
			$ticket->setCreatorRole($creatorRole);
			// Verantwortlich ist die **erzeugende** Person. Bert ist damit fuer
			// `b:public/erna` weder verantwortlich noch mitarbeitend — der
			// Schritt unten haengt genau daran.
			$ticket->setResponsibleUserId($creator);
			$ticket->setPosition($number * 65536);
			$ticket->setVersion(1);
			$ticket->setCreatedAt($now);
			$ticket->setUpdatedAt($now);
			$this->ticketIds[$label] = (int)$tickets->insert($ticket)->getId();
		}

		$step = new Step();
		$step->setTicketId($this->ticketIds['b:public/erna']);
		$step->setTitle('Zuarbeit von der Kundenseite');
		$step->setAssignedUserId(self::BERT);
		$step->setAssignedRole(ViewerContext::ROLE_EXTERNAL);
		$step->setAssignedAt($now);
		$step->setDone(0);
		$step->setPosition(0);
		$step->setCreatedAt($now);
		$steps->insert($step);

		// **Ein erledigter Schritt, und er ist der einzige an diesem Vorgang.**
		// Ohne ihn liefe die Bedingung „offen" — einmal als `done = 0` im SQL,
		// einmal als `!isDone()` in PHP — gegen nichts: Beide liessen sich
		// streichen, ohne dass ein Test faellt. `b:internal/bert` darf deshalb
		// in „Meine Arbeitsschritte" **nicht** auftauchen, obwohl Bert das
		// Ticket sieht und der Schritt ihm gehoert.
		$done = new Step();
		$done->setTicketId($this->ticketIds['b:internal/bert']);
		$done->setTitle('Schon erledigt');
		$done->setAssignedUserId(self::BERT);
		$done->setAssignedRole(ViewerContext::ROLE_EXTERNAL);
		$done->setAssignedAt($now);
		$done->setDone(1);
		$done->setDoneAt($now);
		$done->setPosition(1);
		$done->setCreatedAt($now);
		$steps->insert($done);

		$board->setTicketCounter($number);
		$boards->update($board);
		$project->setTicketCounter($number);
		$projects->update($project);
	}

	/**
	 * Der Kontext eines Mitglieds — auf demselben Weg wie im Betrieb, also ueber
	 * `BoardAccess`.
	 */
	public function contextFor(string $userId, ?int $boardId = null): ViewerContext {
		return Server::get(\OCA\Projektwerk\Access\BoardAccess::class)
			->contextFor($userId, $boardId ?? $this->boardId);
	}

	/**
	 * Bezeichnungen zu Ticket-IDs.
	 *
	 * @param string[] $labels
	 * @return int[] aufsteigend sortiert
	 */
	public function idsFor(array $labels): array {
		$ids = array_map(fn (string $label): int => $this->ticketIds[$label], $labels);
		sort($ids);

		return $ids;
	}

	/**
	 * Die Bezeichnungen zu einer Menge von Tickets.
	 *
	 * @param Ticket[] $tickets
	 * @return string[] aufsteigend sortiert, damit der Vergleich stabil ist
	 */
	public function labelsOfTickets(array $tickets): array {
		return $this->labelsForIds(array_map(
			static fn (Ticket $ticket): int => (int)$ticket->getId(),
			$tickets,
		));
	}

	/**
	 * Die Bezeichnungen der Tickets, zu denen diese Kinder gehoeren.
	 *
	 * **Getrennt von {@see labelsOfTickets()}, und zwar absichtlich.** Ein
	 * gemeinsamer Helfer musste zur Laufzeit entscheiden, ob er `getId()` oder
	 * `getTicketId()` nimmt — und `method_exists()` kann das nicht: Nextclouds
	 * `Entity` erzeugt seine Getter ueber `__call()`, die Methode existiert also
	 * nicht im Sinne der Reflexion. Der Helfer fiel damit still auf `getId()`
	 * zurueck und verglich die IDs der Kinder gegen die der Tickets.
	 *
	 * Das war zweimal zufaellig richtig: `pwerk_tickets` und `pwerk_comments`
	 * standen auf demselben Auto-Increment, weil die Handpruefungen aus PR #34
	 * beide Tabellen gleich oft befuellt hatten. Ein Testhelfer, der von der
	 * Einfuegehistorie einer fremden Instanz abhaengt, ist kein Waechter.
	 *
	 * @param object[] $children Entities mit einer Eigenschaft `ticketId`
	 * @return string[] aufsteigend sortiert
	 */
	public function labelsOfChildren(array $children): array {
		return $this->labelsForIds(array_map(
			static fn (object $child): int => (int)$child->getTicketId(),
			$children,
		));
	}

	/**
	 * @param int[] $ticketIds
	 * @return string[]
	 */
	private function labelsForIds(array $ticketIds): array {
		$byId = array_flip($this->ticketIds);

		$labels = [];
		foreach ($ticketIds as $ticketId) {
			// Ein unbekannter Wert wird benannt und nicht verschluckt: Er heisst,
			// dass eine Abfrage etwas geliefert hat, das nicht zur Fixture gehoert.
			$labels[] = $byId[$ticketId] ?? ('FREMDE-TICKET-ID(' . $ticketId . ')');
		}
		sort($labels);

		return $labels;
	}
}
