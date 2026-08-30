<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\Server;

/**
 * Der Durchsatz-Zähler {@see TicketMapper::countInWindow()} (#226) gegen die
 * echte Datenbank.
 *
 * **Die eine Frage dieses Tests:** Bei `closed_at` zählt „Erledigt / Woche" nur
 * erledigte Vorgänge, keine verworfenen — und bei `created_at` zählt „Neu /
 * Woche" beide. Genau diese Trennung war der Bug: Ohne den Outcome-Filter
 * zählte das Dashboard verworfene Vorgänge als erledigt mit.
 *
 * Der Filter hängt allein an der Zeitspalte, nicht am Ticket-Zustand: Ein
 * verworfener Vorgang ist neu entstanden (er zählt bei `created_at`) und nur
 * eben nicht erledigt worden (er zählt nicht bei `closed_at`). Beide Fälle
 * stehen deshalb nebeneinander — der `created_at`-Zweig ist die Gegenprobe,
 * dass der Filter sich nicht versehentlich auf beide Fenster legt.
 *
 * Sichtbarkeit prüft die Leak-Matrix (`durchsatz` für ein Nichtmitglied ist
 * dort null); hier sind alle Vorgänge öffentlich und der Betrachter Mitglied,
 * damit nur die Zeit- und Outcome-Bedingung übrig bleibt.
 */
class TicketDurchsatzTest extends IntegrationTestCase {

	private const VIEWER = 'dur-viewer';

	/** Das Fenster: eine Woche, `[ab, bis)`. */
	private const AB = '2026-08-17 00:00:00';
	private const BIS = '2026-08-24 00:00:00';

	/** Mitten im Fenster. */
	private const IM_FENSTER = '2026-08-20 10:00:00';

	/** Außerhalb — hinter der oberen, exklusiven Grenze. */
	private const AUSSERHALB = '2026-09-01 10:00:00';

	/** Vor der unteren Grenze — für den `since`-Schnitt von countNewByBoard. */
	private const VOR_FENSTER = '2026-08-10 10:00:00';

	private TicketMapper $tickets;
	private int $boardId;
	private int $columnId;
	private int $number = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->tickets = Server::get(TicketMapper::class);
		$now = new \DateTime(self::IM_FENSTER);

		$boards = Server::get(BoardMapper::class);
		$board = new Board();
		$board->setTitle('Durchsatz');
		$board->setOwnerUserId(self::VIEWER);
		$board->setArchived(0);
		$board->setOrgInternal('cpcMomentum');
		$board->setOrgExternal('Kunde');
		$board->setCreatedAt($now);
		$board->setUpdatedAt($now);
		$this->boardId = (int)$boards->insert($board)->getId();

		$members = Server::get(MemberMapper::class);
		$member = new Member();
		$member->setBoardId($this->boardId);
		$member->setUserId(self::VIEWER);
		$member->setRole(ViewerContext::ROLE_INTERNAL);
		$member->setIsManager(1);
		$member->setDisplayName(null);
		$member->setAddedBy(self::VIEWER);
		$member->setAddedAt($now);
		$members->insert($member);

