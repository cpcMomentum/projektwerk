<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\MailOutboxMapper;
use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;
use OCA\Projektwerk\Service\MailDispatcher;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Server;
use Psr\Log\NullLogger;

/**
 * Der Versandweg gegen eine echte Datenbank.
 *
 * **Der Mailer ist ein Doppel, die Datenbank nicht.** Was hier geprüft wird,
 * ist nicht das SMTP-Gespräch — das hat S4 an der laufenden Instanz gemessen —,
 * sondern was aus dessen Ergebnis in der Tabelle wird. Genau dort stehen die
 * drei Zustände aus §5.24, und genau dort entscheidet sich, ob „der Kunde
 * bekommt nichts" später beantwortbar ist.
 *
 * Das Doppel bildet die **gemessene** Eigenart nach: `send()` wirft bei einem
 * Transportfehler nichts, sondern gibt die fehlgeschlagenen Empfänger zurück.
 * Ein Doppel, das stattdessen wirft, würde einen Code grün machen, der in
 * Wirklichkeit jeden Fehlschlag verschluckt.
 */
class MailDispatcherTest extends IntegrationTestCase {

	private const MIT_ADRESSE = 'pw-mail-mit';
	private const OHNE_ADRESSE = 'pw-mail-ohne';

	/** Irgendein Projekt — die Aufloesung faellt hier immer auf die Vorgabe. */
	private const BOARD = 4200;

	private MailOutboxMapper $outbox;
	private NotifyPrefMapper $prefs;

	protected function setUp(): void {
		parent::setUp();

		$this->outbox = Server::get(MailOutboxMapper::class);
		$this->prefs = Server::get(NotifyPrefMapper::class);
	}

	/**
	 * Ein Versandweg mit einem Mailer-Doppel, das ein festgelegtes Ergebnis
	 * liefert.
	 *
	 * @param string[]|\Throwable $ergebnis Fehlgeschlagene Empfänger, oder was geworfen wird.
	 */
	private function dispatcher(array|\Throwable $ergebnis = []): MailDispatcher {
		$nachricht = $this->createStub(IMessage::class);

		$mailer = $this->createStub(IMailer::class);
		$mailer->method('createMessage')->willReturn($nachricht);
		if ($ergebnis instanceof \Throwable) {
			$mailer->method('send')->willThrowException($ergebnis);
		} else {
			$mailer->method('send')->willReturn($ergebnis);
		}

		$mit = $this->createStub(IUser::class);
		$mit->method('getEMailAddress')->willReturn('kunde@example.invalid');

		$ohne = $this->createStub(IUser::class);
		// Nextcloud liefert je nach Backend `null` **oder** einen leeren String —
		// beide bedeuten „keine Adresse", und beide muessen hier ankommen.
		$ohne->method('getEMailAddress')->willReturn('');

		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnMap([
			[self::MIT_ADRESSE, $mit],
			[self::OHNE_ADRESSE, $ohne],
		]);

		$l10n = $this->createStub(IFactory::class);
		$l10n->method('getUserLanguage')->willReturn('de');
		$l10n->method('findGenericLanguage')->willReturn('en');

		return new MailDispatcher(
			$this->outbox,
			$this->prefs,
			$mailer,
			$users,
			$l10n,
			new NullLogger(),
		);
	}

	/**
	 * Der gute Fall: Zeile entsteht, Versand gelingt, Zustand steht auf `sent`.
	 */
	public function testASuccessfulSendIsRecordedAsSent(): void {
		$dispatcher = $this->dispatcher([]);

		$zeile = $dispatcher->queue(self::MIT_ADRESSE, 4711, MailOutbox::EVENT_TICKET_ASSIGNED, self::BOARD);
		$this->assertNotNull($zeile);
		$this->assertSame(MailOutbox::STATUS_PENDING, $zeile->getStatus());
		$this->assertSame('de', $zeile->getLang(), 'Die Sprache des Empfaengers, nicht die des Ausloesers.');

		$fertig = $dispatcher->flush($zeile, 'Betreff', 'Text');

		$this->assertSame(MailOutbox::STATUS_SENT, $fertig->getStatus());
		$this->assertNotNull($fertig->getSentAt());
		$this->assertSame(1, (int)$fertig->getAttempts());
		$this->assertNull($fertig->getLastError());
	}

