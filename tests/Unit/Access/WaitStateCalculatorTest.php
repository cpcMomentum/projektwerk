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

	private WaitStateCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();

		$this->calculator = new WaitStateCalculator();
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

	private function ticket(int $id = 1): Ticket {
		$ticket = new Ticket();
		$ticket->setId($id);
		$ticket->setVisibility('public');

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
