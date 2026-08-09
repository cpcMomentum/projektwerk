<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Der einzige Weg zu einem Ticket.
 *
 * **Es gibt hier kein `findAll()` und kein `find(int $id)`.** Jede Lesemethode
 * beginnt mit einem {@see ViewerContext} oder einer Benutzerkennung, und jede
 * fuehrt ueber {@see TicketScope}. Damit wird „Sichtbarkeitsfilter vergessen"
 * zu einem **Typfehler statt einem Review-Versaeumnis** — man kann die Regel
 * nicht umgehen, ohne eine neue Methode zu schreiben, und das faellt auf.
 *
 * Zwei Waechter in `tests/Unit/Access/ArchitectureTest.php` halten das fest:
 * Diese Datei und `Access/TicketScope.php` sind ausserhalb der Migration die
 * einzigen Stellen, an denen `pwerk_tickets` stehen darf, und eine
 * kontextfreie Lesemethode laesst den zweiten Test fallen.
 *
 * @template-extends QBMapper<Ticket>
 */
class TicketMapper extends QBMapper {

	/** Alias des Tickets in allen Abfragen dieser Klasse. */
	private const T = 't';

	public function __construct(
		IDBConnection $db,
		private TicketScope $scope,
	) {
		parent::__construct($db, 'pwerk_tickets', Ticket::class);
	}

