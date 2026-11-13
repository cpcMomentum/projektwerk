<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\BackgroundJob;

use OCA\Projektwerk\Service\MemberLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Wiederholt das Aufräumen gelöschter Konten, das beim Löschen gescheitert ist. */
class ForgetRetryJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private MemberLifecycleService $lifecycle,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	/**
	 * @param mixed $argument Wird nicht verwendet.
	 */
	protected function run($argument): void {
		// Auch bei neu angelegtem Konto gleicher Kennung: Die Reste gehören der gelöschten Person.
		foreach ($this->lifecycle->pending() as $uid) {
			$this->lifecycle->forget($uid);
		}
	}
}
