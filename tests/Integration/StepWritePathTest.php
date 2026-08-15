<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Controller\StepController;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Service\StepService;
use OCP\AppFramework\Http;
use OCP\IRequest;
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
	 * **Eine Fälligkeit muss sich löschen lassen.**
	 *
	 * Dieselbe Falle wie bei der Zuweisung, ein Feld weiter: Der Controller
	 * übernahm nur, was `!== null` war, und verwarf damit genau das
	 * ausdrückliche „Frist entfernen". Aufgefallen ist es erst am 2026-08-10 mit
	 * #86 — vorher liess sich die Fälligkeit in der Oberfläche überhaupt nicht
	 * setzen, es kam also nie jemand an die Stelle.
	 *
	 * Der Test sitzt am Dienst, weil dort die Bedeutung festgelegt ist: `null`
	 * heisst löschen. Dass der Controller diesen Wert überhaupt bis hierher
	 * durchreicht, hält der Test darunter fest — **dort** sass der Fehler.
	 */
	public function testADueDateCanBeCleared(): void {
		$viewer = $this->owner();
		$step = $this->steps->create(
			$viewer,
			$this->fixture->ticketIds['public/anna'],
			'Mit Frist',
			null,
			'2026-09-01',
		);
		$this->assertSame('2026-09-01', $step->getDueDate()?->format('Y-m-d'));

		$geleert = $this->steps->update($viewer, (int)$step->getId(), ['dueDate' => null]);

		$this->assertNull($geleert->getDueDate());
	}

	/**
	 * **Der Controller muss ein ausdrückliches `null` weiterreichen.**
	 *
	 * Hier sass der Fehler, nicht im Dienst: Der Controller baute seine
	 * Änderungsliste aus allem, was `!== null` war — und verwarf damit genau
	 * die beiden Werte, bei denen `null` etwas bedeutet. Ein Dienst-Test hätte
	 * das nie gefunden, weil er die Liste selbst mitbringt.
	 *
	 * Geprüft werden beide Felder in einem Zug, weil sie dieselbe Bauform
	 * teilen und beim nächsten dritten Feld dieselbe Falle wartet.
	 */
	public function testTheControllerForwardsAnExplicitNull(): void {
		$viewer = $this->owner();
		$step = $this->steps->create(
			$viewer,
			$this->fixture->ticketIds['public/anna'],
			'Mit allem',
			LeakMatrixFixture::CARLA,
			'2026-09-01',
		);

		// Der rohe Parametersatz ist das Entscheidende: Beide Schluessel sind
		// **genannt** und tragen `null`. Genau diesen Fall kann `getParam()`
		// nicht von „nicht genannt" unterscheiden.
		// `createStub` und nicht `createMock`: Der Test erwartet keinen Aufruf,
		// er braucht nur eine Antwort. PHPUnit meldet den Unterschied als Notiz.
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn([
			'assignedUserId' => null,
			'dueDate' => null,
		]);

		$controller = new StepController(
			$request,
			Server::get(StepService::class),
			Server::get(BoardAccess::class),
			LeakMatrixFixture::ANNA,
		);

		$antwort = $controller->update($this->fixture->boardId, (int)$step->getId());

		$this->assertSame(Http::STATUS_OK, $antwort->getStatus());

		$geleert = $antwort->getData();
		$this->assertInstanceOf(Step::class, $geleert);
		$this->assertNull($geleert->getDueDate(), 'Die Frist muss sich ueber den Controller loeschen lassen.');
		$this->assertNull($geleert->getAssignedUserId(), 'Die Zuweisung ebenso.');
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
