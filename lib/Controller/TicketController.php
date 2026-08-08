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
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
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
		private TicketService $service,
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

			return new JSONResponse([
				'tickets' => $tickets,
				'counts' => [
					'comments' => $this->comments->countForTickets($ids),
					'steps' => $this->steps->countForTickets($ids),
					'attachments' => $this->attachments->countForTickets($ids),
					'collaborators' => $this->ticketUsers->countForTickets($ids),
				],
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

			return new JSONResponse([
				'ticket' => $ticket,
				'comments' => $this->comments->findForTickets($ids),
				'steps' => $this->steps->findForTickets($ids),
				'attachments' => $this->attachments->findForTickets($ids),
				'collaborators' => $this->ticketUsers->findForTickets($ids),
			]);
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
	): JSONResponse {
		return $this->withViewer($boardId, function (ViewerContext $viewer) use ($title, $columnId, $visibility, $description, $responsibleUserId): JSONResponse {
			try {
				return new JSONResponse(
					$this->service->create($viewer, $title, $description, $visibility, $columnId, $responsibleUserId),
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
		?bool $closed = null,
	): JSONResponse {
		// Nur das übernehmen, was tatsächlich geschickt wurde: Ein
		// nicht genanntes Feld darf nicht auf null zurückfallen.
		$changes = array_filter(
			[
				'title' => $title,
				'description' => $description,
				'responsibleUserId' => $responsibleUserId,
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
	 */
	#[NoAdminRequired]
	public function visibility(int $boardId, int $ticketId, int $version, string $visibility): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->changeVisibility($viewer, $ticketId, $version, $visibility));
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
