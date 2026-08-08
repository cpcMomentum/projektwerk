<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\IDBConnection;

/**
 * Arbeitsschritte — ausschliesslich ueber die bereits gefilterte Ticket-Menge.
 *
 * `findForTickets()` liefert zugleich die Grundlage fuer den Kartenzaehler
 * („3/5") und fuer den Zustand „wartet auf Kunde" (§3.7): Beides entsteht
 * in-memory aus **diesen** Zeilen, nicht aus einer eigenen Abfrage. Eine
 * gespeicherte Zaehlerspalte waere ein zweiter Ort, an dem die Zahl stimmen
 * muesste — und der erste, der eine verborgene Zeile mitzaehlt.
 *
 * @template-extends TicketChildMapper<Step>
 */
class StepMapper extends TicketChildMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_steps', Step::class);
	}

	#[\Override]
	protected function sortProperty(): string {
		// Schritte haben eine vom Menschen gesetzte Reihenfolge.
		return 'position';
	}
}
