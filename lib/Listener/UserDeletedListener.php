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
use OCP\User\Events\UserDeletedEvent;

/**
 * Nextcloud loescht ein Konto — ProjektWerk raeumt hinterher.
 *
 * **Duenn mit Absicht.** Der Listener entscheidet nichts; er reicht die Kennung
 * an {@see MemberLifecycleService} weiter. Die Begruendung, warum ueberhaupt
 * aufgeraeumt werden muss, steht dort — sie gehoert zur Regel, nicht zur
 * Verdrahtung.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {

	public function __construct(
		private MemberLifecycleService $lifecycle,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}

		$this->lifecycle->forget($event->getUser()->getUID());
	}
}
