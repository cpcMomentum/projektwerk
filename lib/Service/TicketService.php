<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\MemberMapper;
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
		private ColumnMapper $columns,
		private MemberMapper $members,
		private CommentMapper $comments,
		private AttachmentMapper $attachments,
		private TicketScope $scope,
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
			$this->assertColumnInBoard($viewer, $targetColumnId);

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
			$this->touch($ticket, $viewer);

			$saved = $this->tickets->update($ticket);
			$this->db->commit();

			return $saved;
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/**
	 * Gibt es diese Spalte in diesem Board überhaupt (noch)?
	 *
	 * **Ohne diese Prüfung landet ein Vorgang an einer Spalte, die es nicht
	 * gibt — und ist damit für niemanden mehr erreichbar**, auch nicht für den,
	 * der ihn sehen darf: Die Board-Ansicht ordnet Tickets ihren Spalten zu und
	 * verwirft stillschweigend, was zu keiner passt.
	 *
	 * Der Weg dorthin ist keine Bosheit, sondern der Normalfall zweier
	 * geöffneter Browser: Wird eine Spalte entfernt (#60), kennt jeder andere
	 * Client sie weiterhin und darf sie anbieten. Das Ticket trägt dabei die
	 * unveränderte `version` — die optimistische Sperre schlägt also nicht an,
	 * denn das Verschieben ganzer Spalten ist keine inhaltliche Änderung am
	 * Vorgang und zählt sie bewusst nicht hoch. **Die Zielspalte muss deshalb
	 * hier geprüft werden und nicht über die Version.**
	 *
	 * Die Prüfung steht in derselben Transaktion wie der Schreibvorgang, damit
	 * zwischen Prüfen und Schreiben nichts dazwischenkommt.
	 *
	 * @throws DoesNotExistException die Spalte gehört nicht zu diesem Board
	 */
	private function assertColumnInBoard(ViewerContext $viewer, int $columnId): void {
		foreach ($this->columns->findForBoard($viewer) as $column) {
			if ((int)$column->getId() === $columnId) {
				return;
			}
		}

		throw new DoesNotExistException('Keine Spalte dieses Boards: ' . $columnId);
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

		$this->touch($ticket, $viewer);

		return $this->tickets->update($ticket);
	}

	/**
	 * Wer verliert den Zugriff, wenn die Sichtbarkeit auf `$visibility` geht?
	 *
	 * Für den Rückfragedialog aus §9, der **konkrete Zahlen und Namen** nennen
	 * soll statt einer allgemeinen Warnung — „Folgende Personen verlieren den
	 * Zugriff auf dieses Ticket, seine 4 Kommentare und 2 Anhänge: …".
	 *
	 * **Diese Rechnung gehört auf den Server**, obwohl das Frontend die
	 * Mitgliederliste ohnehin hat. Sie im Browser zu machen hieße, die
	 * Sichtbarkeitsregel ein zweites Mal umzusetzen — genau das, wogegen die
	 * ganze Bauform gerichtet ist. Sie läuft deshalb über
	 * {@see TicketScope::wouldSee()}, das unmittelbar neben dem JOIN steht und
	 * von der Leak-Matrix gegen ihn geprüft wird.
	 *
	 * @return array{losing: string[], comments: int, attachments: int}
	 * @throws DoesNotExistException Ticket nicht sichtbar
	 */
	public function visibilityImpact(ViewerContext $viewer, int $ticketId, string $visibility): array {
		$this->assertKnownVisibility($visibility);

		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$creator = (string)$ticket->getCreatorUserId();
		$creatorRole = (string)$ticket->getCreatorRole();

		$losing = [];
		foreach ($this->members->findForBoard($viewer) as $member) {
			$userId = (string)$member->getUserId();
			$role = (string)$member->getRole();

			$now = $this->scope->wouldSee((string)$ticket->getVisibility(), $creator, $creatorRole, $userId, $role);
			$after = $this->scope->wouldSee($visibility, $creator, $creatorRole, $userId, $role);

			if ($now && !$after) {
				$losing[] = $userId;
			}
		}

		$ids = [$ticketId];

		return [
			'losing' => $losing,
			// Über die gefilterte Einermenge, wie überall: Es gibt keinen Weg,
			// „die Kommentare zu Ticket 42" zu zählen, der nicht durch die
			// Sichtbarkeit geht.
			'comments' => $this->comments->countForTickets($ids)[$ticketId] ?? 0,
			'attachments' => $this->attachments->countForTickets($ids)[$ticketId] ?? 0,
		];
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
	 * **Ein Vorgang mit Anhängen lässt sich nicht umstellen** (§3.10 Stufe 1).
	 * Die Begründung steht bei {@see AttachmentsPresentException}; kurz: Der
	 * Ablageort ist die Sichtbarkeit, ein Umzug der Dateien ist nicht
	 * transaktional zur Datenbank, und ein halb gelungener Umzug wäre ein Leck,
	 * das keine spätere Codekorrektur heilt.
	 *
	 * @throws DoesNotExistException       Ticket nicht sichtbar
	 * @throws ConflictException           zwischenzeitlich geändert
	 * @throws NotOwningSideException      die andere Seite besitzt dieses Ticket
	 * @throws AttachmentsPresentException es hängen noch Anhänge daran
	 */
	public function changeVisibility(ViewerContext $viewer, int $ticketId, int $version, string $visibility): Ticket {
		$this->assertKnownVisibility($visibility);

		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$this->assertVersion($ticket, $version);

		$this->assertOwningSide($viewer, $ticket, $visibility);

		// **„Darf ich" steht vor „geht es".** Wer die Seite nicht besitzt, darf
		// ohnehin nicht umstellen und bekommt deshalb auch keine Zahl über die
		// Anhänge zu sehen. Und nur bei einer echten Änderung: Dieselbe Stufe
		// noch einmal zu wählen bewegt keine Datei und darf an einem Anhang
		// nicht scheitern.
		if ($visibility !== (string)$ticket->getVisibility()) {
			$this->assertNoAttachments($viewer, $ticketId);
		}

		$ticket->setVisibility($visibility);
		$this->touch($ticket, $viewer);

		return $this->tickets->update($ticket);
	}

	/**
	 * Einen Vorgang loeschen — weich.
	 *
	 * **Es gibt keinen Papierkorb in der App**, und das ist der Entwurf, nicht
	 * eine Auslassung. Eine Ansicht geloeschter Vorgaenge waere ein zweiter Ort,
	 * an dem Tickets leben, und damit ein zweiter Ort, an dem die
	 * Sichtbarkeitsregel stimmen muesste — bei einem Produkt, das genau darauf
	 * beruht, dass es einen gibt. Sie waere ausserdem der bequemste Weg an das,
	 * was die App zu verbergen verspricht.
	 *
	 * `deleted_at` wird deshalb allein von {@see TicketScope::apply()}
	 * ausgewertet: Jeder Lesepfad geht ohnehin dort durch. Wiederhergestellt
	 * wird per `occ projektwerk:ticket:restore` — ein Serverzugang ist die
	 * ehrlichere Grenze als ein Knopf in der Oberflaeche.
	 *
	 * **Wer loeschen darf, folgt §7**: die Seite, der das Ticket gehoert. Dieselbe
	 * Regel wie beim Sichtbarkeitswechsel, aus demselben Grund — sonst koennte
	 * die eine Seite Vorgaenge der anderen verschwinden lassen.
	 *
	 * @throws DoesNotExistException   Ticket nicht sichtbar
	 * @throws ConflictException       zwischenzeitlich geaendert
	 * @throws NotOwningSideException  die andere Seite besitzt dieses Ticket
	 */
	public function delete(ViewerContext $viewer, int $ticketId, int $version): Ticket {
		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$this->assertVersion($ticket, $version);

		if ($ticket->getCreatorRole() !== $viewer->role) {
			throw new NotOwningSideException(
				'Löschen darf nur die Seite, der der Vorgang gehört.',
			);
		}

		$ticket->setDeletedAt(new \DateTime());
		$this->touch($ticket, $viewer);

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
			$this->assertColumnInBoard($viewer, $columnId);

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

	/**
	 * Ein Schreibvorgang: Version hoch, Zeitstempel neu, Urheber vermerkt.
	 *
	 * **`last_editor_user_id` benennt genau, wer den aktuellen `version`-Stand
	 * verursacht hat** — nicht mehr und nicht weniger. Deshalb wird es an jedem
	 * Schreibweg gesetzt, auch beim Verschieben und beim Sichtbarkeitswechsel:
	 * Stünde `version` auf 3 und der Urheber noch bei dem von Version 2, wäre
	 * das Paar eine Lüge, und jede Anzeige, die darauf baut, ebenfalls.
	 *
	 * Beim Anlegen wird es **nicht** gesetzt. `null` heißt „seit dem Anlegen
	 * unverändert"; wer es angelegt hat, steht in `creator_user_id` und wird
	 * hier nicht wiederholt.
	 */
	private function touch(Ticket $ticket, ViewerContext $viewer): void {
		$ticket->setVersion((int)$ticket->getVersion() + 1);
		$ticket->setLastEditorUserId($viewer->userId);
		$ticket->setUpdatedAt(new \DateTime());
	}

	/**
	 * §7, wörtlich: Ändern darf nur die Seite, der das Ticket gehört — und auf
	 * „privat" herunter nur die anlegende Person selbst.
	 *
	 * Die Begründung ist keine Zierde: Sonst könnte ein interner Mitarbeiter ein
	 * Kundenticket so herunterstufen, dass er selbst den Zugriff verliert — ein
	 * Vorgang, der danach für niemanden mehr erreichbar wäre.
	 *
	 * @throws NotOwningSideException
	 */
	private function assertOwningSide(ViewerContext $viewer, Ticket $ticket, string $visibility): void {
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
	}

	/**
	 * Der Riegel aus §3.10 Stufe 1.
	 *
	 * Gezählt wird über die gefilterte Einermenge — es gibt keinen Weg, „die
	 * Anhänge zu Vorgang 42" zu zählen, der nicht durch die Sichtbarkeit geht.
	 *
	 * @throws AttachmentsPresentException
	 */
	private function assertNoAttachments(ViewerContext $viewer, int $ticketId): void {
		$count = $this->attachments->countForTickets([$ticketId])[$ticketId] ?? 0;

		if ($count > 0) {
			// Die Zahl steht in der Meldung, weil sie die Handlung bestimmt:
			// „Bitte den Anhang zuerst entfernen" ist eine andere Aufgabe als
			// dieselbe Bitte für sieben.
			throw new AttachmentsPresentException($count);
		}
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
