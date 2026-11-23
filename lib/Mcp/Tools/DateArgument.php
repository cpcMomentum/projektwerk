<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

/**
 * Streng statt `new DateTimeImmutable()`, das auch „tomorrow“ schluckt; ohne Zone gilt die übergebene.
 */
final class DateArgument {

	public const DATE = ['!Y-m-d'];
	public const DATE_OR_TIME = ['!Y-m-d', \DateTimeInterface::ATOM, 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'];

	/** @param string[] $formats */
	public static function parse(string $value, array $formats, \DateTimeZone $zone): ?\DateTimeImmutable {
		foreach ($formats as $format) {
			$parsed = \DateTimeImmutable::createFromFormat($format, $value, $zone);
			$errors = \DateTimeImmutable::getLastErrors();
			if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
				return $parsed;
			}
		}

		return null;
	}
}
