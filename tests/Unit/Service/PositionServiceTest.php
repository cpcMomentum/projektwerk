<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\PositionService;
use PHPUnit\Framework\TestCase;

/**
 * Die Positionsrechnung, ohne Datenbank.
 *
 * Der Grund, warum sie ueberhaupt eine eigene Klasse ist: Genau hier steckt der
 * Fehler, der sich erst nach Wochen zeigt und dann nicht mehr zuzuordnen ist —
 * eine Spalte, die sich nach vielen Verschiebungen anders sortiert, als der
 * Nutzer sie gelassen hat.
 */
class PositionServiceTest extends TestCase {

	private PositionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new PositionService();
	}

	public function testFirstTicketOfAColumn(): void {
		$this->assertSame(PositionService::STEP, $this->service->between(null, null));
	}

	public function testAppendingGoesAStepBelowTheLast(): void {
		$this->assertSame(
			PositionService::STEP * 3,
			$this->service->between(PositionService::STEP * 2, null),
		);
	}

	public function testInsertingAtTheTopHalvesTheRemainingGap(): void {
		// Nicht `$after - STEP`: Das liefe nach genuegend Einfuegungen ins
		// Negative, und der Spielraum nach oben ist endlich.
		$this->assertSame(32768, $this->service->between(null, 65536));
		$this->assertSame(16384, $this->service->between(null, 32768));
	}

	public function testInsertingBetweenTwoNeighbours(): void {
		$this->assertSame(98304, $this->service->between(65536, 131072));
	}

	/**
	 * Ungerade Abstaende runden nachvollziehbar ab statt irgendwohin.
	 */
	public function testOddGapRoundsDown(): void {
		$this->assertSame(12, $this->service->between(10, 15));
		$this->assertSame(10, $this->service->between(10, 11));
	}

	/**
	 * Sechzehn Einfuegungen an derselben Stelle passen in eine Luecke.
	 *
	 * Das ist die Zusage hinter der Schrittweite 65536. Der Test faehrt sie
	 * wirklich durch, statt sie auszurechnen — 2^16 = 65536 stimmt auch dann
	 * noch, wenn jemand die Konstante aendert und den Kommentar vergisst.
	 */
	public function testSixteenInsertionsFitIntoOneGap(): void {
		$before = 0;
		$after = PositionService::STEP;

		$insertions = 0;
		while (!$this->service->needsRebalance($before, $after)) {
			$after = $this->service->between($before, $after);
			$insertions++;

			$this->assertGreaterThan($before, $after, 'Position hat den Vorgaenger eingeholt.');
			$this->assertLessThan(64, $insertions, 'Die Schleife endet nicht.');
		}

		$this->assertSame(16, $insertions);
	}

	public function testRebalanceIsNeededWhenTheGapIsGone(): void {
		$this->assertTrue($this->service->needsRebalance(10, 11));
		$this->assertTrue($this->service->needsRebalance(10, 10));
		$this->assertFalse($this->service->needsRebalance(10, 12));
	}

	/**
	 * An den Raendern ist Platz — bis auf den einen Fall ganz oben.
	 */
	public function testEdgesUsuallyHaveRoom(): void {
		$this->assertFalse($this->service->needsRebalance(999, null), 'Nach unten ist immer Platz.');
		$this->assertFalse($this->service->needsRebalance(null, null));
		$this->assertFalse($this->service->needsRebalance(null, 2));

		// Ganz oben mit Position 1 als erstem Nachbarn: `between()` lieferte 0,
		// beim naechsten Mal wieder 0. Da muss neu nummeriert werden.
		$this->assertTrue($this->service->needsRebalance(null, 1));
	}

	/**
	 * Verkehrt herum stehende Nachbarn sind ein Fehler, kein Sonderfall.
	 *
	 * Irgendeine Position zu erfinden verschöbe das Ticket sichtbar woandershin,
	 * als der Nutzer wollte — und niemand sähe, warum.
	 */
	public function testReversedNeighboursThrow(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->between(200, 100);
	}

	public function testEqualNeighboursThrow(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->between(100, 100);
	}

	public function testRebalanceSpreadsEvenlyInTheGivenOrder(): void {
		$positions = $this->service->rebalance([7, 3, 9]);

		$this->assertSame([7 => 65536, 3 => 131072, 9 => 196608], $positions);
		$this->assertSame([7, 3, 9], array_keys($positions), 'Die Reihenfolge ist die Vorgabe.');
	}

	public function testRebalanceOfAnEmptyColumn(): void {
		$this->assertSame([], $this->service->rebalance([]));
	}

	/**
	 * Nach dem Neunummerieren ist wieder Platz zwischen allen Nachbarn.
	 *
	 * Das ist der eigentliche Zweck, und ohne diesen Test liesse sich
	 * `rebalance()` durch etwas ersetzen, das zwar sortiert, aber keine Luecken
	 * laesst — der naechste Verschiebevorgang stuende dann sofort wieder an.
	 */
	public function testRebalanceLeavesRoomBetweenEveryPair(): void {
		$positions = array_values($this->service->rebalance(range(1, 20)));

		for ($i = 1; $i < count($positions); $i++) {
			$this->assertFalse(
				$this->service->needsRebalance($positions[$i - 1], $positions[$i]),
				'Zwischen ' . $positions[$i - 1] . ' und ' . $positions[$i] . ' ist kein Platz.',
			);
		}
	}
}
