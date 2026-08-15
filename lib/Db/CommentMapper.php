<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
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

	/**
	 * Der jüngste Kommentar je Vorgang — `ticketId => created_at` (#79).
	 *
	 * Für „geändert seit deinem Blick": Ein neuer Kommentar ist die häufigste
	 * Bewegung an einem Vorgang (#98), und die Karte soll sie sehen. Wie
	 * {@see countForTickets()} ausschliesslich über die bereits gefilterte
	 * Ticket-Menge, gechunkt, mit derselben Zusage — ein Vorgang ohne Kommentar
	 * erscheint gar nicht (kein Wert heisst „keine Bewegung von hier").
	 *
	 * Ein neuer Lesepfad, und deshalb in Registry und Leak-Matrix eingetragen:
	 * `testNewestCommentFollowsTheFilteredTicketSet`.
	 *
	 * @param int[] $ticketIds Die bereits sichtbaren Vorgänge.
	 * @return array<int, string> ticketId => created_at als ATOM-Zeitstempel.
	 */
	public function findNewestForTickets(array $ticketIds): array {
		$ids = $this->normalizeIds($ticketIds);
		if ($ids === []) {
			return [];
		}

		$newest = [];
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('ticket_id')
				->selectAlias($qb->func()->max('created_at'), 'newest')
				->from($this->tableName)
				->where($qb->expr()->in(
					'ticket_id',
					$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY),
				))
				->groupBy('ticket_id');

			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$newest[(int)$row['ticket_id']] = (new \DateTime((string)$row['newest']))->format(\DateTime::ATOM);
			}
			$result->closeCursor();
		}

		return $newest;
	}
}
