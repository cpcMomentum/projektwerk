<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Der Betrachter darf dieses Board nicht verwalten.
 *
 * **403 und nicht 404**, wie schon bei {@see NotOwningSideException}: Wer bis
 * hierher kommt, ist Mitglied und sieht das Board. Zu verbergen gibt es nichts
 * mehr — nur zu erklaeren, warum die Einstellung ihm verschlossen ist.
 *
 * Das Verwaltungsrecht traegt laut §8 nur ein **internes** Mitglied. Die
 * Fabrikmethode von {@see \OCA\Projektwerk\Access\ViewerContext} entschaerft
 * ein versehentlich gesetztes Flag an einem externen Mitglied bereits beim
 * Bauen des Kontexts; diese Ausnahme ist die zweite, sprechende Sperre davor.
 *
 * (Der Methodenname steht hier absichtlich nicht ausgeschrieben: Der
 * Architekturtest sucht ihn als Text und soll das bleiben.)
 */
class NotManagerException extends \RuntimeException {
}
