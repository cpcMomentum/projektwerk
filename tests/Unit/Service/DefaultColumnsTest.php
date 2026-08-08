<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\BoardService;
use PHPUnit\Framework\TestCase;

/**
 * Die Spaltenvorgabe eines neuen Projekts — die Woerter selbst.
 *
 * Getrennt von der Integrationssuite, weil dort die Sprache der Umgebung
 * mitspielt: Die Spalten entstehen uebersetzt, und in der CI laeuft alles auf
 * Englisch. Welche Quellwoerter in der Vorgabe stehen, ist dagegen eine Frage
 * an den Code und braucht weder Datenbank noch Sprache.
 */
class DefaultColumnsTest extends TestCase {

	public function testTheDefaultColumnsAreTheAgreedSix(): void {
		$this->assertSame(
			['Eingegangen', 'Bestätigt', 'Eingeplant', 'In Arbeit', 'Erledigt', 'Verworfen'],
			BoardService::DEFAULT_COLUMNS,
		);
	}

	/**
	 * **Die ersten drei Spalten sind eine Leiter der Verbindlichkeit.**
	 *
	 * „wir haben es" — „wir machen es" — „wir wissen wann". Jede Stufe ist ein
	 * Vorgang, den jemand ausloest. Faellt eine weg, sagt eine der uebrigen
	 * mehr zu, als jemand gemeint hat.
	 */
	public function testTheFirstThreeAreTheCommitmentLadder(): void {
		$this->assertSame(
			['Eingegangen', 'Bestätigt', 'Eingeplant'],
			array_slice(BoardService::DEFAULT_COLUMNS, 0, 3),
		);
	}

	/**
	 * **Die erste Spalte ist der Eingang, nicht die Zusage.**
	 *
	 * Auf einem geteilten Board meldet der Kunde etwas. Faellt diese Spalte weg,
	 * faellt „wir haben es" mit „wir machen es" zusammen — jedes neue Ticket
	 * stuende sofort unter „Eingeplant" und saegte damit eine Zusage zu, die
	 * niemand gegeben hat.
	 */
	public function testTheFirstColumnIsTheIntake(): void {
		$this->assertSame('Eingegangen', BoardService::DEFAULT_COLUMNS[0]);
	}

	/**
	 * Kein Fachjargon in einem Namen, den der Kunde liest.
	 *
	 * §7 benennt „nach dem Publikum, nicht nach der Technik", und §8 verbietet
	 * kundenspezifische Spaltennamen — beide Seiten sehen dieselben. „Backlog"
	 * ist der naheliegende Rueckfall und deshalb hier ausdruecklich verboten.
	 */
	public function testNoJargonInTheDefaults(): void {
		foreach (['Backlog', 'Sprint', 'Ready', 'Done', 'WIP', 'To Do'] as $jargon) {
			$this->assertNotContains($jargon, BoardService::DEFAULT_COLUMNS, $jargon . ' ist Fachjargon.');
		}
	}

	/**
	 * Kein Warte-Zustand als Spalte.
	 *
	 * §9: Der Zustand „wartet auf Kunde" liegt **quer zu den Spalten** und ist
	 * ein Filterschalter, kein Ort. Eine solche Spalte waere die naheliegendste
	 * falsche Ergaenzung beim naechsten Nachdenken ueber die Vorgabe.
	 */
	public function testNoWaitingColumn(): void {
		foreach (BoardService::DEFAULT_COLUMNS as $title) {
			$this->assertStringNotContainsStringIgnoringCase('wartet', $title);
			$this->assertStringNotContainsStringIgnoringCase('warten', $title);
		}
	}
}
