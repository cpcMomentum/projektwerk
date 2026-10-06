<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

/**
 * Der rohe Anfragerumpf. `IRequest` liefert nur die bereits zerlegten Parameter, und daraus
 * ließe sich ein Batch-Array nicht mehr von einem Objekt unterscheiden.
 */
class RequestBody {

	public const MAX_BYTES = 1048576;

	/**
	 * @return string|null der Rumpf, oder null wenn er größer als {@see MAX_BYTES} ist
	 */
	public function read(): ?string {
		$body = file_get_contents('php://input', false, null, 0, self::MAX_BYTES + 1);
		if ($body === false) {
			return '';
		}

		return strlen($body) > self::MAX_BYTES ? null : $body;
	}
}
