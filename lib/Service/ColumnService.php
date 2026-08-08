<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCP\AppFramework\Db\DoesNotExistException;

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
		private ColumnMapper $columns,
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
	 * @throws DoesNotExistException
	 */
	private function findInBoard(ViewerContext $viewer, int $columnId): Column {
		foreach ($this->columns->findForBoard($viewer) as $column) {
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
