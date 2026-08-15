<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Spalten pflegen — Name und Reihenfolge (§8).
 *
 * **Keine kundenspezifischen Spaltennamen.** Beide Seiten sehen dieselben
 * Spalten; ein Mapping intern → Kundenstatus ist ausdrücklich ausgeschlossen.
 * Deshalb gibt es hier nichts Rollenabhängiges.
 *
 * Die Reihenfolge wird als **vollständige Liste** gesetzt und nicht über
 * Nachbarn wie bei Tickets. Der Unterschied hat einen Grund: Spalten werden je
 * Betrachter **nicht gefiltert**. Es gibt also keine „ungefilterte Liste", die
 * von der gesehenen abweichen könnte — und damit auch keinen Anlass für die
 * Nachbar-Konstruktion, die beim Ticket nötig ist. Eine Handvoll Spalten am
 * Stück umzunummerieren ist einfacher und nachvollziehbarer.
 */
class ColumnService {

	public function __construct(
		private IDBConnection $db,
		private ColumnMapper $columns,
		private BoardMapper $boards,
		private TicketMapper $tickets,
	) {
	}

	/**
	 * Eine neue Spalte, hinten angehängt.
	 *
	 * @throws NotManagerException
	 */
	public function create(ViewerContext $viewer, string $title, ?string $color = null): Column {
		$this->assertManager($viewer);
		$this->assertTitle($title);

		$existing = $this->columns->findForBoard($viewer);

		$column = new Column();
		$column->setBoardId($viewer->boardId);
		$column->setTitle(trim($title));
		$column->setPosition(count($existing));
		$column->setColor($color);

		return $this->columns->insert($column);
	}

	/**
	 * @throws NotManagerException
	 * @throws DoesNotExistException die Spalte gehört nicht zu diesem Board
	 */
	public function rename(ViewerContext $viewer, int $columnId, string $title): Column {
		$this->assertManager($viewer);
		$this->assertTitle($title);

		$column = $this->findInBoard($viewer, $columnId);
		$column->setTitle(trim($title));

		return $this->columns->update($column);
	}

	/**
	 * Die Reihenfolge neu setzen.
	 *
	 * Erwartet **alle** Spalten des Boards in Sollreihenfolge. Eine unvollständige
	 * Liste wird abgewiesen statt still ergänzt: Sonst entschiede die Reihenfolge
	 * der nicht genannten Spalten der Zufall, und niemand könnte erklären, warum
	 * eine Spalte gewandert ist, die niemand angefasst hat.
	 *
	 * @param int[] $columnIds
	 * @return Column[] in der neuen Reihenfolge
	 * @throws NotManagerException
	 * @throws \InvalidArgumentException die Liste passt nicht zum Board
	 */
	public function reorder(ViewerContext $viewer, array $columnIds): array {
		$this->assertManager($viewer);

		$existing = [];
		foreach ($this->columns->findForBoard($viewer) as $column) {
			$existing[(int)$column->getId()] = $column;
		}

		$wanted = array_values(array_unique(array_map('intval', $columnIds)));

		if (count($wanted) !== count($existing) || array_diff($wanted, array_keys($existing)) !== []) {
			throw new \InvalidArgumentException(
				'Die Reihenfolge muss genau die Spalten dieses Boards enthalten.',
			);
		}

		$ordered = [];
		foreach ($wanted as $position => $columnId) {
			$column = $existing[$columnId];
			$column->setPosition($position);
			$ordered[] = $this->columns->update($column);
		}

		return $ordered;
	}

