<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Access;

use OCA\Projektwerk\Access\WaitStateCalculator;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\Ticket;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * „Wartet auf Kunde" — die Bedingung, Wort für Wort.
 *
 * Containerfrei, weil die Rechnung nichts liest und nichts schreibt. Genau das
 * ist ihr Zweck: Ein gespeichertes Feld müsste bei jedem Zuweisen, Erledigen,
 * Löschen und Rollenwechsel mitgepflegt werden, und die erste vergessene Stelle
 * erzeugt eine Marke, die niemand mehr erklären kann.
 */
class WaitStateCalculatorTest extends TestCase {

	/** Feste „heute"-Uhr, damit „überfällig" (#144) nicht vom Testtag abhängt. */
	private const HEUTE = '2026-08-16';

	private WaitStateCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime(self::HEUTE . ' 09:00:00'));

		$this->calculator = new WaitStateCalculator($time);
	}

	public function testAnOpenExternalStepMakesTheTicketWait(): void {
		$state = $this->calculator->forTicket(
			$this->ticket(),
			[$this->step('external', false, '2026-06-12')],
		);

		$this->assertNotNull($state);
		$this->assertStringStartsWith('2026-06-12', $state['since']);
		$this->assertSame(['kunde'], $state['userIds']);
	}

	public function testAnInternalAssignmentNeverWaits(): void {
		// Die Marke heißt „wartet auf Kunde" und nicht „wartet auf jemanden".
		// Ein intern zugewiesener Schritt ist die eigene Arbeit.
		$this->assertNull($this->calculator->forTicket(
			$this->ticket(),
			[$this->step('internal', false, '2026-06-12')],
		));
	}

	public function testADoneStepHoldsNothingUp(): void {
		$this->assertNull($this->calculator->forTicket(
			$this->ticket(),
			[$this->step('external', true, '2026-06-12')],
		));
	}

	public function testTheDateIsTheOldestAssignmentNotTheNewest(): void {
		// Sonst spränge die Marke bei jeder weiteren Zuweisung auf ein neueres
		// Datum und verlöre ihren Sinn als Wartezeit.
		$state = $this->calculator->forTicket($this->ticket(), [
			$this->step('external', false, '2026-07-01', 'kunde'),
			$this->step('external', false, '2026-06-12', 'kundin'),
		]);

		$this->assertStringStartsWith('2026-06-12', $state['since']);
		$this->assertSame(['kunde', 'kundin'], $state['userIds']);
	}

	public function testAClosedTicketWaitsForNobody(): void {
		// E8: Der Schritt überlebt das Schließen — er wird nicht automatisch
		// erledigt, weil das eine Aussage über die Wirklichkeit wäre, die
		// niemand getroffen hat. Aber eine Marke am geschlossenen Vorgang wäre
		// eine Aufforderung ins Leere.
		$ticket = $this->ticket();
		$ticket->setClosedAt(new \DateTime('2026-07-05'));

		$this->assertNull($this->calculator->forTicket(
			$ticket,
			[$this->step('external', false, '2026-06-12')],
		));
	}

	public function testARoleWithoutAPersonIsNoMark(): void {
		// Eine Rolle ohne Kennung wäre ein Datenfehler — und der Satz im Detail
		// könnte keinen Namen nennen.
		$step = $this->step('external', false, '2026-06-12');
		$step->setAssignedUserId(null);

		$this->assertNull($this->calculator->forTicket($this->ticket(), [$step]));
	}

	public function testAnExternalResponsibleWithoutStepsMakesTheTicketWait(): void {
		// Der Kern von #114: Nicht jeder Vorgang wird in Schritte zerlegt. Einer,
		// der jemandem auf der Gegenseite gehoert, liegt trotzdem dort.
		$state = $this->calculator->forTicket(
			$this->responsibleTicket('external', '2026-06-12', 'kunde'),
			[],
		);

		$this->assertNotNull($state);
		$this->assertStringStartsWith('2026-06-12', $state['since']);
		$this->assertSame(['kunde'], $state['userIds']);
	}

	public function testAnInternalResponsibleNeverWaits(): void {
		// „Wartet auf Kunde", nicht „wartet auf jemanden": Ein intern
		// Verantwortlicher ist die eigene Arbeit.
		$this->assertNull($this->calculator->forTicket(
			$this->responsibleTicket('internal', '2026-06-12', 'kollege'),
			[],
		));
	}

	public function testAResponsibleRoleWithoutAPersonIsNoMark(): void {
		// Wie beim Schritt: Eine Rolle ohne Kennung waere ein Datenfehler, und der
		// Satz im Detail koennte keinen Namen nennen.
		$ticket = $this->responsibleTicket('external', '2026-06-12', 'kunde');
		$ticket->setResponsibleUserId(null);

		$this->assertNull($this->calculator->forTicket($ticket, []));
	}

	public function testResponsibleSinceCountsTowardTheOldestDate(): void {
		// Das Datum ist das kleinste ueber beide Quellen — nicht das juengste,
		// sonst verloere die Marke ihren Sinn als Wartezeit.
		$state = $this->calculator->forTicket(
			$this->responsibleTicket('external', '2026-06-01', 'chef'),
			[$this->step('external', false, '2026-07-01', 'kunde')],
		);

		$this->assertStringStartsWith('2026-06-01', $state['since']);
		$this->assertSame(['kunde', 'chef'], $state['userIds']);
	}

	public function testAnExternalResponsibleWithoutSinceShowsNoDate(): void {
		// Bestandszeilen tragen keine `responsible_since` — die Marke steht dann
		// ohne Datum da, wie ein Schritt ohne `assigned_at`. Ehrlicher als ein
		// erfundenes Datum.
		$state = $this->calculator->forTicket(
			$this->responsibleTicket('external', null, 'kunde'),
			[],
		);

		$this->assertNotNull($state);
		$this->assertSame('', $state['since']);
		$this->assertSame(['kunde'], $state['userIds']);
	}

	public function testAClosedTicketWithAnExternalResponsibleWaitsForNobody(): void {
		$ticket = $this->responsibleTicket('external', '2026-06-12', 'kunde');
		$ticket->setClosedAt(new \DateTime('2026-07-05'));

		$this->assertNull($this->calculator->forTicket($ticket, []));
	}

	public function testTheListVariantAnswersOnlyForWaitingTickets(): void {
		$wartend = $this->ticket(1);
		$ruhig = $this->ticket(2);

		$states = $this->calculator->forTickets(
			[$wartend, $ruhig],
			[
				$this->stepFor(1, 'external', false, '2026-06-12'),
				$this->stepFor(2, 'internal', false, '2026-06-12'),
			],
		);

		$this->assertArrayHasKey(1, $states);
		$this->assertArrayNotHasKey(2, $states);
	}

	public function testAWaitingTicketWithoutADueDateStaysCalm(): void {
		// Ohne Fälligkeit gibt es kein „zu spät" (#144): Der Ball liegt beim
		// Kunden, aber die Marke ruft nicht.
		$state = $this->calculator->forTicket(
			$this->ticket(),
			[$this->step('external', false, '2026-06-12')],
		);

		$this->assertFalse($state['overdue']);
	}

	public function testATicketDueInTheFutureStaysCalm(): void {
		// Innerhalb der vereinbarten Frist: ruhig, obwohl der Ball beim Kunden
		// liegt.
		$state = $this->calculator->forTicket(
			$this->ticketWithDue('2026-09-01'),
			[$this->step('external', false, '2026-06-12')],
		);

		$this->assertFalse($state['overdue']);
	}

	public function testTheDueDayItselfIsNotYetOverdue(): void {
		// Datum-Vergleich auf Tagesebene, der Fälligkeitstag selbst zählt noch
		// nicht — Wort für Wort dieselbe Regel wie im Frontend (`taskStore.ts`).
		$state = $this->calculator->forTicket(
			$this->ticketWithDue(self::HEUTE),
			[$this->step('external', false, '2026-06-12')],
		);

		$this->assertFalse($state['overdue']);
	}

	public function testAnOverdueTicketMakesTheMarkLoud(): void {
		// Fälligkeit gerissen: die kräftige Marke — „in Verzug".
		$state = $this->calculator->forTicket(
			$this->ticketWithDue('2026-08-15'),
			[$this->step('external', false, '2026-06-12')],
		);

		$this->assertTrue($state['overdue']);
	}

	public function testOverdueRidesTheResponsibleSourceToo(): void {
		// Die Fälligkeit hängt am Ticket, nicht an der Quelle des Wartens — auch
		// ein per Verantwortlichem (#114) wartender Vorgang wird laut.
		$ticket = $this->responsibleTicket('external', '2026-06-12', 'kunde');
		$ticket->setDueDate(new \DateTime('2026-08-15'));

		$state = $this->calculator->forTicket($ticket, []);

		$this->assertTrue($state['overdue']);
	}

	private function ticket(int $id = 1): Ticket {
		$ticket = new Ticket();
		$ticket->setId($id);
		$ticket->setVisibility('public');

		return $ticket;
	}

	/**
	 * Ein Ticket mit gesetzter Fälligkeit (#72), für die Verzugs-Stufe (#144).
	 *
	 * @param string $dueDate Fälligkeit als JJJJ-MM-TT.
	 */
	private function ticketWithDue(string $dueDate, int $id = 1): Ticket {
		$ticket = $this->ticket($id);
		$ticket->setDueDate(new \DateTime($dueDate));

		return $ticket;
	}

	/**
	 * Ein Ticket mit eingetragenem Verantwortlichen — die zweite Quelle (#114).
	 *
	 * @param string $role Rolle, die beim Eintragen eingefroren wurde.
	 * @param ?string $since Zeitpunkt des Eintragens, oder `null` (Bestandszeile).
	 * @param string $userId Kennung des Verantwortlichen.
	 * @param int $id Kennung des Tickets.
	 */
	private function responsibleTicket(string $role, ?string $since, string $userId = 'kunde', int $id = 1): Ticket {
		$ticket = $this->ticket($id);
		$ticket->setResponsibleUserId($userId);
		$ticket->setResponsibleRole($role);
		$ticket->setResponsibleSince($since === null ? null : new \DateTime($since));

		return $ticket;
	}

	/**
	 * @param string $role Rolle, die bei der Zuweisung kopiert wurde.
	 * @param bool $done Erledigt ja/nein.
	 * @param string $assignedAt Zeitpunkt der Zuweisung.
	 * @param string $userId Kennung der zugewiesenen Person.
	 */
	private function step(string $role, bool $done, string $assignedAt, string $userId = 'kunde'): Step {
		return $this->stepFor(1, $role, $done, $assignedAt, $userId);
	}

	/**
	 * @param int $ticketId Kennung des Tickets.
	 * @param string $role Rolle, die bei der Zuweisung kopiert wurde.
	 * @param bool $done Erledigt ja/nein.
	 * @param string $assignedAt Zeitpunkt der Zuweisung.
	 * @param string $userId Kennung der zugewiesenen Person.
	 */
	private function stepFor(int $ticketId, string $role, bool $done, string $assignedAt, string $userId = 'kunde'): Step {
		$step = new Step();
		$step->setTicketId($ticketId);
		$step->setTitle('Schritt');
		$step->setAssignedUserId($userId);
		$step->setAssignedRole($role);
		$step->setAssignedAt(new \DateTime($assignedAt));
		$step->setDone($done ? 1 : 0);

		return $step;
	}
}
