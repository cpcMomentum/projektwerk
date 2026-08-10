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
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\NotAuthorException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Kommentare schreiben.
 *
 * **Kein Lesepfad**, genau wie beim {@see StepController}: Die Kommentare
 * kommen ueber `ticket#show` mit, aus derselben gefilterten Ticketmenge (§5.8).
 * Ein eigener Leseweg waere der zweite Ort, an dem die Sichtbarkeitsregel
 * stimmen muesste.
 *
 * **Ratenbegrenzung am Anlegen** nach demselben Muster wie `ticket#create`: Ab
 * Phase 6 haengt der Mailversand daran, und das ist ein Versandhebel in
 * Kundenhand. Die Grenze steht hier, bevor es den Versand gibt, weil sie danach
 * leicht vergessen wird. Hoeher als beim Ticket, weil ein Gespraechsverlauf
 * naturgemaess mehr Zeilen hat als ein Vorgang.
 */
class CommentController extends Controller {

	public function __construct(
		IRequest $request,
		private CommentService $service,
		private BoardAccess $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function create(int $boardId, int $ticketId, string $body): JSONResponse {
		return $this->run(
			$boardId,
			fn (ViewerContext $viewer): mixed => $this->service->create($viewer, $ticketId, $body),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	public function update(int $boardId, int $commentId, string $body): JSONResponse {
		return $this->run($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->update($viewer, $commentId, $body));
	}

	#[NoAdminRequired]
	public function destroy(int $boardId, int $commentId): JSONResponse {
		return $this->run($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->delete($viewer, $commentId));
	}

	/**
	 * @param callable(ViewerContext): mixed $write
	 */
	private function run(int $boardId, callable $write, int $status = Http::STATUS_OK): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$viewer = $this->access->contextFor($this->userId, $boardId);

			return new JSONResponse($write($viewer), $status);
		} catch (NotAuthorException $e) {
			// 403 und nicht 404: Der Betrachter sieht den Kommentar, er steht vor
			// ihm. Zu verbergen gibt es nichts mehr.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (NotAMemberException|DoesNotExistException) {
			// Dieselbe Antwort fuer Nichtmitglied, unbekanntes Board, verborgenes
			// Ticket und unbekannten Kommentar.
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