		$columns = Server::get(ColumnMapper::class);
		$column = new Column();
		$column->setBoardId($this->boardId);
		$column->setTitle('Offen');
		$column->setPosition(0);
		$column->setColor('#0082c9');
		$this->columnId = (int)$columns->insert($column)->getId();
	}

	public function testClosedWindowCountsDoneButNotDiscarded(): void {
		// Zwei erledigt im Fenster — einer ausdrücklich `done`, einer ohne
		// Outcome (geschlossen und nicht verworfen ist erledigt, wie in
		// countClosedByBoard).
		$this->insertTicket(self::IM_FENSTER, Ticket::OUTCOME_DONE, self::IM_FENSTER);
		$this->insertTicket(self::IM_FENSTER, null, self::IM_FENSTER);
		// Verworfen im Fenster — darf bei „Erledigt" nicht mitzählen.
		$this->insertTicket(self::IM_FENSTER, Ticket::OUTCOME_DISCARDED, self::IM_FENSTER);
		// Offen — kein closed_at, fällt durch die >=-Bedingung heraus.
		$this->insertTicket(null, null, self::IM_FENSTER);
		// Erledigt, aber hinter der oberen Grenze — das Fenster schließt es aus.
		$this->insertTicket(self::AUSSERHALB, Ticket::OUTCOME_DONE, self::AUSSERHALB);

		$erledigt = $this->tickets->countInWindow(
			self::VIEWER, [$this->boardId], 'closed_at', self::AB, self::BIS,
		);

		$this->assertSame(2, $erledigt, 'Erledigt zählt done, nicht discarded, nicht offen, nicht außerhalb.');
	}

	public function testCreatedWindowCountsDiscardedToo(): void {
		// Dieselben vier Vorgänge im Fenster wie oben, plus einer außerhalb.
		$this->insertTicket(self::IM_FENSTER, Ticket::OUTCOME_DONE, self::IM_FENSTER);
		$this->insertTicket(self::IM_FENSTER, null, self::IM_FENSTER);
		$this->insertTicket(self::IM_FENSTER, Ticket::OUTCOME_DISCARDED, self::IM_FENSTER);
		$this->insertTicket(null, null, self::IM_FENSTER);
		$this->insertTicket(self::AUSSERHALB, Ticket::OUTCOME_DONE, self::AUSSERHALB);

		$neu = $this->tickets->countInWindow(
			self::VIEWER, [$this->boardId], 'created_at', self::AB, self::BIS,
		);

		// Vier im Fenster entstanden — auch der verworfene und der offene. Der
		// Outcome-Filter greift nur bei closed_at, nicht hier.
		$this->assertSame(4, $neu, 'Neu zählt jeden im Fenster entstandenen Vorgang, Outcome-unabhängig.');
	}

	/**
	 * Die Zeitstempel für die Verlaufs-Kurve (#232) folgen bei `created_at`
	 * derselben Regel wie der Zähler: jeder im Fenster entstandene Vorgang,
	 * Outcome-unabhängig — offen und verworfen inbegriffen.
	 */
	public function testTimestampsInWindowForCreatedIncludeEveryOutcome(): void {
		$this->insertTicket(self::IM_FENSTER, Ticket::OUTCOME_DONE, self::IM_FENSTER);
		$this->insertTicket(null, null, self::IM_FENSTER);                              // offen
		$this->insertTicket(self::IM_FENSTER, Ticket::OUTCOME_DISCARDED, self::IM_FENSTER); // verworfen
		$this->insertTicket(self::AUSSERHALB, Ticket::OUTCOME_DONE, self::AUSSERHALB);   // außerhalb

		$stamps = $this->tickets->timestampsInWindow(
			self::VIEWER, [$this->boardId], 'created_at', self::AB, self::BIS,
		);

		$this->assertCount(3, $stamps, 'Drei im Fenster entstanden; der außerhalb nicht.');
		foreach ($stamps as $stamp) {
			$this->assertSame('2026-08-20', substr($stamp, 0, 10), 'Der UTC-Tag stimmt fürs Bündeln.');
		}
	}

	/**
	 * Bei `closed_at` teilt die Kurve die Regel des Zählers: nur erledigte
	 * Vorgänge im Fenster, keine verworfenen, keine offenen — sonst zeigte die
	 * Erledigt-Kurve, was die Erledigt-Zahl daneben nicht zählt.
	 */
	public function testTimestampsInWindowForClosedExcludeDiscardedAndOpen(): void {
		$this->insertTicket(self::IM_FENSTER, Ticket::OUTCOME_DONE, self::IM_FENSTER);
		$this->insertTicket(self::IM_FENSTER, null, self::IM_FENSTER);                   // erledigt ohne Outcome
		$this->insertTicket(self::IM_FENSTER, Ticket::OUTCOME_DISCARDED, self::IM_FENSTER); // verworfen -> raus
		$this->insertTicket(null, null, self::IM_FENSTER);                              // offen -> kein closed_at
		$this->insertTicket(self::AUSSERHALB, Ticket::OUTCOME_DONE, self::AUSSERHALB);   // außerhalb -> raus

		$stamps = $this->tickets->timestampsInWindow(
			self::VIEWER, [$this->boardId], 'closed_at', self::AB, self::BIS,
		);

		$this->assertCount(2, $stamps, 'Nur die zwei erledigten im Fenster.');
	}

	/**
	 * Die Kachel-Marke „N diese Woche" (#232): neu angelegte Vorgänge je Board
	 * ab einem Zeitpunkt, gruppiert. Vor dem Schnitt Entstandenes zählt nicht.
	 */
	public function testCountNewByBoardCountsSinceGroupedByBoard(): void {
		$this->insertTicket(null, null, self::IM_FENSTER);   // ab `since`
		$this->insertTicket(null, null, self::IM_FENSTER);   // ab `since`
		$this->insertTicket(null, null, self::VOR_FENSTER);  // vor `since` -> raus

		$counts = $this->tickets->countNewByBoard(self::VIEWER, [$this->boardId], self::AB);

		$this->assertSame([$this->boardId => 2], $counts);
	}

	/**
	 * Legt einen öffentlichen Vorgang an — nur die Felder, die dieser Test
	 * unterscheidet, sind Parameter.
	 *
	 * @param ?string $closedAt Schließzeitpunkt (`Y-m-d H:i:s`) oder null für offen.
	 * @param ?string $outcome `done`/`discarded` oder null.
	 * @param string $createdAt Anlagezeitpunkt (`Y-m-d H:i:s`).
	 */
	private function insertTicket(?string $closedAt, ?string $outcome, string $createdAt): void {
		$this->number++;

		$ticket = new Ticket();
		$ticket->setBoardId($this->boardId);
		$ticket->setColumnId($this->columnId);
		$ticket->setNumber($this->number);
		$ticket->setTitle('Vorgang ' . $this->number);
		$ticket->setVisibility(TicketScope::VISIBILITY_PUBLIC);
		$ticket->setCreatorUserId(self::VIEWER);
		$ticket->setCreatorRole(ViewerContext::ROLE_INTERNAL);
		$ticket->setResponsibleUserId(self::VIEWER);
		$ticket->setPosition($this->number * 65536);
		$ticket->setVersion(1);
		$ticket->setClosedAt($closedAt === null ? null : new \DateTime($closedAt));
		$ticket->setClosedOutcome($outcome);
		$ticket->setCreatedAt(new \DateTime($createdAt));
		$ticket->setUpdatedAt(new \DateTime($createdAt));
		$this->tickets->insert($ticket);
	}
}