	/**
	 * **Der Befund aus S4, als Test.**
	 *
	 * `send()` wirft bei einem Transportfehler nichts — es gibt die
	 * fehlgeschlagenen Empfaenger zurueck. Wer nur `try/catch` schreibt, haelt
	 * das hier fuer einen Erfolg, und niemand bemerkt es je.
	 */
	public function testAFailedTransportIsRecognisedFromTheReturnValue(): void {
		$dispatcher = $this->dispatcher(['kunde@example.invalid']);

		$zeile = $dispatcher->queue(self::MIT_ADRESSE, 4711, MailOutbox::EVENT_TICKET_CREATED, self::BOARD);
		$fertig = $dispatcher->flush($zeile, 'Betreff', 'Text');

		$this->assertSame(MailOutbox::STATUS_FAILED, $fertig->getStatus());
		$this->assertNull($fertig->getSentAt());
		$this->assertSame(1, (int)$fertig->getAttempts());
		$this->assertStringContainsString('kunde@example.invalid', (string)$fertig->getLastError());
	}

	/**
	 * Auch eine Ausnahme wird zum Zustand — sie kommt nur aus einer anderen
	 * Ecke (ungueltiger Absender, kaputte Konfiguration), nicht aus dem
	 * Transport.
	 */
	public function testAnExceptionIsAlsoRecordedInsteadOfEscaping(): void {
		$dispatcher = $this->dispatcher(new \RuntimeException('kaputt'));

		$zeile = $dispatcher->queue(self::MIT_ADRESSE, 4711, MailOutbox::EVENT_STEP_ASSIGNED, self::BOARD);
		$fertig = $dispatcher->flush($zeile, 'Betreff', 'Text');

		$this->assertSame(MailOutbox::STATUS_FAILED, $fertig->getStatus());
	}

	/**
	 * **Die drei Zustaende, die im Postfach gleich aussehen** (§5.24).
	 *
	 * Ohne Adresse: `skipped_no_address`, ein Versuch wurde nicht gezaehlt —
	 * ein erneuter aenderte nichts. Kanal aus: **gar keine Zeile**.
	 */
	public function testTheThreeSilentCasesStayDistinguishable(): void {
		$dispatcher = $this->dispatcher([]);

		// (1) Keine Adresse.
		$ohne = $dispatcher->queue(self::OHNE_ADRESSE, 4711, MailOutbox::EVENT_TICKET_ASSIGNED, self::BOARD);
		$this->assertNotNull($ohne, 'Der Kanal ist an — die Zeile muss entstehen.');
		$fertig = $dispatcher->flush($ohne, 'Betreff', 'Text');

		$this->assertSame(MailOutbox::STATUS_SKIPPED_NO_ADDRESS, $fertig->getStatus());
		$this->assertSame(
			0,
			(int)$fertig->getAttempts(),
			'Kein Versuch gezaehlt: Ein erneuter aenderte nichts an einer fehlenden Adresse.',
		);

		// (2) Kanal abgeschaltet — keine Zeile.
		$aus = new NotifyPref();
		$aus->setUserId(self::MIT_ADRESSE);
		$aus->setPrefKey(NotifyPref::CHANNEL_MAIL);
		$aus->setEnabled(0);
		$this->prefs->insert($aus);

		$this->assertNull(
			$dispatcher->queue(self::MIT_ADRESSE, 4711, MailOutbox::EVENT_TICKET_ASSIGNED, self::BOARD),
			'Abgeschalteter Kanal darf gar keine Zeile erzeugen — das ist der Unterschied zu „keine Adresse".',
		);
	}

	/**
	 * Der Nachlauf nimmt sich `pending` und `failed` vor — **nicht**
	 * `skipped_no_address` und nicht, was aufgegeben wurde.
	 */
	public function testTheRetryQueueSkipsWhatCannotBeHelped(): void {
		$dispatcher = $this->dispatcher(['kunde@example.invalid']);

		$gescheitert = $dispatcher->flush(
			$dispatcher->queue(self::MIT_ADRESSE, 4711, MailOutbox::EVENT_TICKET_CREATED, self::BOARD),
			'Betreff',
			'Text',
		);
		$ohneAdresse = $dispatcher->flush(
			$dispatcher->queue(self::OHNE_ADRESSE, 4711, MailOutbox::EVENT_TICKET_CREATED, self::BOARD),
			'Betreff',
			'Text',
		);

		// Eine Zeile, die die Obergrenze erreicht hat.
		$aufgegeben = $dispatcher->queue(self::MIT_ADRESSE, 4712, MailOutbox::EVENT_TICKET_CREATED, self::BOARD);
		$aufgegeben->setAttempts(MailOutboxMapper::MAX_ATTEMPTS);
		$aufgegeben->setStatus(MailOutbox::STATUS_FAILED);
		$this->outbox->update($aufgegeben);

		$ids = array_map(
			static fn (MailOutbox $z): int => (int)$z->getId(),
			$this->outbox->findRetryable(),
		);

		$this->assertContains((int)$gescheitert->getId(), $ids);
		$this->assertNotContains(
			(int)$ohneAdresse->getId(),
			$ids,
			'Eine fehlende Adresse ist kein Wiederholungsfall.',
		);
		$this->assertNotContains(
			(int)$aufgegeben->getId(),
			$ids,
			'Nach MAX_ATTEMPTS wird nicht weiter versucht.',
		);
	}

