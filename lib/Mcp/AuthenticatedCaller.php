<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

/**
 * Wer ruft: die Nextcloud-Person hinter dem Token und der Client, dem es ausgestellt wurde.
 */
final readonly class AuthenticatedCaller {

	public function __construct(
		public string $userId,
		public string $clientId,
	) {
	}
}
