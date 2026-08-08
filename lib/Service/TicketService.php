<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Der Schreibpfad am Ticket.
 *
 * Drei Dinge stehen hier und nirgends sonst: die atomare Nummer (§3.9), die
 * Positionsauflösung über Nachbarn statt Zahlen (§3.6) und die einzige
 * Schreibregel, die die Produktbeschreibung tatsächlich formuliert — wer die
 * Sichtbarkeit ändern darf.
 *
 * **Was hier NICHT steht, ist Absicht.** Die Produktbeschreibung regelt in §7
 * ausschließlich die Sichtbarkeit („Ändern darf die Sichtbarkeit nur die Seite,
 * der das Ticket gehört"). Zu Titel, Beschreibung, Zuständigkeit und Schließen
 * sagt sie nichts — das Board ist ausdrücklich „dasselbe Board, nur gefiltert",
 * also ein gemeinsamer Arbeitsraum. Eine zusätzliche Schreibsperre wäre ein
 * Begriff, den das Produkt nicht kennt; sie hier zu erfinden ist genau der
 * Fehler, den ein Review in PR #33 schon einmal entfernt hat.
 */
class TicketService {

	public function __construct(
		private IDBConnection $db,
		private TicketMapper $tickets,
		private BoardMapper $boards,
		private PositionService $positions,
	) {
	}

	/**
	 * Ein neues Ticket, hinten in seiner Spalte.
	 *
	 * Die Transaktion umfasst Nummer und Einfügen — und **sonst nichts**. Kein
	 * Dateizugriff, kein Mailversand: Die Board-Zeile ist der einzige
	 * Serialisierungspunkt der App, und alles, was in dieser Transaktion
	 * wartet, lässt jeden anderen Schreibvorgang im Board mitwarten (§3.9).
	 *
	 * @throws DbException wenn auch der zweite Versuch am eindeutigen Index scheitert
	 */
	public function create(
		ViewerContext $viewer,
		string $title,
		?string $description,
		string $visibility,
		int $columnId,
		?string $responsibleUserId = null,
	): Ticket {
		$this->assertKnownVisibility($visibility);

		// Einmal wiederholen, nicht öfter: Ein zweiter Fehlschlag am
		// eindeutigen Index ist kein Wettlauf mehr, sondern ein kaputter
		// Zähler — und den soll man sehen statt in einer Schleife zu verdecken.
		for ($attempt = 1; $attempt <= 2; $attempt++) {
			try {
				return $this->insertWithNumber($viewer, $title, $description, $visibility, $columnId, $responsibleUserId);
			} catch (DbException $e) {
				if ($attempt === 2 || $e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}

		throw new \LogicException('unerreichbar');
	}

	/**
	 * Ein Ticket in eine Spalte und zwischen zwei Nachbarn schieben.
	 *
	 * **Der Aufrufer schickt Nachbar-IDs, nie eine Position** (§3.6). Der
	 * Menüeintrag „Verschieben nach …", der Tastaturweg und später Drag & Drop
	 * rufen damit dieselbe Funktion — die Tastaturbedienung ist strukturell
	 * erfüllt statt nachgerüstet.
	 *
	 * Beide Nachbarn werden über {@see TicketMapper::findVisible()} aufgelöst.
	 * Das ist keine Umständlichkeit: Es sind die Nachbarn **aus der Sicht des
	 * Aufrufers**, also Tickets, die er sehen darf. Eine ID, die er nicht sehen
	 * darf, ergibt dieselbe Ausnahme wie eine, die es nicht gibt.
	 *
	 * @throws DoesNotExistException Ticket oder Nachbar nicht sichtbar
	 * @throws ConflictException     das Ticket wurde zwischenzeitlich geändert
	 */
	public function move(
		ViewerContext $viewer,
		int $ticketId,
		int $version,
		int $targetColumnId,
		?int $beforeId,
		?int $afterId,
	): Ticket {
		$this->db->beginTransaction();

		try {
			$ticket = $this->tickets->findVisible($viewer, $ticketId);
			$this->assertVersion($ticket, $version);

			$before = $this->neighbourPosition($viewer, $beforeId, $targetColumnId);
			$after = $this->neighbourPosition($viewer, $afterId, $targetColumnId);

			if ($this->positions->needsRebalance($before, $after)) {
				// Die Lücke ist aufgebraucht. Erst die Spalte neu nummerieren,
				// dann die Nachbarn erneut lesen — ihre Positionen sind jetzt
				// andere.
				$this->tickets->rebalanceColumn(
					$viewer,
					$targetColumnId,
					fn (array $ids): array => $this->positions->rebalance($ids),
				);
				$before = $this->neighbourPosition($viewer, $beforeId, $targetColumnId);
				$after = $this->neighbourPosition($viewer, $afterId, $targetColumnId);
			}

			$ticket->setColumnId($targetColumnId);
			$ticket->setPosition($this->positions->between($before, $after));
			$this->touch($ticket);

			$saved = $this->tickets->update($ticket);
			$this->db->commit();

			return $saved;
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/**
	 * Titel, Beschreibung, Zuständigkeit, geschlossen ja/nein.
	 *
	 * Die Sichtbarkeit ist hier **nicht** dabei — sie hat eine eigene Regel und
	 * deshalb einen eigenen Weg ({@see changeVisibility()}). Ein gemeinsames
	 * Update, das ein Feld anders behandelt als die übrigen, wäre die Stelle,
	 * an der die Regel beim nächsten Feld vergessen wird.
	 *
	 * @param array{title?: string, description?: ?string, responsibleUserId?: ?string, closed?: bool} $changes
	 * @throws DoesNotExistException Ticket nicht sichtbar
	 * @throws ConflictException     zwischenzeitlich geändert
	 */
	public function update(ViewerContext $viewer, int $ticketId, int $version, array $changes): Ticket {
		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$this->assertVersion($ticket, $version);

		if (array_key_exists('title', $changes)) {
			$ticket->setTitle($changes['title']);
		}
		if (array_key_exists('description', $changes)) {
			$ticket->setDescription($changes['description']);
		}
		if (array_key_exists('responsibleUserId', $changes)) {
			$ticket->setResponsibleUserId($changes['responsibleUserId']);
		}
		if (array_key_exists('closed', $changes)) {
			$ticket->setClosedAt($changes['closed'] ? new \DateTime() : null);
		}

		$this->touch($ticket);

		return $this->tickets->update($ticket);
	}

	/**
	 * Die Sichtbarkeit ändern — die einzige Schreibregel, die §7 formuliert.
	 *
	 * Zwei Sätze, wörtlich: „Ändern darf die Sichtbarkeit nur die Seite, der das
	 * Ticket gehört" und „Das Herunterstufen auf `private` kann nur die
	 * anlegende Person selbst."
	 *
	 * Die Begründung steht dort gleich dabei und ist der Grund, warum die Regel
	 * nicht bloß Zierde ist: Sonst könnte ein interner Mitarbeiter ein
	 * Kundenticket so herunterstufen, dass er selbst den Zugriff verliert — ein
	 * Vorgang, der danach für niemanden mehr erreichbar wäre.
	 *
	 * @throws DoesNotExistException  Ticket nicht sichtbar
	 * @throws ConflictException      zwischenzeitlich geändert
	 * @throws NotOwningSideException die andere Seite besitzt dieses Ticket
	 */
	public function changeVisibility(ViewerContext $viewer, int $ticketId, int $version, string $visibility): Ticket {
		$this->assertKnownVisibility($visibility);

		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$this->assertVersion($ticket, $version);

		if ($ticket->getCreatorRole() !== $viewer->role) {
			throw new NotOwningSideException(
				'Die Sichtbarkeit darf nur die Seite ändern, der das Ticket gehört.',
			);
		}

		if ($visibility === TicketScope::VISIBILITY_PRIVATE
			&& $ticket->getCreatorUserId() !== $viewer->userId) {
			throw new NotOwningSideException(
				'Auf „privat" herunterstufen kann nur die anlegende Person selbst.',
			);
		}

		$ticket->setVisibility($visibility);
		$this->touch($ticket);

		return $this->tickets->update($ticket);
	}

	private function insertWithNumber(
		ViewerContext $viewer,
		string $title,
		?string $description,
		string $visibility,
		int $columnId,
		?string $responsibleUserId,
	): Ticket {
		$this->db->beginTransaction();

		try {
			$number = $this->boards->claimTicketNumber($viewer);
			$last = $this->tickets->findLastPositionInColumn($viewer, $columnId);
			$now = new \DateTime();

			$ticket = new Ticket();
			$ticket->setBoardId($viewer->boardId);
			$ticket->setColumnId($columnId);
			$ticket->setNumber($number);
			$ticket->setTitle($title);
			$ticket->setDescription($description);
			$ticket->setVisibility($visibility);
			$ticket->setCreatorUserId($viewer->userId);
			// Eingefroren, nicht zur Laufzeit ermittelt: Sonst bräche die
			// Symmetrie von `internal`, sobald jemand die Rolle wechselt oder
			// das Board verlässt.
			$ticket->setCreatorRole($viewer->role);
			$ticket->setResponsibleUserId($responsibleUserId);
			$ticket->setPosition($this->positions->between($last, null));
			$ticket->setVersion(1);
			$ticket->setCreatedAt($now);
			$ticket->setUpdatedAt($now);

			$saved = $this->tickets->insert($ticket);
			$this->db->commit();

			return $saved;
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/**
	 * Die Position eines Nachbarn, oder `null` für „kein Nachbar".
	 *
	 * @throws DoesNotExistException der Nachbar ist nicht sichtbar
	 * @throws \InvalidArgumentException der Nachbar steht in einer anderen Spalte
	 */
	private function neighbourPosition(ViewerContext $viewer, ?int $neighbourId, int $columnId): ?int {
		if ($neighbourId === null) {
			return null;
		}

		$neighbour = $this->tickets->findVisible($viewer, $neighbourId);

		if ((int)$neighbour->getColumnId() !== $columnId) {
			// Ohne diese Prüfung würde zwischen Positionen aus zwei Spalten
			// gerechnet — das Ergebnis wäre eine gültige Zahl an einer Stelle,
			// die niemand gemeint hat.
			throw new \InvalidArgumentException(
				'Nachbar ' . $neighbourId . ' steht nicht in der Zielspalte.',
			);
		}

		return (int)$neighbour->getPosition();
	}

	/**
	 * Optimistisches Sperren über `version` (§ Konflikterkennung).
	 *
	 * @throws ConflictException
	 */
	private function assertVersion(Ticket $ticket, int $expected): void {
		if ((int)$ticket->getVersion() !== $expected) {
			throw new ConflictException($ticket);
		}
	}

	private function touch(Ticket $ticket): void {
		$ticket->setVersion((int)$ticket->getVersion() + 1);
		$ticket->setUpdatedAt(new \DateTime());
	}

	private function assertKnownVisibility(string $visibility): void {
		$known = [
			TicketScope::VISIBILITY_PUBLIC,
			TicketScope::VISIBILITY_INTERNAL,
			TicketScope::VISIBILITY_PRIVATE,
		];

		if (!in_array($visibility, $known, true)) {
			// Ein unbekannter Wert darf nicht durchrutschen: Die
			// Sichtbarkeitsregel vergleicht exakt gegen diese drei Zeichenketten,
			// ein Tippfehler machte das Ticket für alle unsichtbar.
			throw new \InvalidArgumentException('Unbekannte Sichtbarkeit: ' . $visibility);
		}
	}
}
