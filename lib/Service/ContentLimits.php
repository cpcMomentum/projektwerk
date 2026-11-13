<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/** Obergrenzen in Zeichen, weil jedes Board-Laden diese Felder mitliefert. */
final class ContentLimits {

	public const TICKET_TITLE = 255;
	public const TICKET_DESCRIPTION = 50000;
	public const STEP_TITLE = 255;
	public const STEP_TEXT = 10000;
	public const STEPS_PER_TICKET = 100;

	public static function exceeds(?string $value, int $max): bool {
		return $value !== null && mb_strlen($value) > $max;
	}
}
