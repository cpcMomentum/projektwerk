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
	 * @return array<int, array{at: string, author: string}> ticketId => jüngster Kommentar (ATOM-Zeit und Autor, #175).
	 */
	public function findNewestForTickets(array $ticketIds): array {
		$ids = $this->normalizeIds($ticketIds);
		if ($ids === []) {
			return [];
		}

		$newest = [];
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			// **Nicht mehr nur `MAX(created_at)`, sondern auch der Autor** (#175):
			// Der „geändert seit deinem Blick"-Punkt soll nicht am eigenen
			// Kommentar leuchten, und dafür muss die auslesende Person wissen,
			// von wem der jüngste Kommentar stammt. Statt eines Aggregats deshalb
			// nach Vorgang und Zeit absteigend sortiert und je Vorgang die erste
			// Zeile genommen; `id` als Tiebreak für zwei Kommentare derselben
			// Sekunde. Bei der Größenordnung dieser App (ein Team, ein Board)
			// wiegt die Sortierung nichts gegen die gewonnene Auskunft.
			$qb->select('ticket_id', 'created_at', 'author_user_id')
				->from($this->tableName)
				->where($qb->expr()->in(
					'ticket_id',
					$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY),
				))
				->orderBy('ticket_id')
				->addOrderBy('created_at', 'DESC')
				->addOrderBy('id', 'DESC');

			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$id = (int)$row['ticket_id'];
				// Die erste Zeile je Vorgang ist die jüngste — spätere übergehen.
				if (isset($newest[$id])) {
					continue;
				}
				$newest[$id] = [
					'at' => (new \DateTime((string)$row['created_at']))->format(\DateTime::ATOM),
					'author' => (string)$row['author_user_id'],
				];
			}
			$result->closeCursor();
		}

		return $newest;
	}
}
