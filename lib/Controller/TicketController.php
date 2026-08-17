<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Access\WaitStateCalculator;
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketReadMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCA\Projektwerk\Service\AttachmentService;
use OCA\Projektwerk\Service\AttachmentsPresentException;
use OCA\Projektwerk\Service\ConflictException;
use OCA\Projektwerk\Service\NotOwningSideException;
use OCA\Projektwerk\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tickets lesen und schreiben.
 *
 * Dünn nach §3.5: Kontext holen, Dienst rufen, `JSONResponse`. Die
 * Sichtbarkeitsregel steckt in `TicketScope`, die Schreibregel in
 * {@see TicketService} — hier steht keine von beiden.
 *
 * **Ratenbegrenzung an `create`** (§3.5): Am Anlegen hängt ab Phase 6 der
 * Mailversand, und das ist ein Versandhebel in Kundenhand. Die Grenze steht
 * hier, bevor es den Versand gibt, weil sie danach leicht vergessen wird.
 */
class TicketController extends Controller {

	public function __construct(
		IRequest $request,
		private TicketMapper $tickets,
		private CommentMapper $comments,
		private StepMapper $steps,
		private AttachmentMapper $attachments,
		private TicketUserMapper $ticketUsers,
		private TicketReadMapper $reads,
		private TicketService $service,
		private AttachmentService $attachmentService,
		private WaitStateCalculator $waitState,
		private BoardAccess $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Die sichtbaren Tickets eines Boards, mit den Zählern ihrer Kinder.
	 *
	 * Die Zähler kommen aus derselben gefilterten ID-Menge wie die Tickets
	 * selbst. §5.8 nennt sie ausdrücklich: Ein Zähler, der mitzählt, was
	 * verborgen ist, verrät dessen Existenz genauso wie eine Zeile.
	 */
	#[NoAdminRequired]
	public function index(int $boardId, ?int $columnId = null): JSONResponse {
		return $this->withViewer($boardId, function (ViewerContext $viewer) use ($columnId): JSONResponse {
			$tickets = $this->tickets->findVisibleInBoard($viewer, $columnId);
			$ids = array_map(static fn ($ticket): int => (int)$ticket->getId(), $tickets);
			$steps = $this->steps->findForTickets($ids);

			return new JSONResponse([
				'tickets' => $tickets,
				// „Wartet auf Kunde" wird gerechnet, nie gespeichert — und aus
				// **denselben** Schritten, die auch die Zaehler speisen. Eine
				// zweite Abfrage waere ein zweiter Ort, an dem die Sichtbarkeit
				// stimmen muesste.
				'waiting' => $this->waitState->forTickets($tickets, $steps),
				'counts' => [
					'comments' => $this->comments->countForTickets($ids),
					'steps' => $this->steps->countForTickets($ids),
					// Erledigte je Ticket aus **derselben** Menge wie die
					// Gesamtzahl — „3 von 5" darf nicht aus zwei Abfragen
					// stammen, sonst zeigt die Karte irgendwann 6 von 5.
					'stepsDone' => $this->doneCounts($steps),
					'attachments' => $this->attachments->countForTickets($ids),
					'collaborators' => $this->ticketUsers->countForTickets($ids),
				],
				// „Seit deinem Blick geändert" (#79) — nur für Vorgänge, die
				// dieser Betrachter schon einmal geöffnet hat. Aus **seinem**
				// Lesestand und der Bewegung (Ticket-Änderung oder neuer
				// Kommentar), beides über die bereits gefilterte Menge.
				'changed' => $this->changedSince($viewer, $tickets, $ids),
			]);
		});
	}

	/**
	 * Ein Ticket mit seinen Kindern.
	 *
	 * Die Kinder werden über eine **Einermenge** geladen — sperriger als ein
	 * direkter Zugriff, und genau so gemeint: Es gibt keine Methode, die
	 * „die Kommentare zu Ticket 42" lädt, sondern nur „die Kommentare zu den
	 * Tickets, die dieser Betrachter sehen darf".
	 */
	#[NoAdminRequired]
	public function show(int $boardId, int $ticketId): JSONResponse {
		return $this->withViewer($boardId, function (ViewerContext $viewer) use ($ticketId): JSONResponse {
			try {
				$ticket = $this->tickets->findVisible($viewer, $ticketId);
			} catch (DoesNotExistException) {
				return new JSONResponse([], Http::STATUS_NOT_FOUND);
			}

			$ids = [(int)$ticket->getId()];
			$steps = $this->steps->findForTickets($ids);

			return new JSONResponse([
				'ticket' => $ticket,
				'waiting' => $this->waitState->forTicket($ticket, $steps),
				'comments' => $this->comments->findForTickets($ids),
				'steps' => $steps,
				// Mit `missing`-Angabe je Anhang (#9): verwaiste Dateien werden
				// gezeigt, nicht verschwiegen. Die Menge kommt weiter über den
				// gefilterten `findForTickets()`-Weg.
				'attachments' => $this->attachmentService->withPresence($viewer, $this->attachments->findForTickets($ids)),
				'collaborators' => $this->ticketUsers->findForTickets($ids),
			]);
		});
	}

	/**
	 * Einen Vorgang als gelesen vermerken (#79).
	 *
	 * Setzt den Lesestand dieser Person auf jetzt — der Punkt „seit deinem
	 * Blick geändert" verschwindet damit von der Karte. Nur für einen Vorgang,
	 * den die Person auch sehen darf: `findVisible()` wirft sonst, und ein Stand
	 * zu einem verborgenen Vorgang entstünde nie.
	 *
	 * `POST`, kein `GET`: Es ist ein Schreibvorgang, und er soll durch die
	 * CSRF-Prüfung. Der Rumpf ist leer; die Antwort ist der Erfolg selbst.
	 */
	#[NoAdminRequired]
	public function read(int $boardId, int $ticketId): JSONResponse {
		return $this->withViewer($boardId, function (ViewerContext $viewer) use ($ticketId): JSONResponse {
			try {
				$ticket = $this->tickets->findVisible($viewer, $ticketId);
			} catch (DoesNotExistException) {
				return new JSONResponse([], Http::STATUS_NOT_FOUND);
			}

			$this->reads->markSeen($viewer->userId, (int)$ticket->getId());

			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		});
	}

	/**
	 * Ein neues Ticket.
	 *
	 * Die Sichtbarkeit ist ein Pflichtfeld ohne serverseitige Vorbelegung. §9
	 * verlangt die Zeile **fest sichtbar** im Formular mit der Vorauswahl „Alle
	 * Beteiligten" — die Vorauswahl gehört ins Formular, damit sie sichtbar ist.
	 * Ein stiller Vorgabewert im Server wäre genau die eingeklappte Variante,
	 * die §9 verhindern will.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function create(
		int $boardId,
		string $title,
		int $columnId,
		string $visibility,
		?string $description = null,
		?string $responsibleUserId = null,
		?string $dueDate = null,
	): JSONResponse {
		return $this->withViewer($boardId, function (ViewerContext $viewer) use ($title, $columnId, $visibility, $description, $responsibleUserId, $dueDate): JSONResponse {
			try {
				return new JSONResponse(
					$this->service->create($viewer, $title, $description, $visibility, $columnId, $responsibleUserId, $dueDate),
					Http::STATUS_CREATED,
				);
			} catch (\InvalidArgumentException $e) {
				return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
			}
		});
	}

	#[NoAdminRequired]
	public function update(
		int $boardId,
		int $ticketId,
		int $version,
		?string $title = null,
		?string $description = null,
		?string $responsibleUserId = null,
		?string $dueDate = null,
		?bool $closed = null,
	): JSONResponse {
		// Nur das übernehmen, was tatsächlich geschickt wurde: Ein
		// nicht genanntes Feld darf nicht auf null zurückfallen. Das Loeschen
		// einer Faelligkeit reist deshalb als Leerstring, nicht als `null` — der
		// waere hier nicht von „nicht geschickt" zu unterscheiden.
		$changes = array_filter(
			[
				'title' => $title,
				'description' => $description,
				'responsibleUserId' => $responsibleUserId,
				'dueDate' => $dueDate,
				'closed' => $closed,
			],
			static fn ($value): bool => $value !== null,
		);

		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->update($viewer, $ticketId, $version, $changes));
	}

	/**
	 * Verschieben — mit Nachbar-IDs, nie mit einer Position (§3.6).
	 */
	#[NoAdminRequired]
	public function move(
		int $boardId,
		int $ticketId,
		int $version,
		int $targetColumnId,
		?int $beforeId = null,
		?int $afterId = null,
	): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->move($viewer, $ticketId, $version, $targetColumnId, $beforeId, $afterId));
	}

	/**
	 * Die Sichtbarkeit ändern — eigener Weg, weil sie als einziges Feld eine
	 * Schreibregel hat.
	 *
	 * **`visibilityImpact()` stand hier bis #103** und beantwortete vorab, wer
	 * durch einen Wechsel den Zugriff verliert — für die Rückfrage aus §9. Mit
	 * dem Wegfall der Rückfrage (Axel, 2026-08-13) hat die Antwort keinen
	 * Abnehmer mehr, und der Endpunkt ist aufgegeben statt verwaist gelassen:
	 * Ein Lesepfad ist eine Stelle, an der die Sichtbarkeitsregel stimmen muss,
	 * und die Leak-Matrix musste ihn mitfahren.
	 *
	 * Die Anhänge-Sperre (§3.10 Stufe 1) braucht ihn nicht — sie kommt aus der
	 * Absage dieses Schreibwegs, mit der Zahl im Rumpf.
	 */
	#[NoAdminRequired]
	public function visibility(int $boardId, int $ticketId, int $version, string $visibility): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->changeVisibility($viewer, $ticketId, $version, $visibility));
	}

	/**
	 * Einen Vorgang loeschen — weich, und ohne Papierkorb in der App.
	 *
	 * Wiederhergestellt wird ueber {@see restore()} (Undo-Toast) oder, falls
	 * der Toast schon verschwunden ist, per `occ projektwerk:ticket:restore`.
	 * Der Rueckgabewert ist der geloeschte Stand; das Frontend nimmt die Karte
	 * daraufhin aus der Ansicht.
	 */
	#[NoAdminRequired]
	public function destroy(int $boardId, int $ticketId, int $version): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->delete($viewer, $ticketId, $version));
	}

	/**
	 * Einen weich gelöschten Vorgang wiederherstellen (#167, Undo).
	 *
	 * Das Gegenstück zu {@see destroy()}: Nach dem Löschen bietet die Oberfläche
	 * einen Undo-Toast; ein Klick darauf ruft diesen Endpunkt. Ohne `version`,
	 * weil die Wiederherstellung idempotent ist und dem Löschen unmittelbar folgt.
	 */
	#[NoAdminRequired]
	public function restore(int $boardId, int $ticketId): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->restore($viewer, $ticketId));
	}

	/**
	 * Wie viele Schritte je Ticket erledigt sind.
	 *
	 * Aus derselben Menge wie die Gesamtzahl — „3 von 5" darf nicht aus zwei
	 * Abfragen stammen, sonst zeigt die Karte irgendwann 6 von 5.
	 *
	 * @param \OCA\Projektwerk\Db\Step[] $steps
	 * @return array<int, int>
	 */
	private function doneCounts(array $steps): array {
		$done = [];
		foreach ($steps as $step) {
			$ticketId = (int)$step->getTicketId();
			$done[$ticketId] ??= 0;
			if ($step->isDone()) {
				$done[$ticketId]++;
			}
		}

		return $done;
	}

	/**
	 * „Seit deinem Blick geändert" je Vorgang (#79) — nur die geänderten stehen
	 * drin, wie beim Wartezustand.
	 *
	 * **Nur für schon einmal geöffnete Vorgänge.** Ein nie geöffneter bekommt
	 * keinen Punkt: „seit du zuletzt draufgeschaut hast" setzt ein Draufschauen
	 * voraus, und am ersten Tag trüge sonst jede Karte einen. Neue Zuweisungen
	 * fangen Glocke, Mail und „Meine Vorgänge" ab.
	 *
	 * **Bewegung heisst Ticket-Änderung oder neuer Kommentar.** Beide über die
	 * bereits gefilterte Menge — der Lesestand nach `user_id`, der jüngste
	 * Kommentar über dieselben sichtbaren IDs. Verglichen wird über Zeitstempel,
	 * nicht über die ISO-Zeichenkette: Deren Reihenfolge stimmt nur bei gleichem
	 * Zeitzonenversatz, und daran soll die Rechnung nicht hängen.
	 *
	 * @param \OCA\Projektwerk\Db\Ticket[] $tickets
	 * @param int[] $ids
	 * @return array<int, true> Nur die geänderten Vorgänge.
	 */
	private function changedSince(ViewerContext $viewer, array $tickets, array $ids): array {
		$seen = $this->reads->findSeenForTickets($viewer->userId, $ids);
		if ($seen === []) {
			return [];
		}

		$newestComment = $this->comments->findNewestForTickets($ids);

		$changed = [];
		foreach ($tickets as $ticket) {
			$id = (int)$ticket->getId();
			if (!isset($seen[$id])) {
				continue;
			}

			$seenTs = (int)strtotime($seen[$id]);
			$activityTs = $ticket->getUpdatedAt()?->getTimestamp() ?? 0;
			$commentTs = isset($newestComment[$id]) ? (int)strtotime($newestComment[$id]) : 0;

			if (max($activityTs, $commentTs) > $seenTs) {
				$changed[$id] = true;
			}
		}

		return $changed;
	}

	/**
	 * Der gemeinsame Rahmen der Schreibwege: Kontext, Dienst, Fehlerformen.
	 *
	 * @param callable(ViewerContext): mixed $write
	 */
	private function write(int $boardId, callable $write): JSONResponse {
		return $this->withViewer($boardId, function (ViewerContext $viewer) use ($write): JSONResponse {
			try {
				return new JSONResponse($write($viewer));
			} catch (ConflictException $e) {
				// 409 **mit dem aktuellen Stand**: Ohne ihn bliebe dem Frontend
				// nur ein Neuladen, und der Nutzer verlöre seine Eingabe, ohne
				// zu erfahren, was sich geändert hat.
				return new JSONResponse(
					['error' => $e->getMessage(), 'current' => $e->current],
					Http::STATUS_CONFLICT,
				);
			} catch (AttachmentsPresentException $e) {
				// **409 wie beim Versionskonflikt und aus demselben Grund:** Die
				// Anfrage ist richtig gebaut und das Recht ist da — der Vorgang
				// ist nur gerade in einem Zustand, in dem sie nicht geht. Die
				// Zahl kommt mit, damit die Oberflaeche sie nicht aus der
				// Meldung fischen muss.
				return new JSONResponse(
					['error' => $e->getMessage(), 'attachments' => $e->count],
					Http::STATUS_CONFLICT,
				);
			} catch (NotOwningSideException $e) {
				// 403 und nicht 404: Der Betrachter sieht das Ticket, es steht
				// vor ihm. Zu verbergen gibt es nichts mehr.
				return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
			} catch (DoesNotExistException) {
				return new JSONResponse([], Http::STATUS_NOT_FOUND);
			} catch (\InvalidArgumentException $e) {
				return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
			}
		});
	}

	/**
	 * @param callable(ViewerContext): JSONResponse $run
	 */
	private function withViewer(int $boardId, callable $run): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$viewer = $this->access->contextFor($this->userId, $boardId);
		} catch (NotAMemberException) {
			// Dieselbe Antwort wie für ein Board, das es nicht gibt.
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		return $run($viewer);
	}
}
