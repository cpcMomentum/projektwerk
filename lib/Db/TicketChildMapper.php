<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Die gemeinsame Bauform aller Kinder eines Tickets: Kommentare,
 * Arbeitsschritte, Anhaenge, Mitarbeitende.
 *
 * **Kinder sind nicht adressierbar.** Es gibt hier keine Methode, die eine
 * einzelne `ticketId` aus einem Request entgegennimmt — die einzige Lesesignatur
 * nimmt eine **Menge** von Ticket-IDs, und die stammt ausschliesslich aus
 * {@see TicketMapper}, ist also bereits gefiltert. Ein Controller kann damit
 * gar nicht „die Kommentare zu Ticket 42" laden, sondern nur „die Kommentare zu
 * den Tickets, die dieser Betrachter sehen darf".
 *
 * Dass diese Regel als **abstrakte Basisklasse** und nicht als Konvention in
 * vier Dateien steht, ist der ganze Punkt: Eine Konvention bricht beim sechsten
 * Lesepfad. Eine Basisklasse ohne kontextfreie Lesemethode kann man nicht
 * versehentlich umgehen, nur absichtlich verlassen — und das faellt im Review
 * auf. `MapperArchitectureTest` haelt zusaetzlich fest, dass die vier
 * Kinder-Mapper keine eigenen Lesemethoden nachtragen.
 *
 * Kosten, ehrlich benannt: Das Ticket-Detail laedt seine Kinder ueber eine
 * Einermenge (`findForTickets([42])`). Das ist sperriger als ein direkter
 * Zugriff. Genau so ist es gemeint — die Reibung kommt vor dem Fehler statt
 * danach.
 *
 * @template T of Entity
 * @template-extends QBMapper<T>
 */
abstract class TicketChildMapper extends QBMapper {

	/**
	 * Obergrenze je `IN (...)`-Liste.
	 *
	 * MySQL und PostgreSQL begrenzen die Zahl gebundener Parameter (65535 bei
	 * PostgreSQL). Ein Board mit mehreren tausend sichtbaren Tickets ist
	 * unwahrscheinlich — aber der Fehler traete erst auf einer fremden
	 * Installation auf und waere dort nicht nachvollziehbar. Dieselbe
	 * Fehlerklasse wie die zu langen Tabellennamen, und dieselbe Antwort:
	 * vorher begrenzen statt hinterher suchen.
	 */
	// Protected, damit ein Kind-Mapper eine eigene Aggregatabfrage über
	// dieselbe Chunk-Grenze legen kann (CommentMapper::findNewestForTickets, #79).
	protected const CHUNK_SIZE = 1000;

	/**
	 * @param class-string<T> $entityClass
	 */
	public function __construct(IDBConnection $db, string $tableName, string $entityClass) {
		parent::__construct($db, $tableName, $entityClass);
	}

	/**
	 * Die einzige Lesesignatur.
	 *
	 * @param int[] $ticketIds IDs aus TicketMapper — also bereits gefiltert
	 * @return T[] nach Ticket gruppiert, darin nach {@see sortProperty()}
	 */
	public function findForTickets(array $ticketIds): array {
		$ids = $this->normalizeIds($ticketIds);
		if ($ids === []) {
			// Kein Sonderfall, sondern der Normalfall fuer einen Betrachter, der
			// nichts sehen darf: leere Ticket-Menge, leere Kindmenge — und keine
			// Abfrage. Ohne diese Abkuerzung wuerde `IN ()` je nach Datenbank
			// verschieden reagieren.
			return [];
		}

		$entities = [];
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->tableName)
				->where($qb->expr()->in(
					'ticket_id',
					$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY),
				));
			foreach ($this->findEntities($qb) as $entity) {
				$entities[] = $entity;
			}
		}

		// Sortiert wird in PHP, nicht in SQL. Ueber mehrere Bloecke hinweg
		// ordnet die Datenbank nur innerhalb eines Blocks — eine ORDER BY-Klausel
		// waere dann ein zweiter Ort, an dem die Reihenfolge stimmen muesste,
		// und der stimmte erst ab tausend Tickets nicht mehr.
		return $this->sortEntities($entities);
	}

	/**
	 * Anzahl je Ticket — fuer die Zaehler auf der Karte (§3.7).
	 *
	 * Bewusst hier und nicht als eigenstaendige Abfrage im Service: Ein
	 * `SELECT COUNT(*) ... GROUP BY ticket_id` ohne die gefilterte ID-Menge ist
	 * die naheliegende Optimierung, die das Leck baut — ein Zaehler, der
	 * mitzaehlt, was verborgen ist, verraet dessen Existenz (§5.8).
	 *
	 * @param int[] $ticketIds
	 * @return array<int, int> jede uebergebene Ticket-ID, Tickets ohne Kinder mit 0
	 */
	public function countForTickets(array $ticketIds): array {
		$ids = $this->normalizeIds($ticketIds);
		if ($ids === []) {
			return [];
		}

		// Vorbelegt mit 0, damit der Aufrufer nicht zwischen „keine Kinder" und
		// „nicht gefragt" unterscheiden muss — und keine Karte ohne Zahl bleibt.
		$counts = array_fill_keys($ids, 0);
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('ticket_id')
				->selectAlias($qb->func()->count('*'), 'child_count')
				->from($this->tableName)
				->where($qb->expr()->in(
					'ticket_id',
					$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY),
				))
				->groupBy('ticket_id');

			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$counts[(int)$row['ticket_id']] = (int)$row['child_count'];
			}
			$result->closeCursor();
		}

		return $counts;
	}

	/**
	 * Die Eigenschaft, nach der innerhalb eines Tickets sortiert wird.
	 *
	 * @return string Name der Entity-Eigenschaft, nicht der Datenbankspalte
	 */
	abstract protected function sortProperty(): string;

	/**
	 * Die einzige Stelle, die ueber die Reihenfolge entscheidet.
	 *
	 * Protected und nicht private, damit sie ohne Datenbank pruefbar ist —
	 * die Reihenfolge ueber Blockgrenzen hinweg ist genau der Teil, den ein
	 * Test mit einer Handvoll Zeilen nicht faende.
	 *
	 * @param T[] $entities
	 * @return T[]
	 */
	protected function sortEntities(array $entities): array {
		$getter = 'get' . ucfirst($this->sortProperty());

		usort($entities, static fn (Entity $a, Entity $b): int
			=> [$a->getTicketId(), $a->$getter(), $a->getId()]
			<=> [$b->getTicketId(), $b->$getter(), $b->getId()]);

		return $entities;
	}

	/**
	 * @param int[] $ticketIds
	 * @return int[] eindeutig, lueckenlos indiziert
	 */
	protected function normalizeIds(array $ticketIds): array {
		return array_values(array_unique(array_map('intval', $ticketIds)));
	}
}
