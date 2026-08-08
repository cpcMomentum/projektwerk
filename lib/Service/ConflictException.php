<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Db\Ticket;

/**
 * Jemand anderes war schneller.
 *
 * Traegt den **aktuellen Stand** mit, nicht nur die Tatsache des Konflikts: Der
 * Controller antwortet mit 409 und dem Ticket, wie es jetzt ist. Ohne den Stand
 * bliebe dem Frontend nur ein Neuladen — und der Nutzer verlöre seine Eingabe,
 * ohne zu erfahren, was sich geaendert hat.
 */
class ConflictException extends \RuntimeException {

	public function __construct(
		public readonly Ticket $current,
	) {
		parent::__construct('Das Ticket wurde zwischenzeitlich geaendert.');
	}
}
