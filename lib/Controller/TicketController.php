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
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketReadMapper;
use OCA\Projektwerk\Service\ConflictException;
use OCA\Projektwerk\Service\GithubTransferException;
use OCA\Projektwerk\Service\NoFolderException;
use OCA\Projektwerk\Service\NotOwningSideException;
use OCA\Projektwerk\Service\TicketReadModel;
use OCA\Projektwerk\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
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
		private TicketReadMapper $reads,
		private TicketService $service,
		private TicketReadModel $readModel,
		private BoardAccess $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Die sichtbaren Tickets eines Boards, mit den Zählern ihrer Kinder.
	 */
	#[NoAdminRequired]
	public function index(int $boardId, ?int $columnId = null): JSONResponse {
		return $this->withViewer($boardId, fn (ViewerContext $viewer): JSONResponse
			=> new JSONResponse($this->readModel->index($viewer, $columnId)));
	}

	/**
	 * Ein Ticket mit seinen Kindern.
	 */
	#[NoAdminRequired]
	public function show(int $boardId, int $ticketId): JSONResponse {
		return $this->withViewer($boardId, function (ViewerContext $viewer) use ($ticketId): JSONResponse {
			try {
				return new JSONResponse($this->readModel->show($viewer, $ticketId));
			} catch (DoesNotExistException) {
				return new JSONResponse([], Http::STATUS_NOT_FOUND);
			}
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
		?string $outcome = null,
	): JSONResponse {
		// Nur das übernehmen, was tatsächlich geschickt wurde: Ein
		// nicht genanntes Feld darf nicht auf null zurückfallen. Das Loeschen
		// einer Faelligkeit reist deshalb als Leerstring, nicht als `null` — der
		// waere hier nicht von „nicht geschickt" zu unterscheiden.
		//
		// `outcome` (#171) begleitet `closed: true`; beim Wieder-oeffnen bleibt
		// es weg und der Dienst loescht das Ergebnis ohnehin.
		$changes = array_filter(
			[
				'title' => $title,
				'description' => $description,
				'responsibleUserId' => $responsibleUserId,
				'dueDate' => $dueDate,
				'closed' => $closed,
				'outcome' => $outcome,
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
	 * Einen Vorgang nach GitHub überführen (#12, Stufe 1) — einseitig, einmalig.
	 *
	 * Legt ein Issue im am Board hinterlegten Repository an und speichert Nummer
	 * und Adresse am Vorgang. Ohne `version`: Die Überführung ist keine
	 * konkurrierende Feldänderung; gegen ein zweites Issue schützt die bereits
	 * gesetzte Nummer (409 mit dem aktuellen Stand), nicht der Versionsvergleich.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function transferToGithub(int $boardId, int $ticketId): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->transferToGithub($viewer, $ticketId));
	}

	/**
	 * Einen Vorgang loeschen — weich, und ohne Papierkorb in der App.
	 *
	 * Wiederhergestellt wird per `occ projektwerk:ticket:restore`. Der
	 * Rueckgabewert ist der geloeschte Stand; das Frontend nimmt die Karte
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
			} catch (NoFolderException | NotPermittedException $e) {
				// **400 und nicht 409**, wie im AttachmentController: Es fehlt kein
				// Recht und die Anfrage ist richtig gebaut — beim
				// Sichtbarkeitswechsel (#185) hat die Ziel-Sichtbarkeit für die
				// vorhandenen Anhänge nur keinen Ablageort (Kundenseite nach
				// intern; seit #184 Phase B nicht mehr nach `private`), oder der
				// hinterlegte Ordner trägt nicht mehr. **Nicht 409**, weil das
				// Frontend jedes 409 als Versionskonflikt liest („bitte neu
				// laden") — hier soll aber die Servermeldung sprechen, die sagt,
				// was zu tun ist.
				return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
			} catch (GithubTransferException $e) {
				// **400 wie NoFolderException:** Es fehlt kein Recht und die
				// Anfrage ist richtig gebaut — es hakt an der Einrichtung (kein
				// Token, falsches Repo) oder an GitHub selbst. Die Meldung ist
				// schon kundentauglich formuliert und soll unverändert sprechen;
				// ein 409 läse das Frontend als Versionskonflikt.
				return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
