<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Prüft Tokens in-process über das `TokenValidationRequestEvent` der App `oidc` (H2CK).
 *
 * Bewusst kein HTTP-Selbstaufruf der Introspektion: der hielte zwei FPM-Worker je Anfrage.
 * Die Klasse wird über ihren Namen gebaut, weil `oidc` eine optionale Laufzeit-Abhängigkeit ist.
 */
class OidcTokenValidator implements TokenValidator {

	public const APP_ID = 'oidc';
	public const EVENT_CLASS = 'OCA\\OIDCIdentityProvider\\Event\\TokenValidationRequestEvent';

	public function __construct(
		private IEventDispatcher $dispatcher,
		private IAppManager $appManager,
	) {
	}

	public function isAvailable(): bool {
		return $this->appManager->isEnabledForAnyone(self::APP_ID) && class_exists(self::EVENT_CLASS);
	}

	public function userIdFor(string $token): ?string {
		if (!$this->isAvailable()) {
			return null;
		}

		$class = self::EVENT_CLASS;
		/** @var Event $event */
		$event = new $class($token);
		$this->dispatcher->dispatchTyped($event);

		if ($event->getIsValid() !== true) {
			return null;
		}
		$userId = $event->getUserId();

		return is_string($userId) && $userId !== '' ? $userId : null;
	}
}
