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
		?string $description = null,
	): JSONResponse {
		return $this->run(
			$boardId,
			fn (ViewerContext $viewer): mixed
				=> $this->service->create($viewer, $ticketId, $title, $assignedUserId, $dueDate, $description),
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
		?string $description = null,
		?string $result = null,
	): JSONResponse {
		// Nur das uebernehmen, was tatsaechlich geschickt wurde.
		$changes = [];
		foreach (['title' => $title, 'done' => $done] as $key => $value) {
			if ($value !== null) {
				$changes[$key] = $value;
			}
		}

		// **Zwei Felder, bei denen `null` etwas bedeutet**: „Zuweisung loeschen"
		// und „Frist loeschen". Ein Vergleich auf `!== null` verwirft genau
		// diesen Fall und laesst beides setzen, aber nie wieder entfernen.
		//
		// `getParam()` hilft nicht: Es prueft mit `isset()` und kann ein
		// ausdruecklich gesendetes `null` nicht von „nicht genannt"
		// unterscheiden (`isset()` ist bei `null` immer `false`).
		// `array_key_exists()` auf den rohen Parametern trennt beide Faelle.
		//
		// Die Zuweisung stand hier schon seit dem Review vom 2026-08-09; die
		// Faelligkeit ist am 2026-08-10 dazugekommen, als sie mit #86 zum ersten
		// Mal ueberhaupt aus der Oberflaeche heraus zu setzen war. Bis dahin fiel
		// nicht auf, dass sie sich nicht loeschen liess — es kam nie jemand
		// hin.
		// Beschreibung und Ergebnis gehören hierher, nicht in den `!== null`-Zweig
		// oben: Ein ausdrücklich gesendeter Leerstring **leert** das Feld, und
		// dieser Fall muss von „nicht genannt" unterscheidbar bleiben.
		foreach (['assignedUserId' => $assignedUserId, 'dueDate' => $dueDate, 'description' => $description, 'result' => $result] as $key => $value) {
			if (array_key_exists($key, $this->request->getParams())) {
				$changes[$key] = $value;
			}
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
	 * Wer an einem noch nicht angelegten Vorgang zustaendig sein duerfte (#146).
	 *
	 * Fuer den Verantwortlichen-Picker im Anlege-Dialog, bevor es eine Ticket-ID
	 * gibt. `visibility` kommt als Abfrageparameter, weil die zuweisbare Menge von
	 * der im Dialog gewaehlten Stufe abhaengt.
	 */
	#[NoAdminRequired]
	public function assignableForNew(int $boardId, string $visibility = 'public'): JSONResponse {
		return $this->run($boardId, fn (ViewerContext $viewer): mixed
			=> ['userIds' => $this->service->assignableForNew($viewer, $visibility)]);
	}

	/**
	 * Einen Arbeitsschritt löschen (#203). Wer den Vorgang bearbeiten darf,
	 * darf auch seine Schritte entfernen — dieselbe Grenze wie beim Ändern.
	 */
	#[NoAdminRequired]
	public function destroy(int $boardId, int $stepId): JSONResponse {
		return $this->run($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->delete($viewer, $stepId));
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
