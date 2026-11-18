<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Security;

use OCA\Projektwerk\Controller\GithubTokenController;
use OCA\Projektwerk\Controller\ReplyMailboxController;
use OCA\Projektwerk\Service\GithubService;
use OCA\Projektwerk\Service\ReplyMailboxSettings;
use OCA\Projektwerk\Service\SettingsAudit;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Änderungen am Antwort-Postfach und am eigenen GitHub-Token hinterlassen eine Spur: wer und welche Felder, nie die Werte.
 */
class SettingsAuditTrailTest extends TestCase {

	private const STAND = [
		'replyEnabled' => false,
		'replyAddress' => 'projekte@firma.example',
		'imapHost' => 'imap.firma.example',
		'imapPort' => 993,
		'imapSecurity' => 'ssl',
		'imapUser' => 'projekte',
		'imapFolder' => 'INBOX',
		'imapPasswordSet' => true,
		'werkplus' => true,
	];

	/** @var list<array<string, string>> */
	private array $ereignisse = [];

	private AbstractLogger $logger;

	private function audit(): SettingsAudit {
		$events = $this->createStub(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->assertInstanceOf(CriticalActionPerformedEvent::class, $event);
			$this->ereignisse[] = $event->getParameters();
		});
		$this->logger = new class extends AbstractLogger {
			/** @var list<string> */
			public array $zeilen = [];

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->zeilen[] = $message . ' ' . json_encode($context);
			}
		};

		return new SettingsAudit($events, $this->logger);
	}

	private function allesGeschriebene(): string {
		return json_encode($this->ereignisse) . implode('', $this->logger->zeilen);
	}

	/** @param array<string, mixed> $nachher */
	private function mailbox(array $nachher): ReplyMailboxController {
		$settings = $this->createStub(ReplyMailboxSettings::class);
		$settings->method('getPublicConfig')->willReturnOnConsecutiveCalls(self::STAND, $nachher);

		return new ReplyMailboxController($this->createStub(IRequest::class), $settings, $this->audit(), $this->createStub(IL10N::class), new NullLogger(), 'admin');
	}

	public function testAChangedMailboxNamesTheActorAndTheFieldsButNoValues(): void {
		$this->mailbox(array_replace(self::STAND, ['imapHost' => 'imap.anders.example', 'replyEnabled' => true]))
			->save(true, 'projekte@firma.example', 'imap.anders.example', 993, 'ssl', 'projekte', 'INBOX', 'geheim-123');

		$this->assertCount(1, $this->ereignisse);
		$this->assertSame('admin', $this->ereignisse[0]['user']);
		$this->assertSame('replyEnabled, imapHost, imapPassword', $this->ereignisse[0]['fields']);
		$this->assertCount(1, $this->logger->zeilen);
		$this->assertStringNotContainsString('geheim-123', $this->allesGeschriebene());
		$this->assertStringNotContainsString('imap.anders.example', $this->allesGeschriebene());
	}

	public function testSavingWithoutAChangeLeavesNoTrace(): void {
		$this->mailbox(self::STAND)->save(false, 'projekte@firma.example', 'imap.firma.example', 993, 'ssl', 'projekte', 'INBOX');

		$this->assertSame([], $this->ereignisse);
		$this->assertSame([], $this->logger->zeilen);
	}

	public function testAPasswordDroppedWithTheOldAccessCountsAsAPasswordChange(): void {
		$this->mailbox(array_replace(self::STAND, ['imapUser' => 'anders', 'imapPasswordSet' => false]))
			->save(false, 'projekte@firma.example', 'imap.firma.example', 993, 'ssl', 'anders', 'INBOX');

		$this->assertSame('imapUser, imapPassword', $this->ereignisse[0]['fields']);
	}

	public function testClearingAPasswordThatWasNeverStoredLeavesNoTrace(): void {
		$ohnePasswort = array_replace(self::STAND, ['imapPasswordSet' => false]);
		$settings = $this->createStub(ReplyMailboxSettings::class);
		$settings->method('getPublicConfig')->willReturn($ohnePasswort);
		$controller = new ReplyMailboxController($this->createStub(IRequest::class), $settings, $this->audit(), $this->createStub(IL10N::class), new NullLogger(), 'admin');

		$controller->save(false, 'projekte@firma.example', 'imap.firma.example', 993, 'ssl', 'projekte', 'INBOX', '', true);

		$this->assertSame([], $this->ereignisse);
	}

	public function testStoringAndRemovingTheOwnGithubTokenIsRecordedWithoutTheToken(): void {
		$github = $this->createStub(GithubService::class);
		$github->method('hasToken')->willReturnOnConsecutiveCalls(true, false);
		$controller = new GithubTokenController($this->createStub(IRequest::class), $github, $this->audit(), 'anna');

		$controller->update('ghp_sehrgeheim');
		$controller->destroy();
		$controller->destroy();

		$this->assertSame(['personal GitHub token stored', 'personal GitHub token removed'], array_column($this->ereignisse, 'what'));
		$this->assertSame(['anna', 'anna'], array_column($this->ereignisse, 'user'));
		$this->assertStringNotContainsString('ghp_sehrgeheim', $this->allesGeschriebene());
	}
}
