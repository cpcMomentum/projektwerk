<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Imap;

/** Der Server hat einen Befehl mit NO oder BAD abgelehnt; die Verbindung selbst steht noch. */
class ImapCommandRejectedException extends ImapException {
}
