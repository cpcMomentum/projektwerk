<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketReadMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IURLGenerator;

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
		private AttachmentService $attachmentService,
		private TicketReadMapper $reads,
		private TicketScope $scope,
		private PositionService $positions,
		private NotificationService $notifications,
		private GithubService $github,
		private IURLGenerator $urls,
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
		?string $dueDate = null,
	): Ticket {
		$this->assertKnownVisibility($visibility);

		// Einmal wiederholen, nicht öfter: Ein zweiter Fehlschlag am
		// eindeutigen Index ist kein Wettlauf mehr, sondern ein kaputter
		// Zähler — und den soll man sehen statt in einer Schleife zu verdecken.
		for ($attempt = 1; $attempt <= 2; $attempt++) {
			try {
				return $this->insertWithNumber($viewer, $title, $description, $visibility, $columnId, $responsibleUserId, $dueDate);
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
	 * @param array{title?: string, description?: ?string, responsibleUserId?: ?string, dueDate?: ?string, closed?: bool, outcome?: ?string} $changes
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
		// **Nur eine echte Aenderung loest aus.** Wer denselben Namen noch einmal
		// speichert — etwa weil er den Titel geaendert hat —, schickt keine
		// zweite Mail. Deshalb der Vergleich vor dem Setzen.
		$neuZugewiesen = null;
		if (array_key_exists('responsibleUserId', $changes)) {
			$vorher = $ticket->getResponsibleUserId();
			$nachher = $changes['responsibleUserId'];

			if ($nachher !== null && $nachher !== '') {
				// Dieselbe Sichtbarkeitspruefung wie bei der Schrittzuweisung
				// ({@see StepService::applyAssignment()}): Zustaendig darf nur
				// werden, wer das Ticket auch sehen wuerde. Sonst liesse sich per
				// API eine Mail an jemanden ausserhalb des Boards ausloesen.
				$rolle = $this->roleOnBoard($viewer, (string)$nachher);
				if ($rolle === null || !$this->scope->wouldSee(
					(string)$ticket->getVisibility(),
					(string)$ticket->getCreatorUserId(),
					(string)$ticket->getCreatorRole(),
					(string)$nachher,
					$rolle,
				)) {
					throw new \InvalidArgumentException('Diese Person kann diesen Vorgang nicht sehen.');
				}

				$ticket->setResponsibleUserId($nachher);

				if ($nachher !== $vorher) {
					// **Nur beim echten Wechsel** Rolle einfrieren und die Uhr
					// stellen (#114), wie `assigned_role`/`assigned_at` am Schritt.
					// Bei gleicher Person bleibt beides stehen: sonst spraenge das
					// Wartedatum bei jedem Neu-Speichern, und die eingefrorene
					// Rolle taute auf, sobald sich anderswo die Mitgliedschaft
					// aendert.
					$ticket->setResponsibleRole($rolle);
					$ticket->setResponsibleSince(new \DateTime());
					$neuZugewiesen = (string)$nachher;
				}
			} else {
				$ticket->setResponsibleUserId($nachher);
				// Verantwortlicher entfernt: die eingefrorene Rolle und der
				// Zeitpunkt gehen mit — ein Vorgang ohne Verantwortlichen wartet
				// ueber diese Quelle auf niemanden.
				$ticket->setResponsibleRole(null);
				$ticket->setResponsibleSince(null);
			}
		}
		// Die Faelligkeit: ein Datum setzt, der Leerstring loescht. `null` heisst
		// „nicht geschickt" und faellt schon im Controller heraus — deshalb kann
		// `array_key_exists` hier nicht das Loeschen tragen, der Leerstring tut es.
		if (array_key_exists('dueDate', $changes)) {
			$ticket->setDueDate($this->parseDueDate($changes['dueDate']));
		}
		// **Nur der Uebergang zaehlt**, nicht der Zustand: Ein zweites
		// `closed: true` an einem bereits geschlossenen Vorgang darf nicht noch
		// einmal benachrichtigen. Deshalb wird der Stand **vorher** gelesen.
		$frischGeschlossen = false;
		if (array_key_exists('closed', $changes)) {
			$warOffen = $ticket->getClosedAt() === null;
			$schliessen = (bool)$changes['closed'];
			$frischGeschlossen = $warOffen && $schliessen;
			$ticket->setClosedAt($schliessen ? new \DateTime() : null);
			// **Das Ergebnis begleitet den Abschluss** (#171): beim Schliessen
			// gewaehlt, beim Wieder-oeffnen geloescht — ein offener Vorgang hat
			// kein Ergebnis. Kein Drittzustand, nur ein Vorzeichen am Abschluss.
			$ticket->setClosedOutcome($schliessen ? $this->pruefeOutcome($changes['outcome'] ?? null) : null);
		}

		$this->touch($ticket, $viewer);
		$gespeichert = $this->tickets->update($ticket);

		// Ankuendigen, senden — **in dieser Reihenfolge und nach dem Schreiben**.
		// `update()` laeuft hier ohne eigene Transaktion; der Versand steht
		// trotzdem hinter dem Schreibvorgang, damit ein toter Mailserver ihn
		// nicht mitreisst.
		if ($neuZugewiesen !== null) {
			$vorgemerkt = $this->notifications->announce(
				$gespeichert,
				$neuZugewiesen,
				$viewer->userId,
				MailOutbox::EVENT_TICKET_ASSIGNED,
			);
			$this->notifications->deliver($vorgemerkt, $gespeichert);
		}

		// **Das Schliessen ist das Gegenstueck zum Rundruf** (#98) — Anfang und
		// Ende. Eine Nachricht pro Vorgangsleben, und weil die auslesende Person
		// in `announce()` herausfaellt, bekommt sie genau die andere Seite:
		// „Eure Sache ist durch."
		//
		// Das Verschieben nach „Erledigt" schliesst laut §9 ausdruecklich
		// **nicht**; Schliessen ist eine bewusste Handlung.
		if ($frischGeschlossen) {
			$vorgemerkt = $this->notifications->announceToInvolved(
				$gespeichert,
				$viewer->userId,
				MailOutbox::EVENT_TICKET_CLOSED,
			);
			$this->notifications->deliver($vorgemerkt, $gespeichert);
		}

		return $gespeichert;
	}

	/**
	 * Dieselbe Frage wie beim Lesen, nur fuer eine andere Person — analog zu
	 * {@see StepService::maySee()}.
	 */
	private function mayBecomeResponsible(Ticket $ticket, ViewerContext $viewer, string $userId): bool {
		$role = $this->roleOnBoard($viewer, $userId);
		if ($role === null) {
			return false;
		}

		return $this->scope->wouldSee(
			(string)$ticket->getVisibility(),
			(string)$ticket->getCreatorUserId(),
			(string)$ticket->getCreatorRole(),
			$userId,
			$role,
		);
	}

	/**
	 * Die Rolle einer Person auf dem Board des Betrachters, oder `null`, wenn
	 * kein Mitglied.
	 *
	 * Diese Rolle wird beim Eintragen des Verantwortlichen **eingefroren**
	 * (`responsible_role`), damit der Wartezustand nicht rueckwirkend kippt, wenn
	 * sich die Mitgliedschaft spaeter aendert — dieselbe Begruendung wie bei
	 * `assigned_role` am Schritt.
	 */
	private function roleOnBoard(ViewerContext $viewer, string $userId): ?string {
		foreach ($this->members->findForBoard($viewer) as $member) {
			if ((string)$member->getUserId() === $userId) {
				return (string)$member->getRole();
			}
		}

		return null;
	}

	/**
	 * Ein `JJJJ-MM-TT` in ein Datum, oder `null` fuer „leer".
	 *
	 * Dieselbe Regel wie am Schritt ({@see StepService::parseDueDate()}): ein
	 * Datum ohne Uhrzeit, damit „ueberfaellig" ein Tagvergleich bleibt. Der
	 * Leerstring loescht — das ist der Weg, eine Faelligkeit wieder abzunehmen,
	 * weil der Controller `null` als „nicht geschickt" herausfiltert.
	 *
	 * @throws \InvalidArgumentException kein Datum im Format JJJJ-MM-TT
	 */
	private function parseDueDate(?string $value): ?\DateTime {
		if ($value === null || trim($value) === '') {
			return null;
		}

		$date = \DateTime::createFromFormat('!Y-m-d', trim($value));
		if ($date === false) {
			throw new \InvalidArgumentException('Die Fälligkeit braucht das Format JJJJ-MM-TT.');
		}

		return $date;
	}

	/**
	 * Das Abschluss-Ergebnis auf einen erlaubten Wert bringen (#171).
	 *
	 * Nur `verworfen` (negativ) muss ausdrücklich gewählt werden; alles andere —
	 * eine fehlende oder unbekannte Angabe — gilt als `erledigt`. Ein Abschluss
	 * hat damit immer ein Vorzeichen, ohne dass ein leerer Wert einen dritten
	 * Zustand aufmacht. Der häufige, positive Fall ist zugleich der sichere
	 * Rückfall.
	 */
	private function pruefeOutcome(?string $outcome): string {
		return $outcome === Ticket::OUTCOME_DISCARDED
			? Ticket::OUTCOME_DISCARDED
			: Ticket::OUTCOME_DONE;
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
	 * **Anhänge ziehen mit** (#185) statt den Wechsel zu blockieren. Der
	 * Ablageort IST die Sichtbarkeit (§5.18), also wandert die Datei in den
	 * Ordner der Ziel-Sichtbarkeit. Die Reihenfolge relativ zum Schreiben hält
	 * die Datei dabei nie offener als den Vorgang (siehe unten); die Bewegung
	 * selbst macht {@see AttachmentService::relocate()}.
	 *
	 * Für intern↔öffentlich geht das immer — beide haben einen Ordner. Fehlt der
	 * Zielordner (nach `private`, Kundenseite nach intern), lehnt
	 * {@see AttachmentService::assertRelocatable()} den Wechsel ab, **bevor**
	 * geschrieben ist. Der private Ablageort kommt mit Phase B (#184).
	 *
	 * @throws DoesNotExistException   Ticket nicht sichtbar
	 * @throws ConflictException       zwischenzeitlich geändert
	 * @throws NotOwningSideException  die andere Seite besitzt dieses Ticket
	 * @throws NoFolderException       die Zielsichtbarkeit hat keinen Ablageort für die vorhandenen Anhänge
	 * @throws NotPermittedException   Anhang oder Zielordner beim Umzug nicht erreichbar/beschreibbar
	 */
	public function changeVisibility(ViewerContext $viewer, int $ticketId, int $version, string $visibility): Ticket {
		$this->assertKnownVisibility($visibility);

		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$this->assertVersion($ticket, $version);

		$this->assertOwningSide($viewer, $ticket, $visibility);

		$current = (string)$ticket->getVisibility();

		// Dieselbe Stufe noch einmal zu wählen bewegt nichts — keine Datei, kein
		// Schreiben. „Darf ich" (oben) steht dabei vor „geht es".
		if ($visibility === $current) {
			return $ticket;
		}

		// **Anhänge ziehen mit** (#185) statt den Wechsel zu blockieren — der
		// Ablageort IST die Sichtbarkeit (§5.18). Erst prüfen, ob die
		// Zielsichtbarkeit überhaupt einen Ablageort hat, solange ein Anhang
		// daranhängt: Fehlt er (nach `private`; Kundenseite nach intern), wird
		// der Wechsel abgelehnt, **bevor** irgendetwas geschrieben ist.
		$this->attachmentService->assertRelocatable($viewer, $ticket, $visibility);

		// **Die Reihenfolge hält die Datei nie offener als den Vorgang.**
		// Hochstufen: erst Sichtbarkeit, dann Datei in den offeneren Ordner —
		// klemmt der Umzug, ist der Vorgang schon offen, die Datei aber noch im
		// engeren Ordner und erscheint als „fehlt", nie offener. Herabstufen:
		// erst Datei in den engeren Ordner, dann Sichtbarkeit. Ein abgebrochener
		// Umzug degradiert so zu „Anhang fehlt", nie zu einem Leck; der
		// Reparaturschritt (RelocateAttachments) zieht ihn nach.
		if ($this->opennessRank($visibility) > $this->opennessRank($current)) {
			$ticket->setVisibility($visibility);
			$this->touch($ticket, $viewer);
			$saved = $this->tickets->update($ticket);
			$this->attachmentService->relocate($viewer, $saved, $visibility);

			return $saved;
		}

		$this->attachmentService->relocate($viewer, $ticket, $visibility);
		$ticket->setVisibility($visibility);
		$this->touch($ticket, $viewer);

		return $this->tickets->update($ticket);
	}

	/**
	 * Einen Vorgang **einseitig** als GitHub-Issue anlegen (#12, Stufe 1).
	 *
	 * Kein Inhalts- oder Kommentar-Sync, keine Rückkopplung — nur „hin". Am
	 * Vorgang bleiben Nummer und Adresse des Issues, damit sichtbar ist, dass er
	 * überführt wurde und wohin.
	 *
	 * **Fail-closed:** Die Nummer wird erst gespeichert, nachdem GitHub das Issue
	 * angelegt hat. Scheitert der Aufruf, wirft {@see GithubService} eine
	 * {@see GithubTransferException} und der Vorgang bleibt unverändert — nie
	 * entsteht ein halber Zustand.
	 *
	 * **Kein `version`-Parameter wie beim Sichtbarkeitswechsel:** Die Überführung
	 * ist keine konkurrierende Feldänderung, sondern einmalig. Gegen ein zweites
	 * Issue schützt nicht die Version, sondern die bereits gesetzte Nummer — ein
	 * erneuter Versuch endet als 409 mit dem aktuellen Stand.
	 *
	 * @throws NotOwningSideException wenn ein externes Mitglied es versucht (403)
	 * @throws ConflictException wenn der Vorgang schon überführt ist (409)
	 * @throws GithubTransferException bei fehlendem Token, falschem Repo oder GitHub-Fehler (400)
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn der Vorgang nicht sichtbar ist (404)
	 */
	public function transferToGithub(ViewerContext $viewer, int $ticketId): Ticket {
		// **Nur interne Mitglieder überführen** (§6.1). Externe (Kunden als
		// Gäste) sehen die Aktion nie; hier steht die serverseitige Grenze,
		// damit sie nicht bloß in der Oberfläche verborgen ist.
		if (!$viewer->isInternal()) {
			throw new NotOwningSideException(
				'Nur interne Mitglieder können Vorgänge nach GitHub überführen.',
			);
		}

		$ticket = $this->tickets->findVisible($viewer, $ticketId);

		// **Schon überführt: kein zweites Issue.** Der aktuelle Stand trägt
		// Nummer und Link; die Oberfläche zeigt daraufhin nur noch den Link.
		if ($ticket->getGithubIssueNumber() !== null) {
			throw new ConflictException($ticket);
		}

		$board = $this->boards->findForViewer($viewer);
		if ((int)$board->getGithubEnabled() !== 1) {
			throw new GithubTransferException(
				'Für dieses Projekt ist die GitHub-Anbindung nicht eingeschaltet.',
			);
		}

		$repo = trim((string)$board->getGithubRepo());
		if ($repo === '') {
			throw new GithubTransferException(
				'Für dieses Projekt ist kein Ziel-Repository hinterlegt.',
			);
		}

		$created = $this->github->createIssue(
			$viewer->userId,
			$repo,
			(string)$ticket->getTitle(),
			$this->githubIssueBody($ticket),
		);

		$ticket->setGithubIssueNumber($created['number']);
		$ticket->setGithubIssueUrl($created['url']);
		$this->touch($ticket, $viewer);

		return $this->tickets->update($ticket);
	}

	/**
	 * Der Rumpf des GitHub-Issues: die Beschreibung, darunter ein Rücklink auf
	 * den Vorgang. So ist die Herkunft von beiden Seiten nachvollziehbar.
	 */
	private function githubIssueBody(Ticket $ticket): string {
		$backlink = sprintf(
			'Aus ProjektWerk überführt (Vorgang #%d): %s',
			(int)$ticket->getNumber(),
			$this->deepLink((int)$ticket->getId()),
		);

		$description = trim((string)$ticket->getDescription());

		return $description === '' ? $backlink : $description . "\n\n" . $backlink;
	}

	/**
	 * Fragmentfreier Deep-Link auf den Vorgang, mit derselben Gegenprobe wie im
	 * {@see NotificationService}: Der Link muss `/t/<id>` enthalten, sonst wird
	 * er aus dem CLI-URL zusammengesetzt.
	 */
	private function deepLink(int $ticketId): string {
		$link = $this->urls->linkToRouteAbsolute(
			Application::APP_ID . '.deepLink.ticket',
			['ticketId' => $ticketId],
		);

		return str_contains($link, '/t/' . $ticketId)
			? $link
			: $this->urls->getAbsoluteURL('/index.php/apps/' . Application::APP_ID . '/t/' . $ticketId);
	}

	/**
	 * Wie offen eine Sichtbarkeit ist — je größer, desto mehr Menschen sehen den
	 * Vorgang (`private` < `internal` < `public`).
	 *
	 * **Nur für die Richtung eines Wechsels** (#185), nicht für die
	 * Sichtbarkeitsregel: Die steht als eine Bedingung an einer Stelle in
	 * `TicketScope`; diese Ordnung entscheidet keinen Zugriff, sie bestimmt nur,
	 * ob beim Umzug erst die Datei oder erst die Sichtbarkeit dran ist.
	 */
	private function opennessRank(string $visibility): int {
		return match ($visibility) {
			TicketScope::VISIBILITY_PUBLIC => 3,
			TicketScope::VISIBILITY_INTERNAL => 2,
			default => 1,
		};
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
		$saved = $this->tickets->update($ticket);

		// Die Lesestände zu diesem Vorgang wegräumen (#79): ohne ihren Vorgang
		// sind sie Karteileichen, die niemand mehr liest. Nach dem Schreiben,
		// damit ein Fehler hier den Löschvorgang nicht mitreisst.
		$this->reads->deleteForTicket($ticketId);

		return $saved;
	}

	/**
	 * Einen weich gelöschten Vorgang wiederherstellen (#167, Undo).
	 *
	 * Das Gegenstück zu {@see delete()}. Wer löschen darf, darf auch zurückholen —
	 * {@see \OCA\Projektwerk\Db\TicketMapper::findForRestore()} grenzt bereits auf
	 * das Board des Betrachters und die eigene Rolle ein, deshalb hier keine
	 * zweite Besitzerprüfung.
	 *
	 * **Kein `version`:** Die Wiederherstellung folgt dem Löschen unmittelbar
	 * (Undo-Toast) und ist idempotent — ein bereits offener Vorgang wird
	 * unverändert zurückgegeben, kein Fehler.
	 *
	 * @throws DoesNotExistException  unbekannt, fremdes Board oder andere Seite
	 */
	public function restore(ViewerContext $viewer, int $ticketId): Ticket {
		$ticket = $this->tickets->findForRestore($viewer, $ticketId);

		if ($ticket->getDeletedAt() === null) {
			return $ticket;
		}

		$ticket->setDeletedAt(null);
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
		?string $dueDate = null,
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

			// **Dieselbe Pruefung wie beim Aendern.** Sie loest hier heute keine
			// Mail aus — `create()` benachrichtigt nicht —, aber ohne sie
			// entstuende eine zustaendige Person, die ihren eigenen Vorgang
			// nicht sehen kann. Und sobald das Anlegen spaeter ebenfalls
			// benachrichtigt, waere es dieselbe Luecke wie die, die der Review
			// am 2026-08-11 im Aendern gefunden hat.
			if ($responsibleUserId !== null && $responsibleUserId !== ''
				&& !$this->mayBecomeResponsible($ticket, $viewer, $responsibleUserId)) {
				throw new \InvalidArgumentException('Diese Person kann diesen Vorgang nicht sehen.');
			}

			$ticket->setResponsibleUserId($responsibleUserId);
			if ($responsibleUserId !== null && $responsibleUserId !== '') {
				// Rolle einfrieren und die Uhr stellen (#114), wie am Schritt. Die
				// Rolle steht fest, weil die Pruefung oben die Mitgliedschaft schon
				// bestaetigt hat.
				$ticket->setResponsibleRole($this->roleOnBoard($viewer, $responsibleUserId));
				$ticket->setResponsibleSince($now);
			}
			$ticket->setDueDate($this->parseDueDate($dueDate));
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
