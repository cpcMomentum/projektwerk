<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Access;

use OCA\Projektwerk\Access\ChangeHighlighter;
use OCA\Projektwerk\Db\Ticket;
use PHPUnit\Framework\TestCase;

/**
 * Der Punkt „neu oder seit deinem Blick geändert" (#79, #175) als reine Regel —
 * container- und datenbankfrei, damit die Zeitstempel voll in der Hand liegen
 * und nichts an einer echten Uhr hängt.
 *
 * Aus Sicht von `ich`. Ein Lesestand liegt vor `NEU` und nach `ALT` — so
 * entscheidet je Fall, ob die Bewegung danach kam.
 */
class ChangeHighlighterTest extends TestCase {

	private const ICH = 'ich';
	private const ANDERE = 'andere';

	private const ALT = '2026-05-01T10:00:00+00:00';
	private const GESEHEN = '2026-06-01T10:00:00+00:00';
	private const NEU = '2026-07-01T10:00:00+00:00';

	private ChangeHighlighter $highlighter;

	protected function setUp(): void {
		parent::setUp();

		$this->highlighter = new ChangeHighlighter();
	}

	/**
	 * @param int $id Kennung.
	 * @param string $creator Anlegende Person.
	 * @param ?string $lastEditor Letzter Bearbeiter.
	 * @param string $updatedAt Änderungszeit (ATOM).
	 */
	private function ticket(int $id, string $creator, ?string $lastEditor, string $updatedAt): Ticket {
		$ticket = new Ticket();
		$ticket->setId($id);
		$ticket->setCreatorUserId($creator);
		$ticket->setLastEditorUserId($lastEditor);
		$ticket->setUpdatedAt(new \DateTime($updatedAt));

		return $ticket;
	}

	/**
	 * **Fremde Änderung an einem gesehenen Vorgang leuchtet** — der Kern aus #79.
	 */
	public function testForeignEditOnASeenTicketHighlights(): void {
		$tickets = [$this->ticket(1, self::ANDERE, self::ANDERE, self::NEU)];
		$changed = $this->highlighter->detect($tickets, [1 => self::GESEHEN], [], self::ICH);

		$this->assertArrayHasKey(1, $changed);
	}

	/**
	 * **Die eigene Änderung leuchtet nicht** (#175, Punkt 1): War ich der letzte
	 * Bearbeiter, brauche ich keinen Hinweis auf meine eigene Bewegung.
	 */
	public function testOwnEditDoesNotHighlight(): void {
		$tickets = [$this->ticket(2, self::ANDERE, self::ICH, self::NEU)];
		$changed = $this->highlighter->detect($tickets, [2 => self::GESEHEN], [], self::ICH);

		$this->assertArrayNotHasKey(2, $changed);
	}

	/**
	 * **Fremd angelegter, noch ungesehener Vorgang leuchtet** (#175, Punkt 2):
	 * neu statt bloß geändert — ohne eigenen Lesestand.
	 */
	public function testForeignUnseenTicketHighlightsAsNew(): void {
		$tickets = [$this->ticket(3, self::ANDERE, null, self::NEU)];
		$changed = $this->highlighter->detect($tickets, [], [], self::ICH);

		$this->assertArrayHasKey(3, $changed);
	}

	/**
	 * **Selbst angelegter Vorgang leuchtet nicht** (#175): Wer ihn anlegt, kennt
	 * ihn schon — auch ohne Lesestand.
	 */
	public function testOwnUnseenTicketDoesNotHighlight(): void {
		$tickets = [$this->ticket(4, self::ICH, null, self::NEU)];
		$changed = $this->highlighter->detect($tickets, [], [], self::ICH);

		$this->assertArrayNotHasKey(4, $changed);
	}

	/**
	 * **Fremder Kommentar an einem gesehenen Vorgang leuchtet** — auch ohne
	 * Ticket-Änderung (Änderung liegt vor dem Lesestand).
	 */
	public function testForeignCommentHighlights(): void {
		$tickets = [$this->ticket(5, self::ANDERE, self::ANDERE, self::ALT)];
		$newest = [5 => ['at' => self::NEU, 'author' => self::ANDERE]];
		$changed = $this->highlighter->detect($tickets, [5 => self::GESEHEN], $newest, self::ICH);

		$this->assertArrayHasKey(5, $changed);
	}

	/**
	 * **Der eigene Kommentar leuchtet nicht** (#175, Punkt 1): Ist der jüngste
	 * Kommentar meiner, brauche ich keinen Punkt.
	 */
	public function testOwnCommentDoesNotHighlight(): void {
		$tickets = [$this->ticket(6, self::ANDERE, self::ANDERE, self::ALT)];
		$newest = [6 => ['at' => self::NEU, 'author' => self::ICH]];
		$changed = $this->highlighter->detect($tickets, [6 => self::GESEHEN], $newest, self::ICH);

		$this->assertArrayNotHasKey(6, $changed);
	}

	/**
	 * **Ein ruhiger, gesehener Vorgang leuchtet nicht** — die Grundlinie: keine
	 * Bewegung nach dem Blick, kein Punkt.
	 */
	public function testSeenTicketWithoutNewMovementStaysDark(): void {
		$tickets = [$this->ticket(7, self::ANDERE, self::ANDERE, self::ALT)];
		$changed = $this->highlighter->detect($tickets, [7 => self::GESEHEN], [], self::ICH);

		$this->assertArrayNotHasKey(7, $changed);
	}
}