	/**
	 * Eine Spalte entfernen — **immer mit Zielspalte, nie mit Ticketverlust**.
	 *
	 * Die Spalte enthält womöglich Vorgänge, die der Löschende nicht sehen
	 * darf. Beide naheliegenden Rückfragen scheitern daran: Eine Zahl über
	 * alle verriete deren Existenz, eine Zahl über die sichtbaren wäre
	 * schlimmer, weil dann ungefragt mehr verschwände, als der Dialog sagt.
	 * Also wird nicht gelöscht, sondern verschoben, und die Zielspalte ist
	 * Pflicht — auch wenn die Spalte gerade leer aussieht. „Leer" ist eine
	 * Aussage über den Betrachter, nicht über die Spalte.
	 *
	 * **Verschieben und Wegfallen stehen in einer Transaktion.** Bräche es
	 * dazwischen ab, zeigten Tickets auf eine Spalte, die es nicht mehr gibt —
	 * und sie wären für niemanden mehr erreichbar, auch nicht für den, der sie
	 * sehen darf.
	 *
	 * @throws NotManagerException
	 * @throws NotOwnerException nur dem Eigentümer steht das offen
	 * @throws DoesNotExistException Spalte oder Ziel gehören nicht zu diesem Board
	 * @throws \InvalidArgumentException Ziel ist die Spalte selbst, oder es wäre die letzte
	 */
	public function delete(ViewerContext $viewer, int $columnId, int $targetColumnId): void {
		$this->assertManager($viewer);
		$this->assertOwner($viewer);

		if ($columnId === $targetColumnId) {
			// Ohne Datenbankzugriff pruefbar, deshalb vor der Transaktion.
			throw new \InvalidArgumentException('Die Zielspalte muss eine andere sein.');
		}

		// **Alles Weitere innerhalb der Transaktion**, auch das Lesen. Draussen
		// gelesen koennten zwei gleichzeitige Aufrufe beide an der Pruefung
		// „mindestens zwei Spalten" vorbeilaufen und ein Board ohne Spalte
		// zuruecklassen — mit allen Vorgaengen an Spalten, die es nicht mehr
		// gibt. Das schliesst das Fenster nicht vollstaendig (dafuer braeuchte
		// es eine Sperre auf der Spaltenzeile, und `FOR UPDATE` kennt SQLite
		// nicht), aber es verkleinert es auf die Dauer der Transaktion.
		$this->db->beginTransaction();

		try {
			$existing = $this->columns->findForBoard($viewer);

			// Die einzige Spalte zuerst: Sonst beantwortete ein Board mit einer
			// Spalte die Frage mit „Zielspalte unbekannt" — richtig, aber am
			// Problem vorbei. Es gibt hier kein Ziel, und ohne Ziel gibt es
			// keinen Weg, der nichts verliert.
			if (count($existing) < 2) {
				throw new \InvalidArgumentException('Die letzte Spalte eines Projekts lässt sich nicht entfernen.');
			}

			// Beide über die Board-Liste, nicht per ID-Zugriff: Das prüft in
			// einem Zug, dass die Spalte existiert **und** zu diesem Board
			// gehört.
			$column = $this->inList($existing, $columnId);
			$this->inList($existing, $targetColumnId);

			$this->tickets->moveColumnContents($viewer, $columnId, $targetColumnId);
			$this->columns->delete($column);
			$this->renumber($existing, $columnId);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/**
	 * Die verbleibenden Spalten lückenlos von 0 an durchnummerieren.
	 *
	 * **Sonst bricht das Anlegen.** {@see create()} vergibt `position =
	 * count($existing)` — mit einer Lücke in den Positionen trifft dieser Wert
	 * eine bestehende Spalte, und die neue Spalte landet mitten im Board statt
	 * hinten. Nach zwei Entfernungen ist das kein Randfall mehr, sondern der
	 * Normalfall. Die Invariante „Positionen sind 0..n-1" hält sonst nur
	 * {@see reorder()} aufrecht; sie muss auch das Entfernen überleben.
	 *
	 * @param Column[] $existing die Spalten **vor** dem Entfernen, in Reihenfolge
	 * @param int $removedId die Spalte, die gerade weggefallen ist
	 */
	private function renumber(array $existing, int $removedId): void {
		$position = 0;

		foreach ($existing as $column) {
			if ((int)$column->getId() === $removedId) {
				continue;
			}
			if ((int)$column->getPosition() !== $position) {
				$column->setPosition($position);
				$this->columns->update($column);
			}
			$position++;
		}
	}

	/**
	 * @throws DoesNotExistException
	 */
	private function findInBoard(ViewerContext $viewer, int $columnId): Column {
		return $this->inList($this->columns->findForBoard($viewer), $columnId);
	}

	/**
	 * Dieselbe Suche in einer bereits geladenen Liste.
	 *
	 * Das Entfernen braucht die Liste ohnehin — für die Frage, ob es die letzte
	 * Spalte ist — und schlüge sonst dreimal dieselbe Abfrage an.
	 *
	 * @param Column[] $columns
	 * @throws DoesNotExistException
	 */
	private function inList(array $columns, int $columnId): Column {
		foreach ($columns as $column) {
			if ((int)$column->getId() === $columnId) {
				return $column;
			}
		}

		throw new DoesNotExistException('Keine Spalte dieses Boards: ' . $columnId);
	}

	private function assertTitle(string $title): void {
		if (trim($title) === '') {
			throw new \InvalidArgumentException('Eine Spalte braucht einen Namen.');
		}
	}

	/**
	 * Enger als das Verwaltungsrecht: nur der Eigentümer des Projekts.
	 *
	 * Das Entfernen einer Spalte fasst Daten **aller** Beteiligten an, auch die
	 * für den Handelnden unsichtbaren. Es ist der einzige Vorgang mit dieser
	 * Reichweite, und deshalb der einzige, der enger sitzt als der Rest der
	 * Einstellungen (#60).
	 *
	 * @throws NotOwnerException
	 */
	private function assertOwner(ViewerContext $viewer): void {
		if ((string)$this->boards->findForViewer($viewer)->getOwnerUserId() === $viewer->userId) {
			return;
		}

		throw new NotOwnerException(
			'Eine Spalte entfernen darf nur, wem das Projekt gehört.',
		);
	}

	/**
	 * @throws NotManagerException
	 */
	private function assertManager(ViewerContext $viewer): void {
		if (!$viewer->isManager) {
			throw new NotManagerException(
				'Spalten dürfen nur interne Mitglieder mit Verwaltungsrecht pflegen.',
			);
		}
	}
}
