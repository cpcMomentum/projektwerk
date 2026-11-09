<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\AppInfo\Application;
use OCP\IURLGenerator;

/**
 * Der fragmentfreie Direktlink auf einen Vorgang.
 */
class TicketLink {

	public function __construct(
		private IURLGenerator $urls,
	) {
	}

	/** Ohne geladene Routen (etwa auf der Kommandozeile) liefert der Router die blanke Basisadresse; dann der feste Pfad. */
	public function forTicket(int $ticketId): string {
		$link = $this->urls->linkToRouteAbsolute(Application::APP_ID . '.deepLink.ticket', ['ticketId' => $ticketId]);

		return str_contains($link, '/t/' . $ticketId)
			? $link
			: $this->urls->getAbsoluteURL('/index.php/apps/' . Application::APP_ID . '/t/' . $ticketId);
	}
}
