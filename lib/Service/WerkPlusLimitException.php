<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Eine Grenze des freien Umfangs ist erreicht (#288, WerkPlus).
 *
 * Kein technischer Fehler, sondern eine Produktentscheidung: Das Feature ist da,
 * das Entitlement steuert nur, ab wann es greift. Die Meldung ist für den Nutzer
 * gedacht — der Controller reicht sie mit `402 Payment Required` an die
 * Oberfläche weiter.
 */
class WerkPlusLimitException extends \RuntimeException {
}
