<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Notification;

use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\License\LicenseService;
use OCA\Projektwerk\License\TrialNotices;
use OCA\Projektwerk\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class NotifierTrialTest extends TestCase {

	private const UNTIL = 1_796_134_323;

	/** @var array<string, string> */
	private array $parsed = [];
	private ?int $trialEndsAt = self::UNTIL;
	private string $tier = 'werkplus';
	private int $now = self::UNTIL - 3 * 86400;

	protected function setUp(): void {
		parent::setUp();
		$this->trialEndsAt = self::UNTIL;
		$this->tier = 'werkplus';
		$this->now = self::UNTIL - 3 * 86400;
	}

	private function prepare(string $subject): array {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf($text, $p));
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l);
		$dates = $this->createMock(IDateTimeFormatter::class);
		$dates->method('formatDate')->with(self::UNTIL, 'long', null, $l)->willReturn('18. Dezember 2026');
		$tickets = $this->createMock(TicketMapper::class);
		$tickets->expects($this->never())->method('findVisibleAnywhere');

		$this->parsed = [];
		$action = $this->createStub(IAction::class);
		foreach (['setLabel', 'setParsedLabel', 'setPrimary'] as $method) {
			$action->method($method)->willReturnCallback(function (mixed $value) use ($action, $method): IAction {
				$this->parsed[$method] = var_export($value, true);
				return $action;
			});
		}
		$action->method('setLink')->willReturnCallback(function (string $link, string $type) use ($action): IAction {
			$this->parsed['actionLink'] = $link;
			$this->parsed['actionType'] = $type;
			return $action;
		});

		$n = $this->createStub(INotification::class);
		$n->method('getApp')->willReturn('projektwerk');
		$n->method('getObjectType')->willReturn('werkplus');
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn(['until' => self::UNTIL]);
		$n->method('createAction')->willReturn($action);
		$n->method('setParsedSubject')->willReturnCallback(function (string $s) use ($n): INotification {
			$this->parsed['subject'] = $s;
			return $n;
		});
		$n->method('setLink')->willReturnCallback(function (string $link) use ($n): INotification {
			$this->parsed['link'] = $link;
			return $n;
		});
		$n->method('setIcon')->willReturnSelf();
		$n->method('addParsedAction')->willReturnSelf();

		$license = $this->createStub(LicenseService::class);
		$license->method('trialEndsAt')->willReturnCallback(fn () => $this->trialEndsAt);
		$license->method('tier')->willReturnCallback(fn () => $this->tier);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);

		(new Notifier($factory, $this->createStub(IURLGenerator::class), $tickets, $dates, $license, $time))->prepare($n, 'de');
		return $this->parsed;
	}

	public function testReminderBeforeTheEndNamesTheDateAndOffersBuying(): void {
		$parsed = $this->prepare(TrialNotices::SUBJECT_7D);

		$this->assertSame('Der WerkPlus-Testzeitraum endet am 18. Dezember 2026.', $parsed['subject']);
		$this->assertSame('https://werkwolke.de/preis?app=projektwerk', $parsed['link']);
		$this->assertSame('https://werkwolke.de/preis?app=projektwerk', $parsed['actionLink']);
		$this->assertSame(IAction::TYPE_WEB, $parsed['actionType']);
		$this->assertSame("'Jetzt kaufen'", $parsed['setParsedLabel']);
	}

	public function testOneDayBeforeUsesTheSameSentence(): void {
		$this->now = self::UNTIL - 3600;
		$this->assertSame('Der WerkPlus-Testzeitraum endet am 18. Dezember 2026.', $this->prepare(TrialNotices::SUBJECT_1D)['subject']);
	}

	public function testAfterTheEndTheFreeScopeIsNamed(): void {
		$this->trialEndsAt = null;
		$this->tier = 'free';
		$this->assertStringContainsString('läuft im freien Umfang weiter', $this->prepare(TrialNotices::SUBJECT_END)['subject']);
	}

	public function testAnUnknownWerkPlusSubjectIsDiscarded(): void {
		$this->expectException(AlreadyProcessedException::class);
		$this->prepare('werkplus_unknown');
	}

	/**
	 * @return array<string, array{string, \Closure(self): void}>
	 */
	public static function staleReminders(): array {
		return [
			'7 Tage, wenn die 1-Tag-Stufe erreicht ist' => [TrialNotices::SUBJECT_7D, function (self $t): void {
				$t->now = self::UNTIL - 3600;
			}],
			'7 Tage nach dem Kauf' => [TrialNotices::SUBJECT_7D, function (self $t): void {
				$t->trialEndsAt = null;
			}],
			'1 Tag nach dem Testende' => [TrialNotices::SUBJECT_1D, function (self $t): void {
				$t->now = self::UNTIL + 60;
			}],
			'1 Tag eines früheren Testzeitraums' => [TrialNotices::SUBJECT_1D, function (self $t): void {
				$t->now = self::UNTIL - 3600;
				$t->trialEndsAt = self::UNTIL + 400 * 86400;
			}],
			'Ende nach dem Kauf' => [TrialNotices::SUBJECT_END, function (self $t): void {
				$t->trialEndsAt = null;
				$t->tier = 'werkplus';
			}],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('staleReminders')]
	public function testOutdatedRemindersAreDiscarded(string $subject, \Closure $situation): void {
		$situation($this);

		$this->expectException(AlreadyProcessedException::class);
		$this->prepare($subject);
	}
}
