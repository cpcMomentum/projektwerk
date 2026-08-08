<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Die Sichtbarkeit gehoert der anderen Seite.
 *
 * Anders als bei einem verborgenen Ticket ist hier **403 die richtige Antwort
 * und nicht 404**: Der Betrachter sieht das Ticket ja, es steht vor ihm. Zu
 * verbergen gibt es nichts mehr — nur zu erklaeren, warum das eine Feld
 * schreibgeschuetzt ist.
 */
class NotOwningSideException extends \RuntimeException {
}
