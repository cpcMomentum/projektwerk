<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Comment;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\NotAuthorException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/**
 * Der Schreibpfad der Kommentare.
 *
 * Dass ein Kommentar an einem verborgenen Vorgang nicht **gelesen** wird, prüft
 * die Leak-Matrix. Hier steht die andere Hälfte: dass er sich auch nicht
 * schreiben, ändern oder löschen lässt — und dass die Autorenregel keine
 * Ausnahme für Verwaltungsrecht kennt.
 */
class CommentWritePathTest extends IntegrationTestCase {

	private CommentService $comments;
	private CommentMapper $mapper;
	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
		$this->comments = Server::get(CommentService::class);
		$this->mapper = Server::get(CommentMapper::class);
	}

	/**
	 * **Verwaltungsrecht ist kein Schreibrecht am fremden Beitrag.**
	 *
	 * Anna verwaltet dieses Board. Sie sieht Berts Kommentar, sie darf Spalten
	 * anlegen und Mitglieder pflegen — und sie kann seinen Satz trotzdem weder
	 * ändern noch löschen. Das ist die Hausregel „keine Admin-Ausnahme" an der
	 * Stelle, an der sie am ehesten aufgeweicht würde: Ein Verlauf, in den ein
	 * Dritter hineinschreiben kann, ist keiner.
	 */
	public function testAManagerCannotTouchSomeoneElsesComment(): void {
		$comment = $this->comments->create(
			$this->contextFor(LeakMatrixFixture::BERT),
			$this->fixture->ticketIds['public/anna'],
			'Von Bert.',
		);

		$anna = $this->contextFor(LeakMatrixFixture::ANNA);
		$this->assertTrue($anna->isManager, 'Der Fall lebt davon, dass Anna verwaltet.');

		try {
			$this->comments->update($anna, (int)$comment->getId(), 'Umgeschrieben.');
			$this->fail('Eine verwaltende Person darf fremde Kommentare nicht ändern.');
		} catch (NotAuthorException) {
			// erwartet
		}

		try {
			$this->comments->delete($anna, (int)$comment->getId());
			$this->fail('Eine verwaltende Person darf fremde Kommentare nicht löschen.');
		} catch (NotAuthorException) {
			// erwartet
		}

		$this->assertSame(
			'Von Bert.',
			$this->reload((int)$comment->getId())?->getBody(),
			'Der Text muss unangetastet in der Datenbank stehen.',
		);
	}

	/**
	 * Die verfassende Person darf — und der Zeitstempel zieht mit.
	 *
	 * Verglichen wird `>=` und nicht `>`: Eine Änderung in derselben Sekunde
	 * ergibt denselben Wert, weil die Datenbank die Mikrosekunden abschneidet.
	 * Genau daran hängt auch die Marke „bearbeitet" in der Oberfläche — sie
	 * bleibt bei einer Sofortkorrektur aus. Das ist bewusst in Kauf genommen und
	 * hier festgehalten, damit es niemand später für einen Fehler hält.
	 */
	public function testTheAuthorMayEdit(): void {
		$bert = $this->contextFor(LeakMatrixFixture::BERT);
		$comment = $this->comments->create(
			$bert,
			$this->fixture->ticketIds['public/anna'],
			'Erst so.',
		);

		$geaendert = $this->comments->update($bert, (int)$comment->getId(), '  Dann so.  ');

		$this->assertSame('Dann so.', $geaendert->getBody(), 'Der Text wird beschnitten gespeichert.');
		$this->assertGreaterThanOrEqual(
			$comment->getCreatedAt()?->format('Y-m-d H:i:s'),
			$geaendert->getUpdatedAt()?->format('Y-m-d H:i:s'),
		);
	}

	/**
	 * **Ein verborgener Vorgang bleibt verborgen, auch beim Schreiben.**
	 *
	 * Carla steht auf der Kundenseite; `internal/anna` gehört der anderen. Die
	 * Antwort muss dieselbe sein wie für ein Ticket, das es gar nicht gibt —
	 * eine eigene Fehlermeldung wäre bereits die Auskunft, dass dort etwas
	 * liegt.
	 */
	public function testWritingToAHiddenTicketIsRefused(): void {
		$this->expectException(DoesNotExistException::class);

		$this->comments->create(
			$this->contextFor(LeakMatrixFixture::CARLA),
			$this->fixture->ticketIds['internal/anna'],
			'Sollte nicht ankommen.',
		);
	}

	/**
	 * **Die Sichtbarkeit wird vor der Autorenschaft geprüft.**
	 *
	 * Carla versucht, den Kommentar an einem für sie verborgenen Vorgang zu
	 * ändern. Käme hier ein 403 („nicht Ihr Kommentar") statt eines 404, hätte
	 * sie erfahren, dass es diesen Kommentar gibt — die Reihenfolge der beiden
	 * Prüfungen ist deshalb keine Geschmacksfrage.
	 */
	public function testAHiddenCommentIsNotFoundRatherThanForbidden(): void {
		$fremder = $this->comments->create(
			$this->contextFor(LeakMatrixFixture::ANNA),
			$this->fixture->ticketIds['internal/anna'],
			'Nur für die eigene Seite.',
		);

		$this->expectException(DoesNotExistException::class);

		$this->comments->update(
			$this->contextFor(LeakMatrixFixture::CARLA),
			(int)$fremder->getId(),
			'Fremdzugriff.',
		);
	}

	public function testACommentNeedsText(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->comments->create(
			$this->contextFor(LeakMatrixFixture::ANNA),
			$this->fixture->ticketIds['public/anna'],
			"  \n\t ",
		);
	}

	/**
	 * Die Obergrenze fängt ab, was sonst die Datenbank abschneidet.
	 *
	 * `body` ist `TEXT`, also 65 535 **Byte**. Ein stilles Abschneiden wäre der
	 * schlechteste Ausgang: Der Kommentar stünde da, nur ohne sein Ende.
	 */
	public function testAnOverlongCommentIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->comments->create(
			$this->contextFor(LeakMatrixFixture::ANNA),
			$this->fixture->ticketIds['public/anna'],
			str_repeat('a', 10001),
		);
	}

	/**
	 * Gelöscht heißt weg — `pwerk_comments` hat kein `deleted_at`.
	 */
	public function testDeletingRemovesTheRow(): void {
		$anna = $this->contextFor(LeakMatrixFixture::ANNA);
		$comment = $this->comments->create(
			$anna,
			$this->fixture->ticketIds['public/anna'],
			'Kommt gleich wieder weg.',
		);

		$this->comments->delete($anna, (int)$comment->getId());

		$this->assertNull($this->reload((int)$comment->getId()));
	}

	private function contextFor(string $userId): ViewerContext {
		return $this->fixture->contextFor($userId);
	}

	/**
	 * Der Stand in der Datenbank — über die einzige Lesesignatur.
	 */
	private function reload(int $commentId): ?Comment {
		$ids = array_values($this->fixture->ticketIds);
		foreach ($this->mapper->findForTickets($ids) as $comment) {
			if ((int)$comment->getId() === $commentId) {
				return $comment;
			}
		}

		return null;
	}
}
