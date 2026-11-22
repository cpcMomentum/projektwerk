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
use OCA\Projektwerk\Imap\ImapCommandRejectedException;
use OCA\Projektwerk\Imap\ImapException;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\ReplyMailboxSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
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
	private const NOW = 1_800_000_000;

	private CommentService&MockObject $comments;
	private MailOutbox $zeile;
	private ReplyIntakeJob $job;
	/** 32 Hex-Zeichen, berechnet statt als Literal (Secret-Check). */
	private string $token;

	private bool $archiviert = false;

	/** @var list<int> */
	private array $geholt = [];

	private AbstractLogger $logger;

	/** @var array<string, int> Anfragen je Konto, wie der Nextcloud-Zähler über mehrere Läufe hinweg */
	private array $stunde = [];

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
		$zeile->setSentAt((new \DateTime())->setTimestamp(self::NOW - 86400));
		$this->zeile = $zeile;
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
		$access->method('contextFor')->willReturnCallback(
			fn (): ViewerContext => ViewerContext::forMember(self::RECIPIENT, self::BOARD_ID, 99, ViewerContext::ROLE_EXTERNAL, false, false, $this->archiviert),
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t): string => $t);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);
		$l10nFactory->method('getUserLanguage')->willReturn('de');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->job = new ReplyIntakeJob(
			$time,
			$settings,
			$outbox,
			$tickets,
			$access,
			$this->comments,
			$users,
			$l10nFactory,
			$this->logger = new class extends AbstractLogger {
				/** @var list<string> */
				public array $zeilen = [];

				public function log($level, string|\Stringable $message, array $context = []): void {
					$this->zeilen[] = (string)$message;
				}
			},
			$this->limiter(),
		);
	}

	private function limiter(): ILimiter {
		$limiter = $this->createMock(ILimiter::class);
		$limiter->method('registerUserRequest')->willReturnCallback(
			function (string $id, int $limit, int $period, IUser $user): void {
				$this->assertSame(3600, $period);
				$this->stunde[$id] = ($this->stunde[$id] ?? 0) + 1;
				if ($this->stunde[$id] > $limit) {
					throw new class('Stundengrenze') extends \RuntimeException implements IRateLimitExceededException {
					};
				}
			},
		);

		return $limiter;
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

	/** Die private {@see ReplyIntakeJob::verarbeiten()} mit einem IMAP-Doppel rufen, das genau eine rohe Mail liefert. */
	private function verarbeiten(string $raw, ?ImapClient $client = null): ImapClient {
		if ($client === null) {
			$client = $this->createMock(ImapClient::class);
			$client->method('uidFetchRaw')->willReturn($raw);
		}

		(new ReflectionMethod(ReplyIntakeJob::class, 'verarbeiten'))->invoke($this->job, $client, 1);

		return $client;
	}

	/** Postfach-Doppel mit Ordnerhierarchie „/“; `$vorhanden` sind die schon angelegten Ordner. */
	private function postfach(string $raw, array $vorhanden = []): ImapClient&MockObject {
		$client = $this->createMock(ImapClient::class);
		$client->method('uidFetchRaw')->willReturn($raw);
		$client->method('hierarchyDelimiter')->willReturn('/');
		$client->method('listFolders')->willReturn(['INBOX', ...$vorhanden]);

		return $client;
	}

	private function betreff(): string {
		return 'Re: [Projekt] Neuer Vorgang #0042 [PW-' . $this->token . ']';
	}

	public function testValidReplyBecomesAComment(): void {
		$this->comments->expects($this->once())
			->method('create')
			->with(
				$this->callback(static fn (ViewerContext $v): bool => $v->via === ViewerContext::VIA_MAIL),
				self::TICKET_ID,
				'Ja, bitte umsetzen.',
			);

		$client = $this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Ja, bitte umsetzen.'),
		);

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

		$this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Passt.', ['Content-Disposition' => 'attachment; filename="x.pdf"']),
		);
	}

	public function testAReplyToANotificationOlderThanSevenDaysIsDroppedAndMarkedSeen(): void {
		$this->zeile->setSentAt((new \DateTime())->setTimestamp(self::NOW - ReplyIntakeJob::TOKEN_GUELTIG_SEKUNDEN - 1));
		$this->comments->expects($this->never())->method('create');

		$client = $this->createMock(ImapClient::class);
		$client->method('uidFetchRaw')->willReturn($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Spät, aber doch.'));
		$client->expects($this->once())->method('markSeen')->with(1);

		$this->verarbeiten('', $client);
	}

	public function testAReplyExactlySevenDaysAfterSendingIsStillAccepted(): void {
		$this->zeile->setSentAt((new \DateTime())->setTimestamp(self::NOW - ReplyIntakeJob::TOKEN_GUELTIG_SEKUNDEN));
		$this->comments->expects($this->once())->method('create');

		$this->verarbeiten($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Auf den Tag genau.'));
	}

	public function testAnUnsentRowCountsFromItsCreation(): void {
		$this->zeile->setSentAt(null);
		$this->zeile->setCreatedAt((new \DateTime())->setTimestamp(self::NOW - 3600));
		$this->comments->expects($this->once())->method('create');

		$this->verarbeiten($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Gerade noch.'));
	}

	public function testAutoSubmittedIsDroppedBeforeMatching(): void {
		$this->comments->expects($this->never())->method('create');

		$client = $this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Bin im Urlaub.', ['Auto-Submitted' => 'auto-replied']),
		);
		$this->assertInstanceOf(ImapClient::class, $client);
	}

	public function testBulkPrecedenceIsDropped(): void {
		$this->comments->expects($this->never())->method('create');

		$this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Newsletter.', ['Precedence' => 'bulk']),
		);
	}

	public function testOwnAddressLoopIsDropped(): void {
		$this->comments->expects($this->never())->method('create');

		$this->verarbeiten(
			$this->raw('projekte@firma.de', $this->betreff(), 'Schleife.'),
		);
	}

	public function testAReplyToAnArchivedBoardIsNotTakenOver(): void {
		$this->archiviert = true;
		$this->comments->expects($this->never())->method('create');

		$client = $this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Ja, bitte umsetzen.'),
		);
		$this->assertInstanceOf(ImapClient::class, $client);
	}

	public function testMissingTokenIsUnmatched(): void {
		$this->comments->expects($this->never())->method('create');

		$this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, 'Re: irgendein Betreff ohne Token', 'Hallo.'),
		);
	}

	public function testForgedSenderIsRejected(): void {
		// Echter Token, aber die From-Adresse gehört nicht zum Empfänger-Konto.
		$this->comments->expects($this->never())->method('create');

		$this->verarbeiten(
			$this->raw('angreifer@fremd.de', $this->betreff(), 'Ich bin nicht Carla.'),
		);
	}

	public function testEmptyBodyAfterQuoteStripIsUnmatched(): void {
		$this->comments->expects($this->never())->method('create');

		$this->verarbeiten(
			$this->raw(self::RECIPIENT_MAIL, $this->betreff(), "> nur zitierter Text\n> zweite Zeile"),
		);
	}

	/**
	 * Den Lauf über ein Postfach-Doppel fahren; Mails aus `$echte` sind gültige Antworten, alle anderen ohne Bezug.
	 *
	 * @param list<int>|\Throwable $treffer Antwort des Servers auf die Betreff-Suche.
	 * @param list<int>|\Throwable $ungelesen Antwort des Servers auf die Suche nach allem Ungelesenen.
	 * @param list<int> $echte
	 */
	private function lauf(array|\Throwable $treffer, array|\Throwable $ungelesen, array $echte): void {
		$client = $this->createMock(ImapClient::class);
		$client->method('uidSearch')->willReturnCallback(
			static function (string $kriterium) use ($treffer, $ungelesen): array {
				$antwort = $kriterium === 'UNSEEN' ? $ungelesen : $treffer;
				if ($antwort instanceof \Throwable) {
					throw $antwort;
				}

				return $antwort;
			},
		);
		$this->geholt = [];
		$client->method('uidFetchRaw')->willReturnCallback(
			function (int $uid) use ($echte): string {
				$this->geholt[] = $uid;

				return in_array($uid, $echte, true)
					? $this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Echte Antwort ' . $uid)
					: $this->raw('fremd@example.org', 'Werbung', 'Kein Bezug');
			},
		);

		(new ReflectionMethod(ReplyIntakeJob::class, 'abarbeiten'))->invoke($this->job, $client);
	}

	private function batch(): int {
		return (new \ReflectionClassConstant(ReplyIntakeJob::class, 'BATCH'))->getValue();
	}

	public function testAReplyIsTakenOverEvenBehindMoreUnrelatedMailThanOneRunHandles(): void {
		$antwort = $this->batch() * 3 + 1;
		$this->comments->expects($this->once())->method('create');

		$this->lauf([$antwort], range(1, $antwort), [$antwort]);
	}

	public function testRepliesComeFirstAndAreNotFetchedTwice(): void {
		$this->lauf([3], [1, 2, 3], [3]);

		$this->assertSame([3, 1, 2], $this->geholt);
	}

	public function testRepliesAndUnrelatedMailEachHaveTheirOwnLimitPerRun(): void {
		$batch = $this->batch();
		$treffer = range($batch + 1, $batch * 3);

		$this->lauf($treffer, range(1, $batch * 3), []);

		$this->assertSame([...array_slice($treffer, 0, $batch), ...range(1, $batch)], $this->geholt);
	}

	public function testTheHourlyLimitSpansRepliesAndTheRest(): void {
		$max = ReplyIntakeJob::MAX_JE_STUNDE;
		$alle = range(1, $max + 1);
		$this->comments->expects($this->exactly($max))->method('create');

		$this->lauf(array_slice($alle, 0, 5), $alle, $alle);
	}

	public function testAServerThatRejectsTheSubjectSearchFallsBackToMailboxOrder(): void {
		$this->comments->expects($this->once())->method('create');

		$this->lauf(new ImapCommandRejectedException('IMAP A003 BAD'), [1, 2, 3], [3]);

		$this->assertSame([1, 2, 3], $this->geholt);
	}

	public function testALostConnectionDuringTheSubjectSearchEndsTheRun(): void {
		$this->expectException(ImapException::class);

		try {
			$this->lauf(new ImapException('IMAP: Verbindung unerwartet geschlossen'), [1, 2, 3], [3]);
		} finally {
			$this->assertSame([], $this->geholt);
		}
	}

	public function testRepliesAreTakenOverEvenWhenTheFullListCannotBeRead(): void {
		$this->comments->expects($this->once())->method('create');
		$this->expectException(ImapException::class);

		$this->lauf([7], new ImapException('IMAP: Antwortzeile ueberschreitet das Limit'), [7]);
	}

	public function testTheHourlyLimitHoldsAcrossRuns(): void {
		$max = ReplyIntakeJob::MAX_JE_STUNDE;
		$this->comments->expects($this->exactly($max))->method('create');

		$this->lauf(range(1, 15), range(1, 15), range(1, 15));
		$this->lauf(range(16, 30), range(16, 30), range(16, 30));
	}

	public function testAMailOverTheHourlyLimitGoesToNotMatchedAndTheLogNamesTheAccount(): void {
		$max = ReplyIntakeJob::MAX_JE_STUNDE;
		for ($i = 0; $i < $max; $i++) {
			$this->verarbeiten($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Antwort ' . $i));
		}
		$client = $this->postfach($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Eine zu viel'), ['INBOX/ProjektWerk/Nicht zugeordnet']);
		$client->expects($this->once())->method('uidMove')->with(1, 'INBOX/ProjektWerk/Nicht zugeordnet');
		$client->expects($this->never())->method('markSeen');

		$this->verarbeiten('', $client);

		$grenze = array_values(array_filter($this->logger->zeilen, static fn (string $z): bool => str_contains($z, 'Stunden-Grenze')));
		$this->assertCount(1, $grenze);
		$this->assertStringContainsString(self::RECIPIENT, $grenze[0]);
		$this->assertStringNotContainsString(self::RECIPIENT_MAIL, implode("\n", $this->logger->zeilen));
	}

	public function testATakenOverReplyIsMovedToProcessedAndTheFolderIsCreated(): void {
		$this->comments->expects($this->once())->method('create');
		$client = $this->postfach($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Ja.'));
		$client->expects($this->once())->method('createFolder')->with('INBOX/ProjektWerk/Verarbeitet');
		$client->expects($this->once())->method('subscribe')->with('INBOX/ProjektWerk/Verarbeitet');
		$client->expects($this->once())->method('uidMove')->with(1, 'INBOX/ProjektWerk/Verarbeitet');
		$client->expects($this->once())->method('markSeen')->with(1);

		$this->verarbeiten('', $client);
	}

	public function testAnUnmatchedMailIsMovedToNotMatchedIntoTheExistingFolder(): void {
		$this->comments->expects($this->never())->method('create');
		$client = $this->postfach($this->raw('angreifer@fremd.de', $this->betreff(), 'Ich bin nicht Carla.'), ['INBOX/ProjektWerk/Nicht zugeordnet']);
		$client->expects($this->never())->method('createFolder');
		$client->expects($this->once())->method('uidMove')->with(1, 'INBOX/ProjektWerk/Nicht zugeordnet');
		$client->expects($this->never())->method('markSeen');

		$this->verarbeiten('', $client);
	}

	public function testAnAutoReplyIsMovedToNotMatched(): void {
		$client = $this->postfach($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Bin im Urlaub.', ['Auto-Submitted' => 'auto-replied']));
		$client->expects($this->once())->method('uidMove')->with(1, 'INBOX/ProjektWerk/Nicht zugeordnet');

		$this->verarbeiten('', $client);
	}

	public function testWhenTheMoveFailsTheMailIsOnlyMarkedSeen(): void {
		$this->comments->expects($this->once())->method('create');
		$client = $this->postfach($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Ja.'));
		$client->method('uidMove')->willThrowException(new ImapException('IMAP: weder MOVE- noch UIDPLUS-Capability'));
		$client->expects($this->once())->method('markSeen')->with(1);

		$this->verarbeiten('', $client);
	}

	public function testWhenTheFolderCannotBeCreatedTheMailIsOnlyMarkedSeen(): void {
		$client = $this->postfach($this->raw('fremd@example.org', 'Werbung', 'Kein Bezug'));
		$client->method('createFolder')->willThrowException(new ImapCommandRejectedException('IMAP A005 NO'));
		$client->expects($this->never())->method('uidMove');
		$client->expects($this->once())->method('markSeen')->with(1);

		$this->verarbeiten('', $client);
	}

	public function testAServerWithoutFolderHierarchyOnlyMarksSeen(): void {
		$client = $this->createMock(ImapClient::class);
		$client->method('uidFetchRaw')->willReturn($this->raw('fremd@example.org', 'Werbung', 'Kein Bezug'));
		$client->method('hierarchyDelimiter')->willReturn(null);
		$client->expects($this->never())->method('uidMove');
		$client->expects($this->once())->method('markSeen')->with(1);

		$this->verarbeiten('', $client);
	}

	public function testTheCommentComesBeforeTheMailIsMarkedAndMoved(): void {
		$folge = [];
		$this->comments->method('create')->willReturnCallback(static function () use (&$folge) {
			$folge[] = 'create';

			return new \OCA\Projektwerk\Db\Comment();
		});
		$client = $this->postfach($this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Ja.'), ['INBOX/ProjektWerk/Verarbeitet']);
		$client->method('markSeen')->willReturnCallback(static function () use (&$folge): void {
			$folge[] = 'markSeen';
		});
		$client->method('uidMove')->willReturnCallback(static function () use (&$folge): void {
			$folge[] = 'uidMove';
		});

		$this->verarbeiten('', $client);

		$this->assertSame(['create', 'markSeen', 'uidMove'], $folge);
	}

	public function testAPoisonMailGoesUnseenToNotMatchedAndTheRunContinues(): void {
		$this->comments->expects($this->once())->method('create');
		$client = $this->createMock(ImapClient::class);
		$client->method('uidSearch')->willReturnCallback(static fn (string $k): array => $k === 'UNSEEN' ? [1, 2] : []);
		$client->method('uidFetchRaw')->willReturnCallback(fn (int $uid): string => $uid === 1
			? throw new ImapException('IMAP: Antwortzeile ueberschreitet das Limit')
			: $this->raw(self::RECIPIENT_MAIL, $this->betreff(), 'Ja.'));
		$client->method('hierarchyDelimiter')->willReturn('/');
		$client->method('listFolders')->willReturn(['INBOX', 'INBOX/ProjektWerk/Verarbeitet', 'INBOX/ProjektWerk/Nicht zugeordnet']);
		$bewegt = [];
		$client->method('uidMove')->willReturnCallback(static function (int $uid, string $ziel) use (&$bewegt): void {
			$bewegt[$uid] = $ziel;
		});
		$client->expects($this->once())->method('markSeen')->with(2);

		(new ReflectionMethod(ReplyIntakeJob::class, 'abarbeiten'))->invoke($this->job, $client);

		$this->assertSame([1 => 'INBOX/ProjektWerk/Nicht zugeordnet', 2 => 'INBOX/ProjektWerk/Verarbeitet'], $bewegt);
	}

	public function testAFolderTheServerAlreadyHasUnderADifferentSpellingIsStillUsed(): void {
		$client = $this->postfach($this->raw('fremd@example.org', 'Werbung', 'Kein Bezug'), ['INBOX/ProjektWerk/nicht zugeordnet']);
		$client->expects($this->never())->method('createFolder');
		$client->expects($this->once())->method('uidMove')->with(1, 'INBOX/ProjektWerk/nicht zugeordnet');

		$this->verarbeiten('', $client);
	}

	public function testAnAlreadyExistsAnswerStillAllowsTheMove(): void {
		$client = $this->postfach($this->raw('fremd@example.org', 'Werbung', 'Kein Bezug'));
		$client->method('createFolder')->willThrowException(new ImapCommandRejectedException('IMAP A005 NO [ALREADYEXISTS] Mailbox already exists'));
		$client->expects($this->once())->method('uidMove')->with(1, 'INBOX/ProjektWerk/Nicht zugeordnet');
		$client->expects($this->never())->method('markSeen');

		$this->verarbeiten('', $client);
	}

	public function testEachFolderIsCreatedOnlyOncePerRun(): void {
		$client = $this->createMock(ImapClient::class);
		$client->method('uidSearch')->willReturnCallback(static fn (string $k): array => $k === 'UNSEEN' ? [1, 2, 3] : []);
		$client->method('uidFetchRaw')->willReturn($this->raw('fremd@example.org', 'Werbung', 'Kein Bezug'));
		$client->method('hierarchyDelimiter')->willReturn('.');
		$client->method('listFolders')->willReturn(['INBOX']);
		$client->expects($this->once())->method('createFolder')->with('INBOX.ProjektWerk.Nicht zugeordnet');
		$client->expects($this->exactly(3))->method('uidMove');

		(new ReflectionMethod(ReplyIntakeJob::class, 'abarbeiten'))->invoke($this->job, $client);
	}
}
