<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\IDBConnection;

/**
 * Kommentare — ausschliesslich ueber die bereits gefilterte Ticket-Menge.
 *
 * Die einzige Lesesignatur erbt diese Klasse von {@see TicketChildMapper}; hier
 * steht nur, welche Tabelle gemeint ist und wonach innerhalb eines Tickets
 * sortiert wird.
 *
 * @template-extends TicketChildMapper<Comment>
 */
class CommentMapper extends TicketChildMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_comments', Comment::class);
	}

	#[\Override]
	protected function sortProperty(): string {
		// Aelteste zuerst — ein Gespraechsverlauf, keine Liste.
		return 'createdAt';
	}
}
