<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Comment;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Kommentare schreiben, aendern, loeschen.
 *
 * **Kommentare haben keine eigene Sichtbarkeit.** Sie erben sie vollstaendig
 * vom Ticket — wer das Ticket sieht, sieht seine Kommentare, und wer es nicht
 * sieht, erfaehrt auch nicht, dass es welche gibt. Deshalb gibt es hier keine
 * einzige zusaetzliche Sichtbarkeitsbedingung: Jeder Weg beginnt mit der
 * gefilterten Ticketmenge aus {@see TicketMapper}, und was dort nicht
 * auftaucht, existiert fuer diesen Betrachter nicht.
 *
 * Die **einzige** eigene Regel ist die Autorenschaft: Aendern und Loeschen kann
 * nur die verfassende Person. Sie folgt nicht aus §7, sondern steht daneben —
 * siehe {@see NotAuthorException} fuer die Begruendung und den benannten Preis.
 *
 * Was hier ausdruecklich **nicht** passiert: Der Text wird nicht auf Links
 * durchsucht. Ein Kommentar, der eine Datei verlinkt, wird von Nextclouds
 * eigenen Freigaberegeln aufgeloest, nicht von der Sichtbarkeit des Tickets
 * (Akzeptanzkriterium zu #9). Ein Filter hier waere eine zweite
 * Sichtbarkeitsregel an einer zweiten Stelle — und umgehbar obendrein.
 */
class CommentService {

	/**
	 * Obergrenze in Zeichen.
	 *
	 * `body` ist `TEXT`, also 65 535 **Byte** — bei Umlauten und Emoji deutlich
	 * weniger als 65 535 Zeichen. Ohne eigene Grenze schluege erst die Datenbank
	 * zu, je nach Konfiguration mit stiller Abschneidung statt mit einem Fehler.
	 * Dann fehlte das Ende eines Kommentars, und niemand erfuehre warum.
	 */
	private const MAX_LENGTH = 10000;

	public function __construct(
		private CommentMapper $comments,
		private TicketMapper $tickets,
		private NotificationService $notifications,
		// **Für die Erwähnungen** (#202): `assignableFor()` ist die eine Stelle,
		// die „wer darf diesen Vorgang sehen" beantwortet. Eine zweite Fassung
		// derselben Regel hier wäre genau der zweite Ort, an dem sie stimmen
		// müsste — und der, der irgendwann nicht mehr stimmt.
		private StepService $steps,
	) {
	}

	/**
	 * Ein neuer Kommentar am Ende des Verlaufs.
	 *
	 * @throws DoesNotExistException      Ticket nicht sichtbar
	 * @throws \InvalidArgumentException  Text leer oder zu lang
	 */
	public function create(ViewerContext $viewer, int $ticketId, string $body): Comment {
		// Erst die Sichtbarkeit, dann der Inhalt: Sonst unterschiede sich die
		// Antwort auf ein verborgenes Ticket je nachdem, ob der Text gueltig
		// war — und genau daran liesse sich seine Existenz ablesen.
		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$text = $this->clean($body);

		$now = new \DateTime();

		$comment = new Comment();
		$comment->setTicketId($ticketId);
		$comment->setAuthorUserId($viewer->userId);
		$comment->setBody($text);
		$comment->setCreatedAt($now);
		// Gleich mitgesetzt, nicht nur weil die Spalte `notnull` ist: So heisst
		// `updatedAt > createdAt` genau „wurde nachtraeglich geaendert", und die
		// Oberflaeche braucht dafuer kein zweites Feld.
		$comment->setUpdatedAt($now);

		$gespeichert = $this->comments->insert($comment);

		// **Ankuendigen und senden — nach dem Schreiben** (#98). Erst hier steht
		// der Kommentar in der Datenbank; die Empfaengermenge liest ihn mit, und
		// die auslesende Person faellt in `announce()` ohnehin heraus.
		//
		// Der Versand haengt bewusst **hinter** dem Insert: Ein toter Mailserver
		// darf einen geschriebenen Kommentar nicht mitreissen. Dieselbe
		// Reihenfolge wie bei Ticket und Arbeitsschritt.
		$vorgemerkt = $this->notifications->announceToInvolved(
			$ticket,
			$viewer->userId,
			MailOutbox::EVENT_COMMENT_ADDED,
		);
		$this->notifications->deliver($vorgemerkt, $ticket);

		// **@-Erwähnungen** (#202): Wer im Text ausdrücklich genannt wird, wird
		// gepingt — auch wenn er nicht beteiligt ist. Aber **nur, wer den Vorgang
		// sehen darf:** Die genannten Kennungen werden gegen die sichtbare Menge
		// geschnitten, bevor irgendetwas entsteht. `announce()` blockt zwar
		// Privates und die eigene Handlung, prüft bei einem öffentlichen Vorgang
		// aber nicht die Mitgliedschaft — ein `@fremde-kennung` erreichte einen
		// Außenstehenden sonst und verriete ihm die Existenz des Vorgangs. Eigener
		// Anlass, deshalb nicht von der Kommentar-Drossel betroffen: Eine direkte
		// Erwähnung soll ankommen.
		$sichtbar = $this->steps->assignableFor($viewer, $ticketId);
		$erwaehnt = [];
		foreach (array_intersect($this->mentionsAus($text), $sichtbar) as $uid) {
			$erwaehnt = [...$erwaehnt, ...$this->notifications->announce(
				$ticket,
				$uid,
				$viewer->userId,
				MailOutbox::EVENT_COMMENT_MENTION,
			)];
		}
		$this->notifications->deliver($erwaehnt, $ticket);

		return $gespeichert;
	}

	/**
	 * Die @-Erwähnungen aus einem Kommentartext (#202) — die reinen Kennungen,
	 * eindeutig.
	 *
	 * `NcRichContenteditable` schreibt eine Erwähnung als `@kennung` oder, wenn
	 * die Kennung Sonderzeichen enthält, als `@"kennung"`. Beide Formen werden
	 * hier gelesen. **Ob die Kennung ein sichtberechtigtes Mitglied ist,
	 * entscheidet nicht diese Methode**, sondern `announce()` beim
	 * Benachrichtigen — hier wird nur gelesen, was dasteht.
	 *
	 * @return string[] Eindeutige Kennungen
	 */
	private function mentionsAus(string $text): array {
		preg_match_all('/@(?:"([^"]+)"|([a-zA-Z0-9_.@-]+))/', $text, $treffer, PREG_SET_ORDER);

		$uids = [];
		foreach ($treffer as $t) {
			$uid = ($t[1] ?? '') !== '' ? $t[1] : ($t[2] ?? '');
			if ($uid !== '') {
				$uids[] = $uid;
			}
		}

		return array_values(array_unique($uids));
	}

	/**
	 * Den eigenen Kommentar aendern.
	 *
	 * @throws DoesNotExistException      Kommentar nicht sichtbar
	 * @throws NotAuthorException         Kommentar einer anderen Person
	 * @throws \InvalidArgumentException  Text leer oder zu lang
	 */
	public function update(ViewerContext $viewer, int $commentId, string $body): Comment {
		$comment = $this->findVisibleComment($viewer, $commentId);
		$this->requireAuthor($viewer, $comment);

		$comment->setBody($this->clean($body));
		$comment->setUpdatedAt(new \DateTime());

		return $this->comments->update($comment);
	}

	/**
	 * Den eigenen Kommentar loeschen — endgueltig.
	 *
	 * Anders als beim Ticket gibt es hier **kein weiches Loeschen**:
	 * `pwerk_comments` hat kein `deleted_at`, und ein Verlauf mit unsichtbaren
	 * Luecken waere schlechter als einer ohne. Wer sich vertippt, schreibt neu.
	 *
	 * @throws DoesNotExistException Kommentar nicht sichtbar
	 * @throws NotAuthorException    Kommentar einer anderen Person
	 */
	public function delete(ViewerContext $viewer, int $commentId): Comment {
		$comment = $this->findVisibleComment($viewer, $commentId);
		$this->requireAuthor($viewer, $comment);

		return $this->comments->delete($comment);
	}

	/**
	 * @throws NotAuthorException
	 */
	private function requireAuthor(ViewerContext $viewer, Comment $comment): void {
		if ((string)$comment->getAuthorUserId() !== $viewer->userId) {
			// „ändern oder löschen": Dieselbe Sperre traegt beide Wege, und eine
			// Meldung, die nur den einen nennt, waere beim anderen schlicht
			// falsch. Sie steht im 403-Rumpf und kann damit im Fehlerfall in
			// einer Meldung landen.
			throw new NotAuthorException('Nur die verfassende Person kann diesen Kommentar ändern oder löschen.');
		}
	}

	/**
	 * Ein Kommentar, aber nur ueber die gefilterte Ticketmenge.
	 *
	 * Derselbe Umweg wie bei {@see StepService} und aus demselben Grund: Es gibt
	 * keine Methode, die „Kommentar 42" laedt — nur „die Kommentare zu den
	 * Tickets, die dieser Betrachter sehen darf" (§5.8). Die Reibung ist der
	 * Zweck.
	 *
	 * @throws DoesNotExistException
	 */
	private function findVisibleComment(ViewerContext $viewer, int $commentId): Comment {
		$ticketIds = array_map(
			static fn (Ticket $ticket): int => (int)$ticket->getId(),
			$this->tickets->findVisibleInBoard($viewer),
		);

		foreach ($this->comments->findForTickets($ticketIds) as $comment) {
			if ((int)$comment->getId() === $commentId) {
				return $comment;
			}
		}

		throw new DoesNotExistException('Kommentar nicht gefunden.');
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function clean(string $body): string {
		$text = trim($body);

		if ($text === '') {
			throw new \InvalidArgumentException('Ein Kommentar braucht einen Text.');
		}

		if (mb_strlen($text) > self::MAX_LENGTH) {
			throw new \InvalidArgumentException('Dieser Kommentar ist zu lang.');
		}

		return $text;
	}
}