	/**
	 * Die sichtbaren Tickets eines Boards, in Board-Reihenfolge.
	 *
	 * `$columnId` schraenkt auf eine Spalte ein — es ist **keine** zweite
	 * Berechtigungsfrage, die Sichtbarkeit haengt allein am Kontext.
	 *
	 * @return Ticket[]
	 */
	public function findVisibleInBoard(ViewerContext $viewer, ?int $columnId = null): array {
		$qb = $this->scopedQuery($viewer->userId, $viewer->boardId);
		$qb->select(self::T . '.*');

		if ($columnId !== null) {
			$qb->andWhere($qb->expr()->eq(
				self::T . '.column_id',
				$qb->createNamedParameter($columnId, IQueryBuilder::PARAM_INT),
			));
		}

		$qb->orderBy(self::T . '.position', 'ASC')
			->addOrderBy(self::T . '.id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Ein einzelnes Ticket — wenn dieser Betrachter es sehen darf.
	 *
	 * **Ein verborgenes und ein nicht existierendes Ticket erzeugen dieselbe
	 * Ausnahme.** Das ist Absicht: Die Fehlerform darf nicht verraten, was die
	 * Abfrage nicht verraten darf. Aus demselben Grund gibt es keine Variante,
	 * die zwischen „gibt es nicht" und „darfst du nicht" unterscheidet.
	 *
	 * @throws DoesNotExistException
	 */
	public function findVisible(ViewerContext $viewer, int $ticketId): Ticket {
		$qb = $this->scopedQuery($viewer->userId, $viewer->boardId);
		$qb->select(self::T . '.*')
			->andWhere($qb->expr()->eq(
				self::T . '.id',
				$qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
			));

		return $this->findEntity($qb);
	}

	/**
	 * Ein Ticket ueber alle Boards hinweg — fuer den Deep-Link.
	 *
	 * Der Deep-Link kennt **nur die Ticketnummer**, kein Board. Die naive
	 * Loesung waere, zuerst das Board zu ermitteln und dann `findVisible()` zu
	 * rufen — das waere ein zweiter Lesepfad auf `pwerk_tickets`, der die
	 * Sichtbarkeitsregel gerade nicht durchlaeuft. Er verriete zwar nichts
	 * nach aussen, aber er waere die Stelle, an der jemand spaeter „nur schnell"
	 * etwas anderes damit beantwortet.
	 *
	 * Stattdessen dieselbe Abfrage wie ueberall, nur ohne Board-Einschraenkung:
	 * `TicketScope` verbindet auf `pwerk_members` und bildet die Rolle je Board.
	 * Wer kein Mitglied ist, faellt aus dem INNER JOIN — Nichtmitgliedschaft und
	 * verborgenes Ticket ergeben damit denselben `DoesNotExistException`, und
	 * genau das braucht der Deep-Link: eine Antwort, die nicht verraet, welcher
	 * der beiden Faelle vorliegt.
	 *
	 * @throws DoesNotExistException Ticket unbekannt, nicht sichtbar oder Board fremd
	 */
	public function findVisibleAnywhere(string $userId, int $ticketId): Ticket {
		$qb = $this->scopedQuery($userId, null);
		$qb->select(self::T . '.*')
			->andWhere($qb->expr()->eq(
				self::T . '.id',
				$qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
			));

		return $this->findEntity($qb);
	}

	/**
	 * „Meine Tickets" ueber alle Boards hinweg — verantwortlich **oder**
	 * mitarbeitend (§ Meine Aufgaben).
	 *
	 * Eine einzige Abfrage, keine Schleife ueber Board-Kontexte: `TicketScope`
	 * verbindet auf `pwerk_members` und bildet die Bedingung damit **je Board
	 * mit der dort geltenden Rolle**. Dieselbe Person kann in einem Projekt
	 * intern und in einem anderen extern sein — das faellt hier nicht als
	 * Sonderfall an, sondern ergibt sich aus dem Verbund.
	 *
	 * Der zweite Verbund (`pwerk_ticket_users`) ist ein LEFT JOIN mit der
	 * Benutzerkennung **in der ON-Bedingung**. Das liefert hoechstens eine Zeile
	 * je Ticket (der Unique-Index sorgt dafuer) und braucht damit weder
	 * `DISTINCT` noch eine Unterabfrage.
	 *
	 * @return Ticket[]
	 */
	public function findVisibleAcrossBoards(string $userId, TaskFilter $filter): array {
		$qb = $this->scopedQuery($userId, null);
		$uid = $qb->createNamedParameter($userId);

		$qb->select(self::T . '.*')
			->leftJoin(
				self::T,
				'pwerk_ticket_users',
				'tu',
				$qb->expr()->andX(
					$qb->expr()->eq('tu.ticket_id', self::T . '.id'),
					$qb->expr()->eq('tu.user_id', $uid),
				),
			)
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq(self::T . '.responsible_user_id', $uid),
				$qb->expr()->isNotNull('tu.id'),
			));

		if (!$filter->includeClosed) {
			$qb->andWhere($qb->expr()->isNull(self::T . '.closed_at'));
		}

		// Nach Alter, aeltestes zuerst. Die Feinsortierung der Ansicht
		// (Ueberfaelliges oben) entsteht in Phase 4 aus Ticket und Schritten
		// zusammen und gehoert deshalb nicht in den Mapper.
		$qb->orderBy(self::T . '.created_at', 'ASC')
			->addOrderBy(self::T . '.id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Wie viele Tickets dieser Betrachter im Board sieht.
	 *
	 * Zaehlt **dieselbe** Abfrage, die auch die Liste liefert — nicht eine
	 * eigene. Ein Zaehler mit eigener Bedingung waere der zweite Ort, an dem
	 * die Regel stimmen muesste, und §5.8 verlangt ausdruecklich, dass auch
	 * Zaehler nichts Verborgenes verraten.
	 */
	public function countVisibleInBoard(ViewerContext $viewer): int {
		$qb = $this->scopedQuery($viewer->userId, $viewer->boardId);
		$qb->select($qb->func()->count(self::T . '.id'));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Die groesste Position einer Spalte — **ungefiltert**.
	 *
	 * Bewusst an `TicketScope` vorbei, und das ist die einzige Lesemethode
	 * dieser Klasse, fuer die das gilt. Der Grund steht in §3.8: Positionen
	 * werden gegen die **ungefilterte** Liste aufgeloest. Naehme man die
	 * gefilterte, landete ein neues Ticket je nach Betrachter an einer anderen
	 * Stelle — zwei Personen saehen dieselbe Spalte in verschiedener
	 * Reihenfolge, und keine von beiden koennte das erklaeren.
	 *
	 * **Sie verraet trotzdem nichts.** Zurueck kommt eine einzelne Zahl, die
	 * nie serialisiert wird (`Ticket::jsonSerialize()` laesst `position`
	 * bewusst weg) und nur dazu dient, das eigene Ticket hinten anzuhaengen.
	 * Die Leak-Matrix haelt fest, dass der Wert fuer **alle** Betrachter gleich
	 * ist — waere er es nicht, waere genau das der Beweis, dass er etwas ueber
	 * die gefilterte Menge aussagt.
	 *
	 * Der `ViewerContext` steht trotzdem vorn: Er bezeugt die Mitgliedschaft,
	 * und die Abfrage bleibt auf dessen Board beschraenkt.
	 *
	 * @return int|null null, wenn die Spalte leer ist
	 */
	public function findLastPositionInColumn(ViewerContext $viewer, int $columnId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('position'))
			->from($this->tableName)
			->where($qb->expr()->eq(
				'board_id',
				$qb->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->eq(
				'column_id',
				$qb->createNamedParameter($columnId, IQueryBuilder::PARAM_INT),
			));

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return $max === null || $max === false ? null : (int)$max;
	}

	/**
	 * Nummeriert eine Spalte neu, sobald die Luecken aufgebraucht sind (§3.8).
	 *
	 * Liest und schreibt **vollstaendig innerhalb dieser Methode**. Es gibt
	 * bewusst keine oeffentliche Methode, die die ungefilterte Reihenfolge einer
	 * Spalte herausgibt: Diese Liste enthaelt die IDs verborgener Tickets, und
	 * sobald sie eine Methode verlaesst, ist sie irgendwann in einer Antwort.
	 * Die Rechnung selbst steht in {@see PositionService::rebalance()} und ist
	 * dort ohne Datenbank geprueft.
	 *
	 * @param callable(int[]): array<int, int> $calculate Ticket-IDs in
	 *        Sollreihenfolge => neue Positionen
	 */
	public function rebalanceColumn(ViewerContext $viewer, int $columnId, callable $calculate): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->tableName)
			->where($qb->expr()->eq(
				'board_id',
				$qb->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->eq(
				'column_id',
				$qb->createNamedParameter($columnId, IQueryBuilder::PARAM_INT),
			))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchFirstColumn());
		$result->closeCursor();

		foreach ($calculate($ids) as $ticketId => $position) {
			$update = $this->db->getQueryBuilder();
			$update->update($this->tableName)
				->set('position', $update->createNamedParameter($position, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq(
					'id',
					$update->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
				));
			$update->executeStatement();
		}
	}

	/**
	 * Der gemeinsame Anfang jeder Abfrage dieser Klasse: `FROM pwerk_tickets`
	 * plus die Sichtbarkeitsregel. Es gibt hier keinen Weg an
	 * {@see TicketScope::apply()} vorbei, weil es keinen zweiten Einstieg gibt.
	 *
	 * @param int|null $boardId null heisst boarduebergreifend
	 */
	private function scopedQuery(string $userId, ?int $boardId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->from($this->tableName, self::T);
		$this->scope->apply($qb, self::T, $userId, $boardId);

		return $qb;
	}
}
