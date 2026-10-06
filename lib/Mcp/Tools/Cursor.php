<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp\Tools;

/**
 * Undurchsichtiger Blätter-Cursor über einen Offset. Er trägt keine Berechtigung:
 * Jede Seite wird aus der gefilterten Menge des Aufrufers neu geschnitten.
 */
final class Cursor {

	public static function encode(int $offset): string {
		return rtrim(strtr(base64_encode('o:' . $offset), '+/', '-_'), '=');
	}

	/**
	 * @return int|null der Offset, oder null bei einem ungültigen Cursor
	 */
	public static function decode(?string $cursor): ?int {
		if ($cursor === null || $cursor === '') {
			return 0;
		}
		$raw = base64_decode(strtr($cursor, '-_', '+/'), true);
		if ($raw === false || !preg_match('/^o:(\d{1,9})$/', $raw, $match)) {
			return null;
		}

		return (int)$match[1];
	}
}
