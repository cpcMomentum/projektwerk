<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\License;

use OCA\Projektwerk\AppInfo\Application;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/** Erinnerungen zum Testende an die Administratoren, über alle Werk-Apps hinweg genau einmal je Stufe. */
class TrialNotices {

	public const SUBJECT_7D = 'werkplus_trial_7d';
	public const SUBJECT_1D = 'werkplus_trial_1d';
	public const SUBJECT_END = 'werkplus_trial_end';

	private const STAGES = [
		SharedStore::NOTICE_7D => self::SUBJECT_7D,
		SharedStore::NOTICE_1D => self::SUBJECT_1D,
		SharedStore::NOTICE_END => self::SUBJECT_END,
	];

	public function __construct(
		private readonly SharedStore $store,
		private readonly IGroupManager $groups,
		private readonly IManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}

	public static function stageAt(EntitlementToken $token, int $now): ?string {
		if ($token->trial !== true || $token->licenseValidUntil === null) {
			return null;
		}
		$remaining = $token->licenseValidUntil - $now;
		return match (true) {
			$remaining <= 0 => SharedStore::NOTICE_END,
			$remaining <= 86400 => SharedStore::NOTICE_1D,
			$remaining <= 7 * 86400 => SharedStore::NOTICE_7D,
			default => null,
		};
	}

	public function sendDue(EntitlementToken $token, int $now): void {
		$stage = self::stageAt($token, $now);
		// Regel 6: Bei neuerer Ablage schreibt diese App nichts, also auch keine Vermerke.
		if ($stage === null || $this->store->isOutdated()) {
			return;
		}
		$value = (string)$token->licenseValidUntil;
		$this->store->reload();
		if ($this->store->notice($stage) === $value) {
			return;
		}
		// Erst schreiben, dann senden: Lieber eine verlorene Erinnerung als zwei aus zwei Werk-Apps.
		$this->store->setNotice($stage, $value);
		$this->send(self::STAGES[$stage], (int)$token->licenseValidUntil, $now);
	}

	private function send(string $subject, int $until, int $now): void {
		$admin = $this->groups->get('admin');
		if ($admin === null) {
			return;
		}
		foreach ($admin->getUsers() as $user) {
			try {
				$notification = $this->notifications->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($user->getUID())
					->setDateTime((new \DateTime())->setTimestamp($now))
					->setObject('werkplus', (string)$until)
					->setSubject($subject, ['until' => $until]);
				$this->notifications->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->warning('ProjektWerk: WerkPlus-Erinnerung nicht zugestellt', ['reason' => get_class($e) . ': ' . $e->getMessage()]);
			}
		}
	}
}
