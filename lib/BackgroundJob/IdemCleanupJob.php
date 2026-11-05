<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\BackgroundJob;

use OCA\Projektwerk\Db\IdemKeyMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class IdemCleanupJob extends TimedJob {

	private const INTERVALL_SEKUNDEN = 86400;

	// Länger als jede Warteschlange im Browser realistisch liegen bleibt.
	private const AUFBEWAHRUNG_TAGE = 180;

	public function __construct(
		ITimeFactory $time,
		private IdemKeyMapper $keys,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVALL_SEKUNDEN);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$cutoff = $this->time->getDateTime('-' . self::AUFBEWAHRUNG_TAGE . ' days');
		$this->keys->deleteOlderThan($cutoff);
	}
}
