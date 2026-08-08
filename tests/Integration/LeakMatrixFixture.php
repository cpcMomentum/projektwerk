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

	/** Mitglied => [Rolle, Verwaltungsrecht] */
	private const MEMBERS = [
		self::ANNA => [ViewerContext::ROLE_INTERNAL, true],
		self::BERT => [ViewerContext::ROLE_INTERNAL, false],
		self::CARLA => [ViewerContext::ROLE_EXTERNAL, false],
		self::DIRK => [ViewerContext::ROLE_EXTERNAL, false],
	];

	public int $boardId;

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

		$board = new Board();
		$board->setTitle('Leak-Matrix');
		$board->setOwnerUserId(self::ANNA);
		// Ausdruecklich gesetzt, obwohl die Spalte 0 als Vorgabe traegt:
		// findAllForUser() filtert auf archived = 0, und eine Erwartung soll
		// nicht an einer Vorgabe haengen, die jemand spaeter aendert.
		$board->setArchived(0);
		$board->setCreatedAt($now);
		$board->setUpdatedAt($now);
		$board = $boards->insert($board);
		$this->boardId = (int)$board->getId();

		foreach (self::MEMBERS as $userId => [$role, $isManager]) {
			$member = new Member();
			$member->setBoardId($this->boardId);
			$member->setUserId($userId);
			$member->setRole($role);
			$member->setIsManager($isManager ? 1 : 0);
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
		$board->setTicketCounter($number);
		$boards->update($board);
	}

	/**
	 * Der Kontext eines Mitglieds — auf demselben Weg wie im Betrieb, also ueber
	 * `BoardAccess`.
	 */
	public function contextFor(string $userId): ViewerContext {
		return Server::get(\OCA\Projektwerk\Access\BoardAccess::class)
			->contextFor($userId, $this->boardId);
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
