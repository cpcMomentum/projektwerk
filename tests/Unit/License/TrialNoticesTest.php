<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\License;

use OCA\Projektwerk\License\EntitlementToken;
use OCA\Projektwerk\License\SharedStore;
use OCA\Projektwerk\License\TrialNotices;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrialNoticesTest extends TestCase {

	private const NOW = 1_792_332_723;
	private const UNTIL = self::NOW + 30 * 86400;

	/** @var array<string, string> */
	private array $shared = [];
	/** @var list<string> Reihenfolge von Vermerk und Versand */
	private array $events = [];
	/** @var list<array{user: string, subject: string, params: array<string, mixed>}> */
	private array $sent = [];
	/** @var (\Closure(): void)|null */
	private ?\Closure $otherApp = null;
	/** @var array{user: string, subject: string, params: array<string, mixed>} */
	private array $pending = ['user' => '', 'subject' => '', 'params' => []];

	protected function setUp(): void {
		parent::setUp();
		$this->shared = ['store_version' => '1'];
		$this->events = [];
		$this->sent = [];
		$this->otherApp = null;
	}

	private function notices(?IGroup $admins = null): TrialNotices {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				$this->assertSame(SharedStore::APP, $app);
				return $this->shared[$key] ?? $default;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->assertSame(SharedStore::APP, $app);
				$this->assertFalse($lazy);
				$this->assertFalse($sensitive);
				$this->shared[$key] = $value;
				$this->events[] = 'write:' . $key;
				return true;
			}
		);
		$appConfig->method('clearCache')->willReturnCallback(function (): void {
			if ($this->otherApp !== null) {
				($this->otherApp)();
			}
		});

		if ($admins === null) {
			$alice = $this->createStub(IUser::class);
			$alice->method('getUID')->willReturn('alice');
			$bob = $this->createStub(IUser::class);
			$bob->method('getUID')->willReturn('bob');
			$admins = $this->createStub(IGroup::class);
			$admins->method('getUsers')->willReturn([$alice, $bob]);
		}
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($admins);

		$manager = $this->createStub(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$n = $this->createStub(INotification::class);
			$this->pending = ['user' => '', 'subject' => '', 'params' => []];
			$n->method('setApp')->willReturnSelf();
			$n->method('setDateTime')->willReturnSelf();
			$n->method('setObject')->willReturnSelf();
			$n->method('setUser')->willReturnCallback(function (string $u) use ($n) {
				$this->pending['user'] = $u;
				return $n;
			});
			$n->method('setSubject')->willReturnCallback(function (string $s, array $p = []) use ($n) {
				$this->pending['subject'] = $s;
				$this->pending['params'] = $p;
				return $n;
			});
			return $n;
		});
		$manager->method('notify')->willReturnCallback(function (INotification $n): void {
			$this->sent[] = $this->pending;
			$this->events[] = 'send';
		});

		return new TrialNotices(new SharedStore($appConfig), $groups, $manager, $this->createStub(LoggerInterface::class));
	}

	private function token(?bool $trial = true, ?int $until = self::UNTIL): EntitlementToken {
		return new EntitlementToken('werkplus', [], self::NOW, self::NOW + 30 * 86400, self::NOW + 30 * 86400, null, $trial, $until);
	}

	/**
	 * @return array<string, array{int, ?string}>
	 */
	public static function stages(): array {
		return [
			'noch 8 Tage' => [8 * 86400, null],
			'genau 7 Tage' => [7 * 86400, SharedStore::NOTICE_7D],
			'noch 2 Tage' => [2 * 86400, SharedStore::NOTICE_7D],
			'genau 1 Tag' => [86400, SharedStore::NOTICE_1D],
			'eine Stunde' => [3600, SharedStore::NOTICE_1D],
			'Ende' => [0, SharedStore::NOTICE_END],
			'danach' => [-5 * 86400, SharedStore::NOTICE_END],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('stages')]
	public function testStageFollowsTheRemainingTrialTime(int $remaining, ?string $stage): void {
		$this->assertSame($stage, TrialNotices::stageAt($this->token(), self::UNTIL - $remaining));
	}

	public function testPurchasedOrUnknownKeysGetNoReminder(): void {
		$this->assertNull(TrialNotices::stageAt($this->token(false), self::UNTIL));
		$this->assertNull(TrialNotices::stageAt($this->token(null), self::UNTIL));
		$this->assertNull(TrialNotices::stageAt($this->token(true, null), self::UNTIL));
	}

	public function testNoticeIsWrittenBeforeSendingAndReachesEveryAdmin(): void {
		$this->notices()->sendDue($this->token(), self::UNTIL - 3 * 86400);

		$this->assertSame((string)self::UNTIL, $this->shared[SharedStore::NOTICE_7D]);
		$this->assertSame(['write:' . SharedStore::NOTICE_7D, 'send', 'send'], $this->events);
		$this->assertSame(['alice', 'bob'], array_column($this->sent, 'user'));
		$this->assertSame(TrialNotices::SUBJECT_7D, $this->sent[0]['subject']);
		$this->assertSame(['until' => self::UNTIL], $this->sent[0]['params']);
	}

	public function testANoticeAnotherWerkAppWroteMeanwhilePreventsTheSecondReminder(): void {
		$this->otherApp = function (): void {
			$this->shared[SharedStore::NOTICE_1D] = (string)self::UNTIL;
		};
		$this->notices()->sendDue($this->token(), self::UNTIL - 3600);

		$this->assertSame([], $this->sent);
	}

	public function testEachStageIsSentOnce(): void {
		$notices = $this->notices();
		$notices->sendDue($this->token(), self::UNTIL - 3 * 86400);
		$notices->sendDue($this->token(), self::UNTIL - 2 * 86400);
		$notices->sendDue($this->token(), self::UNTIL + 60);
		$notices->sendDue($this->token(), self::UNTIL + 7200);

		$this->assertSame([TrialNotices::SUBJECT_7D, TrialNotices::SUBJECT_7D, TrialNotices::SUBJECT_END, TrialNotices::SUBJECT_END], array_column($this->sent, 'subject'));
	}

	public function testANewTrialPeriodGetsItsOwnReminders(): void {
		$this->shared[SharedStore::NOTICE_7D] = (string)(self::UNTIL - 400 * 86400);
		$this->notices()->sendDue($this->token(), self::UNTIL - 3 * 86400);

		$this->assertCount(2, $this->sent);
	}

	public function testANewerStoreVersionWritesNoNoticeAndSendsNothing(): void {
		$this->shared['store_version'] = '2';
		$this->notices()->sendDue($this->token(), self::UNTIL - 3600);

		$this->assertSame([], $this->events);
	}

	public function testAnEmptyAdminGroupGetsNothingButTheNoticeStands(): void {
		$this->notices($this->createStub(IGroup::class))->sendDue($this->token(), self::UNTIL - 3600);

		$this->assertSame([], $this->sent);
		$this->assertSame((string)self::UNTIL, $this->shared[SharedStore::NOTICE_1D]);
	}
}
