<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Der Betrachter verwaltet dieses Board zwar, gehoert ihm aber nicht.
 *
 * Eine eigene Ausnahme neben {@see NotManagerException}, weil sie eine andere
 * Frage beantwortet — und weil die Meldung sonst luege: „nur mit
 * Verwaltungsrecht" ist genau das, was die Person hat.
 *
 * Bisher fuehrt nur ein Weg hierher: eine Spalte entfernen (#60). Das ist der
 * einzige Vorgang, der Daten **aller** Beteiligten anfasst, auch der
 * unsichtbaren, und deshalb enger sitzt als der Rest der Einstellungen.
 *
 * **Ausdruecklich keine Admin-Ausnahme.** Ein Nextcloud-Administrator, der kein
 * Board-Mitglied ist, hat keinen Betrachterkontext; ihm einen Weg hinein zu
 * bauen waere genau die Hintertuer, die das Konzept ausschliesst. Wird ein
 * Notausgang gebraucht, dann als `occ`-Befehl — ein Serverzugang ist die
 * ehrlichere Grenze als ein Knopf.
 *
 * 403 wie die Schwesterausnahme: Wer bis hierher kommt, ist Mitglied und sieht
 * das Board.
 */
class NotOwnerException extends \RuntimeException {
}
