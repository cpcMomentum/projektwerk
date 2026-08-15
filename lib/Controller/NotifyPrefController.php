<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Service\NotifyPrefService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Die eigenen Kanalschalter.
 *
 * **Kein Board im Pfad, und das ist richtig.** Jeder andere Endpunkt dieser App
 * beginnt mit einem Projekt, weil er Projektdaten liefert. Hier geht es um die
 * Einstellungen einer Person — die Grenze ist ihre Kennung, und die kommt aus
 * der Sitzung. Ein `boardId` im Pfad taeuschte eine Rechtepruefung vor, die es
 * hier nicht braucht und nicht gibt.
 */
class NotifyPrefController extends Controller {

	public function __construct(
		IRequest $request,
		private NotifyPrefService $service,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Der gespeicherte Stand — nicht der aufgeloeste.
	 *
	 * Die Oberflaeche muss „hier steht ausdruecklich aus" von „hier steht
	 * nichts, also gilt global" unterscheiden koennen.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->alsObjekte($this->service->forUser($this->userId)));
	}

	/**
	 * @param string $prefKey Einer der Kanaele oder Anlaesse.
	 * @param bool $enabled Neuer Stand.
	 * @param int $boardId Projekt, oder 0 fuer global.
	 */
	#[NoAdminRequired]
	public function update(string $prefKey, bool $enabled, int $boardId = 0): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->service->set($this->userId, $prefKey, $boardId, $enabled);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->alsObjekte($this->service->forUser($this->userId)));
	}

	/**
	 * Alle Projekt-Ausnahmen wegraeumen — danach gilt ueberall die globale
	 * Einstellung.
	 */
	#[NoAdminRequired]
	public function clearOverrides(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$this->service->clearBoardOverrides($this->userId);

		return new JSONResponse($this->alsObjekte($this->service->forUser($this->userId)));
	}

	/**
	 * Beide Abbildungen als **Objekte** ausliefern, auch wenn sie leer sind.
	 *
	 * `json_encode()` macht aus einem leeren PHP-Array `[]` und aus einem
	 * gefuellten mit Schluesseln `{...}`. Die Antwort haette damit je nach
	 * Inhalt eine andere Form — ein Aufrufer, der `boards` als Abbildung
	 * behandelt, bekaeme beim ersten Aufruf ein Array. Heute geht das gut, weil
	 * `Object.keys([])` ebenfalls leer ist; es geht gut aus Zufall, und der
	 * haelt nicht.
	 *
	 * @param array{global: array<string, bool>, boards: array<int, array<string, bool>>} $stand
	 * @return array{global: object, boards: object}
	 */
	private function alsObjekte(array $stand): array {
		return [
			'global' => (object)$stand['global'],
			'boards' => (object)array_map(
				static fn (array $kanaele): object => (object)$kanaele,
				$stand['boards'],
			),
		];
	}
}
