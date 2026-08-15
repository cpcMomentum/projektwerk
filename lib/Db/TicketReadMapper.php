<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Der Lesestand je Person und Vorgang (#79).
 *
 * **Dieser Mapper kennt keinen Betrachter — und das ist die Erwartung**, wie
 * beim {@see NotifyPrefMapper}: Der erste Parameter ist eine Benutzerkennung,
 * und genau die ist die Grenze. Eine Person liest nur ihre **eigenen** Stände;
 * es gibt nichts Fremdes zu sehen, weil ein Lesestand keine Projektdaten trägt,
 * nur einen Zeitpunkt.
 *
 * Die Ticket-IDs, nach denen gefragt wird, kommen aus der bereits gefilterten
 * sichtbaren Menge (wie bei den Kinder-Mappern). Hier wird nichts eigenständig
 * an der Sichtbarkeit vorbei geladen — ein Stand zu einem Vorgang, der der
 * Person nicht gehört, kann gar nicht entstehen: Er wird nur beim Öffnen
 * gesetzt, und öffnen kann sie nur, was sie sieht.
 *
 * @template-extends QBMapper<TicketRead>
 */
class TicketReadMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_reads', TicketRead::class);
	}

	/**
	 * Meine Lesestände zu diesen Vorgängen — `ticketId => seen_at`.
	 *
	 * Nach `user_id` gefiltert (die eigenen), `ticket_id` in der übergebenen
	 * Menge. Leere Menge, leere Antwort — ohne die Abfrage, `IN ()` ist auf
	 * manchen Datenbanken ein Fehler.
	 *
	 * @param string $userId Kennung der Person.
	 * @param int[] $ticketIds Die bereits sichtbaren Vorgänge.
	 * @return array<int, string> ticketId => seen_at als ATOM-Zeitstempel.
	 */
	public function findSeenForTickets(string $userId, array $ticketIds): array {
		if ($ticketIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id', 'seen_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $qb->executeQuery();
		$seen = [];
		while ($row = $result->fetch()) {
			$seen[(int)$row['ticket_id']] = (new \DateTime((string)$row['seen_at']))->format(\DateTime::ATOM);
		}
		$result->closeCursor();

		return $seen;
	}

	/**
	 * „Gelesen" setzen — je Person und Vorgang genau eine Zeile.
	 *
	 * Erst suchen, dann aktualisieren oder anlegen: Ein portabler Upsert über
	 * QBMapper, der ohne datenbankspezifisches `ON CONFLICT` auskommt. Der
	 * eindeutige Index fängt das seltene Wettrennen ab.
	 *
	 * @param string $userId Kennung der Person.
	 * @param int $ticketId Kennung des Vorgangs.
	 */
	public function markSeen(string $userId, int $ticketId): void {
		try {
			$read = $this->findOneForUserAndTicket($userId, $ticketId);
			$read->setSeenAt(new \DateTime());
			$this->update($read);
		} catch (DoesNotExistException) {
			$read = new TicketRead();
			$read->setUserId($userId);
			$read->setTicketId($ticketId);
			$read->setSeenAt(new \DateTime());
			$this->insert($read);
		}
	}

	/**
	 * Alle Lesestände zu einem Vorgang wegräumen (#79, Aufräumen).
	 *
	 * Beim Löschen eines Vorgangs: Der Stand ohne seinen Vorgang ist eine
	 * Karteileiche, die niemand mehr liest. Kein Lesepfad — ein Schreibvorgang.
	 *
	 * @param int $ticketId Kennung des Vorgangs.
	 */
	public function deleteForTicket(int $ticketId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @throws DoesNotExistException kein Stand für diese Person und diesen Vorgang
	 */
	private function findOneForUserAndTicket(string $userId, int $ticketId): TicketRead {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}
}
