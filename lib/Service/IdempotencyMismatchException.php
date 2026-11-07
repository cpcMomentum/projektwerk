<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

// 422: Dieselbe Kennung mit anderem Inhalt ist ein Fehler des Aufrufers, keine Wiederholung.
class IdempotencyMismatchException extends \RuntimeException {
}
