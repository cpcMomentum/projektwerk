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
use OCA\Projektwerk\Service\StepService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Arbeitsschritte schreiben.
 *
 * **Kein Lesepfad.** Die Schritte kommen ueber `ticket#show` und
 * `ticket#index` mit, aus derselben gefilterten Ticketmenge (§5.8). Ein
 * eigener Leseweg waere der zweite Ort, an dem die Regel stimmen muesste.
 *
 * Ausnahme mit Grund: `assignable` liest — aber es liefert keine Schritte,
 * sondern beantwortet „wem darf ich hier etwas geben". Die Frage entsteht aus
 * der Sichtbarkeitsregel und gehoert deshalb auf den Server; sie steht in der
 * Leak-Matrix wie jeder andere Lesepfad.
 */
class StepController extends Controller {

	public function __construct(
		IRequest $request,
		private StepService $service,
		private BoardAccess $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function create(
		int $boardId,
		int $ticketId,
		string $title,
		?string $assignedUserId = null,
		?string $dueDate = null,
	): JSONResponse {
		return $this->run(
			$boardId,
			fn (ViewerContext $viewer): mixed
				=> $this->service->create($viewer, $ticketId, $title, $assignedUserId, $dueDate),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	public function update(
		int $boardId,
		int $stepId,
		?string $title = null,
		?string $assignedUserId = null,
		?string $dueDate = null,
		?bool $done = null,
	): JSONResponse {
		// Nur das uebernehmen, was tatsaechlich geschickt wurde. Bei
		// `assignedUserId` ist das wesentlich: `null` heisst hier „Zuweisung
		// loeschen" und darf nicht mit „nicht genannt" zusammenfallen.
		$changes = [];
		foreach (['title' => $title, 'dueDate' => $dueDate, 'done' => $done] as $key => $value) {
			if ($value !== null) {
				$changes[$key] = $value;
			}
		}
		// `getParam()` prueft mit `isset()` und kann ein explizit gesendetes
		// `null` deshalb nicht von „nicht genannt" unterscheiden (`isset()`
		// ist bei `null`-Werten `false`). `array_key_exists()` auf den rohen
		// Parametern unterscheidet beide Faelle korrekt.
		if (array_key_exists('assignedUserId', $this->request->getParams())) {
			$changes['assignedUserId'] = $assignedUserId;
		}

		return $this->run($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->update($viewer, $stepId, $changes));
	}

	/**
	 * Wem an diesem Ticket ein Schritt gegeben werden darf.
	 */
	#[NoAdminRequired]
	public function assignable(int $boardId, int $ticketId): JSONResponse {
		return $this->run($boardId, fn (ViewerContext $viewer): mixed
			=> ['userIds' => $this->service->assignableFor($viewer, $ticketId)]);
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
		} catch (NotAMemberException|DoesNotExistException) {
			// Dieselbe Antwort fuer Nichtmitglied, unbekanntes Board,
			// verborgenes Ticket und unbekannten Schritt.
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
