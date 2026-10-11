<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

use OCP\IUserManager;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * Budgets je Person nach der Anmeldung; ein IP-Limit trüge nicht, weil alle
 * claude.ai-Nutzer aus demselben Anthropic-Adressbereich kommen.
 */
class McpRateLimiter {

	public const READ_LIMIT = 1200;
	public const PERIOD = 3600;

	public function __construct(
		private ILimiter $limiter,
		private IUserManager $users,
	) {
	}

	/**
	 * @return bool false, wenn das Budget erschöpft ist
	 */
	public function allowRead(AuthenticatedCaller $caller): bool {
		$user = $this->users->get($caller->userId);
		if ($user === null) {
			return false;
		}

		try {
			$this->limiter->registerUserRequest('projektwerk-mcp-read', self::READ_LIMIT, self::PERIOD, $user);
		} catch (IRateLimitExceededException) {
			return false;
		}

		return true;
	}
}
