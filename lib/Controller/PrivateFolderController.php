<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Service\ProjectFolderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

/**
 * Der eigene Ordner für private Anhänge (#184, Phase B).
 *
 * **Kein Board im Pfad, und das ist richtig** — wie bei den Kanalschaltern
 * ({@see NotifyPrefController}). Es geht um die Einstellung einer Person, nicht
 * um Projektdaten; die Grenze ist ihre Kennung aus der Sitzung. Ein privater
 * Anhang landet ohne Wahl im Vorgabe-Ordner ({@see ProjectFolderService::DEFAULT_PRIVATE_FOLDER});
 * hier lässt er sich auf einen anderen legen.
 */
class PrivateFolderController extends Controller {

	public function __construct(
		IRequest $request,
		private ProjectFolderService $folders,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Der eingestellte (oder vorgegebene) Ordnerpfad.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['path' => $this->folders->privatePath($this->userId)]);
	}

	/**
	 * Einen anderen Ordner wählen. Der Pfad wird sofort geprüft (und bei Bedarf
	 * angelegt); ein unmöglicher oder unbeschreibbarer Ordner fällt hier auf.
	 *
	 * @param string $path Der Ordnerpfad im eigenen Dateibaum.
	 */
	#[NoAdminRequired]
	public function update(string $path): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->folders->setPrivatePath($this->userId, $path);
		} catch (NotPermittedException $e) {
			// 400: Es fehlt kein Recht — der gewählte Ordner geht nur nicht.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['path' => $this->folders->privatePath($this->userId)]);
	}
}
