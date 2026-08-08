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
