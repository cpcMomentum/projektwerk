<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
// Nur wegen der Schrittweite. Der Mapper rechnet sonst nichts — aber 65536 ein
// zweites Mal hinzuschreiben waere genau die Doppelung, gegen die die Konstante
// gemacht ist.
use OCA\Projektwerk\Service\PositionService;
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
	 * Ein **geloeschtes** Ticket zum Wiederherstellen finden (#167).
	 *
	 * **Bewusst am Sichtbarkeits-Scope vorbei**, denn der nimmt Geloeschtes aus
	 * jeder Abfrage — genau das, was hier gefunden werden soll. Deshalb steht
	 * die Methode im TicketMapper (der einzige erlaubte Ort fuer `pwerk_tickets`)
	 * und nicht in einem zweiten Lesepfad.
	 *
	 * Sicher trotz fehlendem Scope: `withViewer` hat die Board-Mitgliedschaft
	 * bereits geprueft, hier wird zusaetzlich auf **das Board des Betrachters**
	 * und auf **die eigene Rolle** (`creator_role`) eingegrenzt — man kann nur
	 * zurueckholen, was die eigene Seite geloescht hat. Ein fremdes Ticket, ein
	 * fremdes Board und eine unbekannte Kennung ergeben denselben
	 * `DoesNotExistException`; die Fehlerform verraet nichts (wie `findVisible`).
	 *
	 * @throws DoesNotExistException  unbekannt, fremdes Board oder andere Seite
	 */
	public function findForRestore(ViewerContext $viewer, int $ticketId): Ticket {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::T . '.*')
			->from($this->tableName, self::T)
			->where($qb->expr()->eq(
				self::T . '.id',
				$qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->eq(
				self::T . '.board_id',
				$qb->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->eq(
				self::T . '.creator_role',
				$qb->createNamedParameter($viewer->role),
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
	 * **Alles Sichtbare ueber alle Boards** — die Menge fuer den Ueberblick (#76).
	 *
	 * **Warum das nicht {@see findVisibleAcrossBoards()} sein kann.** Jene
	 * Methode engt auf „verantwortlich **oder** mitarbeitend" ein und
	 * beantwortet damit „Meine Vorgaenge". Der Ueberblick fragt etwas anderes:
	 * **wo hakt es**, ueber alle Projekte — auch dort, wo gerade nichts bei mir
	 * liegt. Ein Vorgang, der bei der Kundenseite liegt und mir nicht gehoert,
	 * ist genau der Fall, den die Seite zeigen soll, und faellt bei jener
	 * Methode heraus.
	 *
	 * **Es ist trotzdem kein zweiter Zugang zu den Daten.** Der Einstieg ist
	 * {@see scopedQuery()} wie ueberall, und diese Methode fuegt **nichts**
	 * hinzu ausser der Sortierung und dem Offen-Filter — sie laesst nur die
	 * Einschraenkung weg. Wer hier eine Bedingung ergaenzt, die es anderswo
	 * nicht gibt, hat die Sichtbarkeitsregel ein zweites Mal geschrieben.
	 *
	 * @return Ticket[]
	 */
	public function findVisibleAcrossBoardsAll(string $userId, TaskFilter $filter): array {
		$qb = $this->scopedQuery($userId, null);
		$qb->select(self::T . '.*');

		if (!$filter->includeClosed) {
			$qb->andWhere($qb->expr()->isNull(self::T . '.closed_at'));
		}

		// Nach Alter, aeltestes zuerst — dieselbe Grundordnung wie bei
		// „Meine Vorgaenge". Wonach der Ueberblick am Ende sortiert (Standdauer),
		// entsteht aus Ticket und Wartezustand zusammen und gehoert deshalb
		// nicht in den Mapper.
		$qb->orderBy(self::T . '.created_at', 'ASC')
			->addOrderBy(self::T . '.id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Die Vorgaenge, an denen mir ein **offener Arbeitsschritt** zugewiesen ist
	 * — ueber alle Boards hinweg (§ Meine Aufgaben, Abschnitt „Meine
	 * Arbeitsschritte").
	 *
	 * **Warum das nicht {@see findVisibleAcrossBoards()} sein kann.** Jene
	 * Methode schraenkt auf „verantwortlich **oder** mitarbeitend" ein und
	 * beantwortet damit „Meine Tickets". Ein Arbeitsschritt kann mir aber an
	 * einem Vorgang zugewiesen sein, an dem ich weder das eine noch das andere
	 * bin — genau der Normalfall, wenn jemand mir eine Zuarbeit gibt. Die
	 * beiden Abschnitte der Ansicht sind deshalb zwei verschiedene Mengen und
	 * brauchen zwei Abfragen.
	 *
	 * **Es bleibt trotzdem dieselbe eine Regel.** Der Einstieg ist
	 * {@see scopedQuery()} wie ueberall; die Schritte kommen nur als
	 * Einschraenkung dazu, nicht als zweiter Zugang. Ein Lesepfad im
	 * `StepMapper` waere die Alternative gewesen und ist verworfen: Er muesste
	 * `pwerk_tickets` selbst anfassen, und das ist ausserhalb dieser Klasse und
	 * `TicketScope` verboten — der Architekturtest laesst es fallen.
	 *
	 * **Unterabfrage statt Verbund.** Ein JOIN auf `pwerk_steps` lieferte ein
	 * Ticket mehrfach, sobald mir daran zwei Schritte gehoeren; anders als bei
	 * `pwerk_ticket_users` gibt es hier keinen eindeutigen Index, der das
	 * verhindert. Die Parameter entstehen am **aeusseren** Builder — nur dort
	 * gebunden zu werden ist der Grund, warum Nextclouds eigener Code es genau
	 * so macht (`contactsinteraction/lib/Db/CardSearchDao.php`).
	 *
	 * Zurueck kommen **Tickets, keine Schritte**: Die Schritte laedt der
	 * Aufrufer ueber `findForTickets()` aus dieser gefilterten Menge. Kinder
	 * werden nie eigenstaendig abgefragt.
	 *
	 * @return Ticket[]
	 */
	public function findVisibleWithMyOpenSteps(string $userId, TaskFilter $filter): array {
		$qb = $this->scopedQuery($userId, null);

		$mine = $this->db->getQueryBuilder();
		$mine->select('ticket_id')
			->from('pwerk_steps')
			->where($mine->expr()->eq('assigned_user_id', $qb->createNamedParameter($userId)))
			->andWhere($mine->expr()->eq('done', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		// Ohne `PARAM_INT_ARRAY`: `ExpressionBuilder::in()` reicht den Typ nicht
		// weiter (`return $this->expressionBuilder->in($x, $y);` — das dritte
		// Argument faellt weg). Nextclouds eigener Code gibt ihn trotzdem mit;
		// ihn hier mitzuschleppen hiesse, eine Wirkung zu behaupten, die es
		// nicht gibt. Gebunden werden die Parameter ohnehin am aeusseren
		// Builder, nicht ueber diesen Weg.
		$qb->select(self::T . '.*')
			->andWhere($qb->expr()->in(
				self::T . '.id',
				$qb->createFunction($mine->getSQL()),
			));

		if (!$filter->includeClosed) {
			// §5.3: Geschlossene Vorgaenge verschwinden aus „Meine Aufgaben".
			// Der offene Schritt daran ueberlebt das Schliessen (E8) — er wird
			// nur nicht mehr vorgehalten.
			$qb->andWhere($qb->expr()->isNull(self::T . '.closed_at'));
		}

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
	 * Abgeschlossene Vorgaenge je Board, nach Ausgang gezaehlt — fuer den Status
	 * „Erledigt" und den Fortschritt im Dashboard (#226).
	 *
	 * Sichtbarkeits-gefiltert ueber {@see scopedQuery()} wie jede Lesemethode:
	 * auch dieser Zaehler zaehlt nur, was der Betrachter sehen darf, und verraet
	 * damit nichts Verborgenes (§5.8). Ein abgeschlossener Vorgang **ohne**
	 * ausdruecklichen Ausgang zaehlt als erledigt — geschlossen und nicht
	 * verworfen ist erledigt.
	 *
	 * **Auf die uebergebenen Boards eingeschraenkt**, und zwar aus demselben
	 * Grund wie die offene Menge und `firstColumn` im Ueberblick: Der Einstieg
	 * zeigt nur **aktive** Projekte. Ohne diese Grenze zaehlte die Methode auch
	 * erledigte Vorgaenge archivierter Projekte, und `closedCounts` traege
	 * Board-Kennungen, die in `boards`/`firstColumn` fehlen — die drei Aggregate
	 * liefen auseinander.
	 *
	 * @param string $userId Der Betrachter.
	 * @param int[] $boardIds Die aktiven Boards, auf die gezaehlt wird.
	 * @return array<int, array{done: int, discarded: int}> Board-Kennung => Zaehler
	 */
	public function countClosedByBoard(string $userId, array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->scopedQuery($userId, null);
		$qb->select(self::T . '.board_id', self::T . '.closed_outcome')
			->selectAlias($qb->func()->count(self::T . '.id'), 'cnt')
			->andWhere($qb->expr()->isNotNull(self::T . '.closed_at'))
			->andWhere($qb->expr()->in(
				self::T . '.board_id',
				$qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->groupBy(self::T . '.board_id', self::T . '.closed_outcome');

		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$boardId = (int)$row['board_id'];
			if (!isset($counts[$boardId])) {
				$counts[$boardId] = ['done' => 0, 'discarded' => 0];
			}
			$key = $row['closed_outcome'] === Ticket::OUTCOME_DISCARDED ? 'discarded' : 'done';
			$counts[$boardId][$key] += (int)$row['cnt'];
		}
		$result->closeCursor();

		return $counts;
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
	 * Schiebt **alle** Tickets einer Spalte in eine andere — ungefiltert.
	 *
	 * Der Weg zum Entfernen einer Spalte (#60). Geloescht wird dabei nichts:
	 * Eine Spalte kann Vorgaenge enthalten, die der Loeschende **nicht sehen
	 * darf**, und beide naheliegenden Auswege enden an derselben Wand. Ein
	 * Dialog „12 Vorgaenge werden geloescht" verriete deren Existenz; eine Zahl,
	 * die nur die sichtbaren nennt, waere schlimmer, weil dann ungefragt mehr
	 * verschwaende, als der Dialog sagt. Also wird verschoben — das verraet
	 * nichts, weil der Vorgang nicht angezeigt, nur mitbewegt wird, und niemand
	 * verliert etwas, das er nicht sehen konnte.
	 *
	 * **Weich geloeschte Tickets wandern mit.** Sie sind aus jeder Abfrage
	 * genommen, aber sie existieren; blieben sie zurueck, zeigte ein per
	 * `occ projektwerk:ticket:restore` zurueckgeholter Vorgang auf eine Spalte,
	 * die es nicht mehr gibt.
	 *
	 * **Liest und schreibt vollstaendig innerhalb dieser Methode**, wie
	 * {@see rebalanceColumn()}: Die ungefilterte Reihenfolge einer Spalte
	 * enthaelt die Kennungen verborgener Vorgaenge, und sobald so eine Liste
	 * eine Methode verlaesst, steht sie irgendwann in einer Antwort.
	 *
	 * **Je Zeile eine Anweisung, nicht ein Versatz in SQL.** Der naheliegende
	 * Einzeiler — `SET position = position + :versatz` — scheitert an
	 * Nextclouds Query Builder: Der quotiert das zweite Argument von `set()`
	 * als Spaltennamen, und PostgreSQL sucht dann nach einer Spalte namens
	 * „position + :dcValue2". Der Ausweg waere `createFunction()`, also roher
	 * SQL-Text mit `position` unquotiert — in PostgreSQL ein Schluesselwort.
	 * Eine Schleife ueber eine Kanban-Spalte ist der billigere Preis.
	 *
	 * Die Vorgaenge werden dabei **neu durchnummeriert** statt mit ihren
	 * Abstaenden uebernommen: Sie haengen hinten an, ihre Reihenfolge bleibt,
	 * und die Luecken sind danach wieder voll (§3.8).
	 *
	 * Der Rueckgabewert ist bewusst `void`. Eine Anzahl waere eine Auskunft
	 * ueber die ungefilterte Menge und damit genau das, was die Rueckfrage in
	 * der Oberflaeche nicht nennen darf.
	 */
	public function moveColumnContents(ViewerContext $viewer, int $fromColumnId, int $toColumnId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->tableName)
			->where($qb->expr()->eq(
				'board_id',
				$qb->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->eq(
				'column_id',
				$qb->createNamedParameter($fromColumnId, IQueryBuilder::PARAM_INT),
			))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchFirstColumn());
		$result->closeCursor();

		// Hinter das letzte Ticket der Zielspalte. Leer heisst 0, damit die
		// erste Position dort STEP wird — dieselbe Vergabe wie beim Anlegen.
		$next = ($this->findLastPositionInColumn($viewer, $toColumnId) ?? 0) + PositionService::STEP;

		foreach ($ids as $ticketId) {
			$update = $this->db->getQueryBuilder();
			$update->update($this->tableName)
				->set('column_id', $update->createNamedParameter($toColumnId, IQueryBuilder::PARAM_INT))
				->set('position', $update->createNamedParameter($next, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq(
					'id',
					$update->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
				));
			$update->executeStatement();

			$next += PositionService::STEP;
		}

		// **Nachzuegler einsammeln.** Legt jemand einen Vorgang in genau diese
		// Spalte, waehrend sie entfernt wird, steht er nicht in der Liste oben —
		// und bliebe nach dem Wegfall der Spalte an einer Spalte haengen, die es
		// nicht mehr gibt. Das Board zeigt ihn dann nirgends mehr an: Er waere
		// nicht geloescht, aber unerreichbar, und das ist genau der Ausgang, den
		// dieser ganze Weg vermeiden soll.
		//
		// Diese zweite Anweisung greift ohne ID-Liste und faengt deshalb auch,
		// was zwischen Lesen und Schreiben entstanden ist. Sie **repariert statt
		// abzubrechen** — eine Ausnahme haette denselben Vorgang liegen lassen.
		// Alle Nachzuegler teilen sich eine Position; die Reihenfolge entscheidet
		// dann die ID, wie ueberall sonst auch. Ein Rest bleibt: Was nach dieser
		// Anweisung und vor dem Wegfall der Spalte entsteht, faengt nur eine
		// Sperre auf der Spaltenzeile, und die waere teurer als der Fall haeufig.
		//
		// **Kein eigener Test.** Der Wettlauf laesst sich in der Suite nicht
		// herstellen: Jede Zeile, die zur Lesezeit da ist, geht durch die
		// Schleife. Ein Test, der einen Vorgang vorher anlegt, pruefte die
		// Schleife und behauptete, die Sammelanweisung zu pruefen — gruen und
		// blind. Was pruefbar ist, prueft `testEveryTicketMovedAlongEvenTheHiddenOnes`:
		// dass die Spalte danach leer ist. Diese Anweisung ist eine Obermenge
		// derselben Bedingung.
		$sweep = $this->db->getQueryBuilder();
		$sweep->update($this->tableName)
			->set('column_id', $sweep->createNamedParameter($toColumnId, IQueryBuilder::PARAM_INT))
			->set('position', $sweep->createNamedParameter($next, IQueryBuilder::PARAM_INT))
			->where($sweep->expr()->eq(
				'board_id',
				$sweep->createNamedParameter($viewer->boardId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($sweep->expr()->eq(
				'column_id',
				$sweep->createNamedParameter($fromColumnId, IQueryBuilder::PARAM_INT),
			));
		$sweep->executeStatement();
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
