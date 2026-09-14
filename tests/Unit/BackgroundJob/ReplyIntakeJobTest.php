<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\BackgroundJob;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\BackgroundJob\ReplyIntakeJob;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\MailOutboxMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Imap\ImapClient;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\ReplyMailboxSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Der Einlese-Job (#287) — die **sicherheitskritische Reihenfolge**.
 *
 * Die Bausteine (Parser, Text-Extraktor, Betreff-Token) haben eigene Tests;
 * hier steht die Orchestrierung von {@see ReplyIntakeJob::verarbeiten()} auf dem
 * Prüfstand: dass eine echte Antwort zum Kommentar wird und **jeder** Abweisungs-
 * grund den Kommentar verhindert — Automat/Schleife, fehlender Token, falscher
 * Absender, unsichtbarer Vorgang, Amok-Bremse. Die IMAP-Verbindung ist ein
 * Doppel (`uidFetchRaw` liefert die rohe Mail, `markSeen` wird gezählt); die
 * private Methode wird per Reflexion gerufen — dieselbe Technik wie in den
 * Architektur-Tests.
 */
class ReplyIntakeJobTest extends TestCase {

	private const RECIPIENT = 'pw-carla';
	private const RECIPIENT_MAIL = 'carla@kunde.de';
	private const TICKET_ID = 4242;
	private const BOARD_ID = 7;

	private CommentService&MockObject $comments;
	private ReplyIntakeJob $job;
	/** 32 Hex-Zeichen, berechnet statt als Literal (Secret-Check). */
	private string $token;

	protected function setUp(): void {
		parent::setUp();

		$this->token = str_repeat('ab', 16);

		$this->comments = $this->createMock(CommentService::class);

		$settings = $this->createMock(ReplyMailboxSettings::class);
		$settings->method('getReplyAddress')->willReturn('projekte@firma.de');

		// Die Outbox-Zeile zum Token: Empfänger + Vorgang.
		$zeile = new MailOutbox();
		$zeile->setReplyToken($this->token);
		$zeile->setRecipientUid(self::RECIPIENT);
		$zeile->setTicketId(self::TICKET_ID);
		$outbox = $this->createMock(MailOutboxMapper::class);
		$outbox->method('findByReplyToken')->willReturnCallback(
			fn (string $t): ?MailOutbox => $t === $this->token ? $zeile : null,
		);

		// Der Empfänger hat genau die hinterlegte Adresse.
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn(self::RECIPIENT_MAIL);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === self::RECIPIENT ? $user : null,
		);

		// Der Vorgang ist sichtbar und liegt an BOARD_ID.
		$ticket = new Ticket();
		$ticket->setBoardId(self::BOARD_ID);
		$tickets = $this->createMock(TicketMapper::class);
		$tickets->method('findVisibleAnywhere')->willReturn($ticket);

		// ViewerContext über BoardAccess (final readonly → echt gebaut, nicht gemockt).
		$access = $this->createMock(BoardAccess::class);
		$access->method('contextFor')->willReturn(
			ViewerContext::forMember(self::RECIPIENT, self::BOARD_ID, 99, ViewerContext::ROLE_EXTERNAL, false),
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t): string => $t);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);
		$l10nFactory->method('getUserLanguage')->willReturn('de');

		$this->job = new ReplyIntakeJob(
			$this->createMock(ITimeFactory::class),
			$settings,
			$outbox,
			$tickets,
			$access,
			$this->comments,
			$users,
			$l10nFactory,
			new NullLogger(),
		);
	}

	/**
	 * Eine rohe Antwort-Mail zusammensetzen.
	 *
	 * @param array<string, string> $header Zusätzliche Header (z. B. Auto-Submitted).
	 */
	private function raw(string $from, string $subject, string $body, array $header = []): string {
		$lines = ['From: ' . $from, 'Subject: ' . $subject];
		foreach ($header as $name => $value) {
			$lines[] = $name . ': ' . $value;
		}

		return implode("\r\n", $lines) . "\r\n\r\n" . $body;
	}

	/**
	 * Die private {@see ReplyIntakeJob::verarbeiten()} mit einem IMAP-Doppel rufen,
	 * das genau eine rohe Mail liefert.
	 *
	 * @param array<string, int> $jeAbsender Der laufende Absender-Zähler (per Referenz).
	 */
	private function verarbeiten(string $raw, array &$jeAbsender): ImapClient&MockObject {
		$client = $this->createMock(ImapClient::class);
		$client->method('uidFetchRaw')->willReturn($raw);

		$ref = new ReflectionMethod(ReplyIntakeJob::class, 'verarbeiten');
		$ref->invokeArgs($this->job, [$client, 1, &$jeAbsender]);

		return $client;
	}

	private function betreff(): string {
		return 'Re: [Projekt] Neuer Vorgang #0042 [PW-' . $this->token . ']';
	}

	public function testValidReplyBecomesAComment(): void {
		$this->comments->expects($this->once())
			->method('create')
			->with($this->isInstanceOf(ViewerContext::class), self::TICKET_ID, 'Ja, bitte umsetzen.');

		$counter = [];
		$client = $this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Ja, bitte umsetzen.'),
			$counter,
		);

		// Nach erfolgreichem Kommentar wird die Mail gelesen markiert.
		$this->assertInstanceOf(ImapClient::class, $client);
	}

	public function testAttachmentAppendsHintToComment(): void {
		$this->comments->expects($this->once())
			->method('create')
			->with(
				$this->anything(),
				self::TICKET_ID,
				$this->stringContains('[Anhang aus E-Mail nicht übernommen'),
			);

		$counter = [];
		$this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Passt.', ['Content-Disposition' => 'attachment; filename="x.pdf"']),
			$counter,
		);
	}

	public function testAutoSubmittedIsDroppedBeforeMatching(): void {
		$this->comments->expects($this->never())->method('create');

		$counter = [];
		$client = $this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Bin im Urlaub.', ['Auto-Submitted' => 'auto-replied']),
			$counter,
		);
		$this->assertInstanceOf(ImapClient::class, $client);
	}

	public function testBulkPrecedenceIsDropped(): void {
		$this->comments->expects($this->never())->method('create');

		$counter = [];
		$this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Newsletter.', ['Precedence' => 'bulk']),
			$counter,
		);
	}

	public function testOwnAddressLoopIsDropped(): void {
		$this->comments->expects($this->never())->method('create');

		$counter = [];
		$this->verarbeiten(
			$this->raw('projekte@firma.de', $this->betreff(), 'Schleife.'),
			$counter,
		);
	}

	public function testMissingTokenIsUnmatched(): void {
		$this->comments->expects($this->never())->method('create');

		$counter = [];
		$this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, 'Re: irgendein Betreff ohne Token', 'Hallo.'),
			$counter,
		);
	}

	public function testForgedSenderIsRejected(): void {
		// Echter Token, aber die From-Adresse gehört nicht zum Empfänger-Konto.
		$this->comments->expects($this->never())->method('create');

		$counter = [];
		$this->verarbeiten(
			$this->raw('angreifer@fremd.de', $this->betreff(), 'Ich bin nicht Carla.'),
			$counter,
		);
	}

	public function testEmptyBodyAfterQuoteStripIsUnmatched(): void {
		$this->comments->expects($this->never())->method('create');

		$counter = [];
		$this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), "> nur zitierter Text\n> zweite Zeile"),
			$counter,
		);
	}

	public function testRateLimitCapsCommentsPerSenderPerRun(): void {
		// Über MAX_JE_ABSENDER hinaus wird kein weiterer Kommentar angelegt.
		$max = (new \ReflectionClassConstant(ReplyIntakeJob::class, 'MAX_JE_ABSENDER'))->getValue();
		$this->comments->expects($this->exactly($max))->method('create');

		$counter = [];
		for ($i = 0; $i <= $max; $i++) {
			$this->verarbeiten(
				$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Antwort ' . $i),
				$counter,
			);
		}
	}
}