	/**
	 * **Das Unterdrueckungsfenster** (#98): Die erste Kommentar-Mail geht raus,
	 * die zweite zum selben Vorgang nicht mehr.
	 *
	 * Ein lebhafter Abgleich mit zehn Kommentaren erzeugte sonst zehn Mails an
	 * jeden Beteiligten — und das ist der Punkt, an dem jemand den Mailkanal
	 * abschaltet und dabei die Zuweisungen verliert, die die wichtigen sind.
	 *
	 * Die zweite Zeile wird **festgehalten**, nicht weggelassen: „unterdrueckt"
	 * ist etwas anderes als „abgeschaltet" (gar keine Zeile) und als
	 * „keine Adresse". Wer spaeter fragt, warum eine Mail ausblieb, findet die
	 * Antwort im Ausgangskorb statt im Log.
	 */
	public function testASecondCommentWithinTheWindowIsSuppressed(): void {
		$dispatcher = $this->dispatcher(['kunde@example.invalid']);

		$erste = $dispatcher->queue(self::MIT_ADRESSE, 4711, MailOutbox::EVENT_COMMENT_ADDED, self::BOARD);
		$this->assertNotNull($erste, 'Die erste Kommentar-Mail geht sofort raus.');
		$this->assertSame(MailOutbox::STATUS_PENDING, $erste->getStatus());

		$zweite = $dispatcher->queue(self::MIT_ADRESSE, 4711, MailOutbox::EVENT_COMMENT_ADDED, self::BOARD);
		$this->assertNull($zweite, 'Im Fenster entsteht keine zweite zu versendende Zeile.');

		$alle = $this->outbox->findRetryable();
		$this->assertCount(
			1,
			array_filter($alle, static fn (MailOutbox $z): bool => (int)$z->getTicketId() === 4711),
			'Der Nachlauf darf die unterdrueckte Zeile nicht aufgreifen — sie ist kein Fehlschlag.',
		);
	}

	/**
	 * **Zuweisungen werden nie unterdrueckt.**
	 *
	 * Sie sind die Nachrichten, die zaehlen, und sie kommen einzeln. Sich davon
	 * abzumelden, dass einem Arbeit zugewiesen wird, waere kein Komfort,
	 * sondern ein Loch — deshalb steht in `GEDROSSELT` nur der Kommentar.
	 */
	public function testAssignmentsAreNeverSuppressed(): void {
		$dispatcher = $this->dispatcher(['kunde@example.invalid']);

		$erste = $dispatcher->queue(self::MIT_ADRESSE, 4712, MailOutbox::EVENT_TICKET_ASSIGNED, self::BOARD);
		$zweite = $dispatcher->queue(self::MIT_ADRESSE, 4712, MailOutbox::EVENT_STEP_ASSIGNED, self::BOARD);
		$dritte = $dispatcher->queue(self::MIT_ADRESSE, 4712, MailOutbox::EVENT_TICKET_ASSIGNED, self::BOARD);

		$this->assertNotNull($erste);
		$this->assertNotNull($zweite, 'Ein anderer Anlass zum selben Vorgang bleibt ohnehin unberuehrt.');
		$this->assertNotNull($dritte, 'Auch dieselbe Zuweisung ein zweites Mal geht raus.');
	}

	/**
	 * Das Fenster trennt nach Vorgang **und** nach Person.
	 *
	 * Sonst brachte ein Kommentar an Vorgang A den an Vorgang B zum Schweigen —
	 * oder schlimmer: Annas Mail unterdrueckte Berts.
	 */
	public function testTheWindowSeparatesByTicketAndByPerson(): void {
		$dispatcher = $this->dispatcher(['kunde@example.invalid']);

		$this->assertNotNull($dispatcher->queue(self::MIT_ADRESSE, 4713, MailOutbox::EVENT_COMMENT_ADDED, self::BOARD));
		$this->assertNotNull(
			$dispatcher->queue(self::MIT_ADRESSE, 4714, MailOutbox::EVENT_COMMENT_ADDED, self::BOARD),
			'Ein anderer Vorgang hat sein eigenes Fenster.',
		);
		$this->assertNotNull(
			$dispatcher->queue(self::OHNE_ADRESSE, 4713, MailOutbox::EVENT_COMMENT_ADDED, self::BOARD),
			'Eine andere Person hat ihr eigenes Fenster.',
		);
	}
}
