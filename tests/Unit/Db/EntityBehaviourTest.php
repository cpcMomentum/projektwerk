<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Db;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\Ticket;
use PHPUnit\Framework\TestCase;

/**
 * Die wenigen Entscheidungen, die in den Entities selbst stecken.
 *
 * Keine davon ist eine Sichtbarkeitsfrage — die beantwortet ausschliesslich
 * {@see \OCA\Projektwerk\Access\TicketScope} in der Datenbank. Hier geht es um
 * das, was **nach** dem Filter gilt.
 */
class EntityBehaviourTest extends TestCase {

	/**
	 * Bearbeiten darf, wer verwaltet, erzeugt hat oder verantwortlich ist.
	 */
	public function testEditableByManagerCreatorAndResponsible(): void {
		$ticket = $this->ticket(boardId: 7, creator: 'anna', responsible: 'bert');

		$this->assertTrue($ticket->isEditableBy($this->viewer('anna', 7)), 'Erzeugerin');
		$this->assertTrue($ticket->isEditableBy($this->viewer('bert', 7)), 'Verantwortlicher');
		$this->assertTrue(
			$ticket->isEditableBy($this->viewer('chef', 7, isManager: true)),
			'Verwaltungsrecht',
		);
		$this->assertFalse($ticket->isEditableBy($this->viewer('carla', 7)), 'nur Mitglied');
	}

	/**
	 * Ein Kontext aus einem **anderen** Board berechtigt zu nichts.
	 *
	 * Der Fall ist kein Hirngespinst: Wer eine Ticket-ID aus Board A in einen
	 * Aufruf mit dem Kontext von Board B legt, hat genau diese Konstellation.
	 * Der Lesepfad faengt das ueber `TicketScope` ab, der Schreibpfad braucht
	 * seine eigene Sperre.
	 */
	public function testNotEditableWithForeignBoardContext(): void {
		$ticket = $this->ticket(boardId: 7, creator: 'anna', responsible: 'anna');

		$this->assertFalse($ticket->isEditableBy($this->viewer('anna', 8)));
		$this->assertFalse($ticket->isEditableBy($this->viewer('chef', 8, isManager: true)));
	}

	/**
	 * „Wartet auf Kunde" haengt an offen **und** extern zugewiesen.
	 */
	public function testWaitStateNeedsOpenAndExternal(): void {
		$this->assertTrue($this->stepOf(ViewerContext::ROLE_EXTERNAL, done: 0)->waitsOnExternal());
		$this->assertFalse($this->stepOf(ViewerContext::ROLE_EXTERNAL, done: 1)->waitsOnExternal(), 'erledigt');
		$this->assertFalse($this->stepOf(ViewerContext::ROLE_INTERNAL, done: 0)->waitsOnExternal(), 'intern');
		$this->assertFalse($this->stepOf(null, done: 0)->waitsOnExternal(), 'niemandem zugewiesen');
	}

	/**
	 * Verwaltungsrecht gilt nur intern — dieselbe Entschaerfung wie in
	 * {@see ViewerContext::forMember()}. Ein externes Mitglied mit gesetztem
	 * Flag ist ein Datenfehler und darf nicht als Recht durchgehen.
	 */
	public function testManagerFlagIsIgnoredForExternalMembers(): void {
		$this->assertTrue($this->member(ViewerContext::ROLE_INTERNAL, 1)->isManagerEffective());
		$this->assertFalse($this->member(ViewerContext::ROLE_INTERNAL, 0)->isManagerEffective());
		$this->assertFalse($this->member(ViewerContext::ROLE_EXTERNAL, 1)->isManagerEffective());

		$this->assertFalse(
			$this->member(ViewerContext::ROLE_EXTERNAL, 1)->jsonSerialize()['isManager'],
			'Auch die Ausgabe ans Frontend darf das Flag nicht weiterreichen.',
		);
	}

	private function ticket(int $boardId, string $creator, string $responsible): Ticket {
		$ticket = new Ticket();
		$ticket->setId(42);
		$ticket->setBoardId($boardId);
		$ticket->setCreatorUserId($creator);
		$ticket->setResponsibleUserId($responsible);

		return $ticket;
	}

	private function viewer(string $userId, int $boardId, bool $isManager = false): ViewerContext {
		return ViewerContext::forMember($userId, $boardId, ViewerContext::ROLE_INTERNAL, $isManager);
	}

	private function stepOf(?string $assignedRole, int $done): Step {
		$step = new Step();
		$step->setAssignedRole($assignedRole);
		$step->setDone($done);

		return $step;
	}

	private function member(string $role, int $isManager): Member {
		$member = new Member();
		$member->setRole($role);
		$member->setIsManager($isManager);

		return $member;
	}
}
