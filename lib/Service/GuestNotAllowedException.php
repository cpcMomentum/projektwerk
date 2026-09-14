<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Ein Gast-Konto darf diese Aktion nicht — hier: **kein eigenständiges Projekt
 * anlegen** (#280).
 *
 * **403, nicht 404.** Der Gast ist angemeldet und darf die App nutzen; es gibt
 * nichts zu verbergen, nur die Anlage zu verweigern. Anders als die
 * projektbezogenen Verwaltungs-Sperren ({@see NotManagerException}) hängt diese
 * an der Konto-Art, nicht an einer Mitgliedschaft.
 */
class GuestNotAllowedException extends \RuntimeException {
}
