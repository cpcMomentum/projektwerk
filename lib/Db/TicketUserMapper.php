<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\IDBConnection;

/**
 * Mitarbeitende Personen am Ticket — ausschliesslich ueber die bereits
 * gefilterte Ticket-Menge.
 *
 * Auch die Frage „an welchen Tickets arbeite ich mit?" laeuft **nicht** ueber
 * diese Klasse, sondern ueber
 * {@see TicketMapper::findVisibleAcrossBoards()}: Eine Suche nach der eigenen
 * Kennung in dieser Tabelle wuerde Ticket-IDs liefern, die die
 * Sichtbarkeitsregel nie gesehen hat.
 *
 * @template-extends TicketChildMapper<TicketUser>
 */
class TicketUserMapper extends TicketChildMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_ticket_users', TicketUser::class);
	}

	#[\Override]
	protected function sortProperty(): string {
		return 'addedAt';
	}
}
