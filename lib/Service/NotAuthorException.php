<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Der Kommentar gehoert jemand anderem.
 *
 * Aendern und Loeschen kann **nur die verfassende Person** — auch der
 * Board-Eigentuemer nicht, auch kein Verwalter, auch kein Administrator. Das
 * ist dieselbe Hausregel wie ueberall sonst: keine Admin-Ausnahme. Ein
 * Gespraechsverlauf, in den ein Dritter hineinschreiben oder aus dem er
 * herausloeschen kann, ist keiner.
 *
 * Der Preis ist benannt: Ein Versehen bleibt stehen, wenn die verfassende
 * Person das Board verlassen hat. Wird ein Notausgang gebraucht, dann als
 * `occ`-Befehl — ein Serverzugang ist die ehrlichere Grenze als ein Knopf.
 *
 * **403 und nicht 404**, wie bei {@see NotOwningSideException}: Wer bis hierher
 * kommt, sieht den Kommentar, er steht vor ihm. Zu verbergen gibt es nichts
 * mehr — nur zu erklaeren, warum er ihn nicht anfassen darf.
 */
class NotAuthorException extends \RuntimeException {
}
