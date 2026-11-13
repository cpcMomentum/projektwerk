<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Listener;

use OCA\Projektwerk\Service\MemberLifecycleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserCreatedEvent;

/**
 * Ein neues Konto mit der Kennung eines nicht fertig aufgeräumten darf dessen Reste nicht erben.
 *
 * @template-implements IEventListener<UserCreatedEvent>
 */
class UserCreatedListener implements IEventListener {

	public function __construct(
		private MemberLifecycleService $lifecycle,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserCreatedEvent) {
			return;
		}

		$uid = $event->getUid();
		if (in_array($uid, $this->lifecycle->pending(), true)) {
			$this->lifecycle->forget($uid);
		}
	}
}
