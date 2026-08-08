<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Access;

/**
 * Wird geworfen, wenn jemand ohne Mitgliedschaft auf ein Board zugreift.
 *
 * Die Abweisung passiert **vor** jeder Ticket-Abfrage. Ein Nicht-Mitglied
 * erreicht damit keinen Code, der ueber Laufzeit oder Fehlerform verraten
 * koennte, ob es das Board ueberhaupt gibt.
 *
 * Der Controller uebersetzt das in 403 — und zwar mit demselben Text wie ein
 * nicht existierendes Board, sonst ist die Fehlerform selbst das Leck.
 */
class NotAMemberException extends \RuntimeException {
}
