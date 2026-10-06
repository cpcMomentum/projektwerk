<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Service\BoardPinService;
use OCA\Projektwerk\Service\BoardReadModel;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Die erste API-Naht der App.
 *
 * Duenn nach §3.5: Attribut, Kontext holen, lesen, `JSONResponse`. Es gibt hier
 * keine Berechtigungslogik — die steckt vollstaendig in {@see BoardAccess} und
 * den Mappern. Ein Controller, der selbst entscheidet, wer was sehen darf, waere
 * der zweite Ort, an dem die Regel stimmen muesste.
 *
 * **`#[NoAdminRequired]` an jeder Methode.** Ohne das Attribut verlangt
 * Nextcloud Administratorrechte, und die App waere fuer genau die Personen tot,
 * fuer die sie gebaut ist. Das Akzeptanzkriterium von Phase 2 verlangt den
 * Durchlauf mit einem Nicht-Admin-Konto ab dem ersten Endpunkt — dieser hier ist
 * der erste.
 *
 * **Kein `#[NoCSRFRequired]`.** Das gehoert laut §3.5 ausschliesslich an
 * `PageController::index` und die spaetere Deep-Link-Route.
 */
class BoardController extends Controller {

	public function __construct(
		IRequest $request,
		private BoardReadModel $readModel,
		private BoardAccess $access,
		private BoardPinService $pins,
		// Nextcloud reicht die Benutzerkennung der Sitzung unter genau diesem
		// Namen herein. Als Konstruktorwert statt ueber IUserSession, weil der
		// Controller damit ohne Sitzung baubar und in der Leak-Matrix je
		// Betrachter fahrbar ist.
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alle Projekte, in denen diese Person Mitglied ist.
	 *
	 * Keine Kontextpruefung davor, und das ist kein Versehen:
	 * `findAllForUser()` verbindet selbst auf `pwerk_members`. Ein
	 * Nichtmitglied bekommt eine leere Liste, kein Fehler — es gibt nichts zu
	 * verbergen, wo nichts ist.
	 */
	#[NoAdminRequired]
	public function index(bool $includeArchived = false): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->readModel->listFor($this->userId, $includeArchived));
	}

	/**
	 * Ein Projekt an- oder abpinnen (#115) — eine rein persönliche Einstellung.
	 *
	 * **Keine Sichtbarkeitsprüfung nötig.** Der Pin lebt im eigenen User-Value
	 * und wird nur dort angezeigt, wo {@see index()} das Board ohnehin ausliefert
	 * — die Schnittmenge fällt am Anzeigeort. Wer eine fremde ID pinnt, pinnt in
	 * sein eigenes Nichts: Sie taucht nie auf und räumt sich beim nächsten Laden
	 * von selbst nicht einmal auf, weil sie nie stört.
	 */
	#[NoAdminRequired]
	public function setPin(int $boardId, bool $pinned): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$this->pins->setPin($this->userId, $boardId, $pinned);

		return new JSONResponse(['pinned' => $pinned]);
	}

	/**
	 * Ein Projekt mit Mitgliedern und Spalten — die Grundlast der Boardansicht.
	 *
	 * **Nichtmitgliedschaft und ein nicht existierendes Board ergeben dieselbe
	 * Antwort: 404.** Ein 403 wuerde beantworten, was die Abfrage nicht
	 * beantwortet — naemlich dass es dieses Projekt gibt. `BoardAccess` haelt
	 * es aus demselben Grund schon in der Ausnahme so.
	 */
	#[NoAdminRequired]
	public function show(int $boardId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$viewer = $this->access->contextFor($this->userId, $boardId);

			return new JSONResponse($this->readModel->show($viewer));
		} catch (NotAMemberException|DoesNotExistException) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
	}
}
