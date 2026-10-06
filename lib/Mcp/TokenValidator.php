<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

/**
 * Fragt den Autorisierungsserver, ob ein Token gültig ist, und für wen.
 *
 * ProjektWerk stellt keine Tokens aus und prüft keine Signaturen selbst.
 */
interface TokenValidator {

	/**
	 * @return string|null die Benutzerkennung, oder null bei ungültigem Token
	 */
	public function userIdFor(string $token): ?string;

	/**
	 * Ob der Autorisierungsserver installiert und aktiv ist.
	 */
	public function isAvailable(): bool;
}
