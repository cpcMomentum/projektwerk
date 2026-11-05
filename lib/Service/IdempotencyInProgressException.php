<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

// 409: Die erste Anfrage mit dieser Kennung läuft noch; der Browser fragt später erneut.
class IdempotencyInProgressException extends \RuntimeException {
}
