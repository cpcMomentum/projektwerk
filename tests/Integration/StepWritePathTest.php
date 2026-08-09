<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Service\StepService;
use OCP\Server;

/**
 * Der Schreibpfad der Arbeitsschritte.
 *
 * Die **Zuweisungsregel** steht in der Leak-Matrix, weil sie aus der
 * Sichtbarkeit folgt. Hier stehen die Fälle daneben, die kein Leck wären, aber
 * trotzdem falsch: eine Zuweisung, die sich nicht löschen lässt, ein Zeitstempel,
 * der bei jeder Titeländerung neu gesetzt wird, ein Datum im falschen Format.
 */
class StepWritePathTest extends IntegrationTestCase {

	private StepService $steps;
	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
		$this->steps = Server::get(StepService::class);
	}

	/**
	 * **Eine Zuweisung muss sich löschen lassen.**
	 *
	 * Der Fall ist am 2026-08-09 im Review aufgefallen: Der Controller
	 * unterschied „`assignedUserId` nicht genannt" von „ausdrücklich `null`"
	 * über `getParam()` — und das prüft mit `isset()`, was bei `null` immer
	 * `false` ergibt. Eine Zuweisung liess sich damit setzen, aber nie wieder
	 * entfernen, und der Wartezustand haette an ihr geklebt.
	 *
	 * Der Test sitzt am Dienst und nicht am Controller, weil dort die Bedeutung
	 * festgelegt ist: `null` heisst löschen.
	 */
	public function testAnAssignmentCanBeCleared(): void {
		$viewer = $this->owner();
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$step = $this->steps->create($viewer, $ticketId, 'Logo liefern', LeakMatrixFixture::CARLA);
		$this->assertSame(LeakMatrixFixture::CARLA, $step->getAssignedUserId());
		$this->assertNotNull($step->getAssignedAt(), 'Die Zuweisung braucht einen Zeitpunkt.');

		$geleert = $this->steps->update($viewer, (int)$step->getId(), ['assignedUserId' => null]);

		$this->assertNull($geleert->getAssignedUserId());
		$this->assertNull($geleert->getAssignedRole(), 'Die Rollenkopie muss mitgehen.');
		$this->assertNull($geleert->getAssignedAt(), 'Sonst bliebe eine Wartezeit ohne Wartenden.');
	}

	/**
	 * Der Zeitstempel gehört zur Zuweisung, nicht zur letzten Änderung.
	 *
	 * Spränge er bei jeder Titeländerung auf heute, verlöre die Wartemarke
	 * ihren Sinn — sie soll sagen, seit wann gewartet wird.
	 */
	public function testTheAssignmentTimestampSurvivesAnEdit(): void {
		$viewer = $this->owner();
		$step = $this->steps->create(
			$viewer,
			$this->fixture->ticketIds['public/anna'],
			'Erst so',
			LeakMatrixFixture::CARLA,
		);
		$zuerst = $step->getAssignedAt();

		$spaeter = $this->steps->update($viewer, (int)$step->getId(), ['title' => 'Dann so']);

		// Auf die Sekunde verglichen: Die Datenbank schneidet Mikrosekunden ab,
		// das Objekt im Speicher hat sie noch. Ein Vergleich auf Gleichheit
		// schluege deshalb fehl, ohne dass etwas falsch waere.
		$this->assertSame(
			$zuerst?->format('Y-m-d H:i:s'),
			$spaeter->getAssignedAt()?->format('Y-m-d H:i:s'),
		);
		$this->assertSame('Dann so', $spaeter->getTitle());
	}

	/**
	 * §7 gilt auch für Schritte: An einem internen Ticket der eigenen Seite hat
	 * die Kundenseite nichts zu suchen.
	 */
	public function testTheOtherSideCannotBeAssignedOnAnInternalTicket(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->steps->create(
			$this->owner(),
			$this->fixture->ticketIds['internal/anna'],
			'Nicht erlaubt',
			LeakMatrixFixture::CARLA,
		);
	}

	public function testAStepNeedsATitle(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->steps->create($this->owner(), $this->fixture->ticketIds['public/anna'], '   ');
	}

	/**
	 * Ein unbrauchbares Datum wird abgewiesen statt still verworfen.
	 *
	 * Still verworfen hiesse: Jemand trägt eine Fälligkeit ein, sie erscheint
	 * nicht, und niemand erfährt warum.
	 */
	public function testAMalformedDueDateIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->steps->create(
			$this->owner(),
			$this->fixture->ticketIds['public/anna'],
			'Mit Frist',
			null,
			'12.06.2026',
		);
	}

	private function owner(): ViewerContext {
		return $this->fixture->contextFor(LeakMatrixFixture::ANNA);
	}
}
