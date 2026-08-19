<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Service\GithubService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Der eigene GitHub-Token für die Überführung (#12, Stufe 1).
 *
 * **Kein Board im Pfad, und das ist richtig** — wie bei den Kanalschaltern
 * ({@see NotifyPrefController}) und dem privaten Anhang-Ordner
 * ({@see PrivateFolderController}). Der Token gehört der Person, nicht einem
 * Projekt; die Grenze ist ihre Kennung aus der Sitzung.
 *
 * **Der Token verlässt diesen Endpunkt nie.** Erfragbar ist allein, *ob* einer
 * hinterlegt ist — {@see GithubService} gibt ihn nach außen grundsätzlich nicht
 * heraus. Deshalb liefert {@see index()} nur `present`, nie den Wert.
 */
class GithubTokenController extends Controller {

	public function __construct(
		IRequest $request,
		private GithubService $github,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Ob die angemeldete Person einen Token hinterlegt hat — nie der Token selbst.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['present' => $this->github->hasToken($this->userId)]);
	}

	/**
	 * Einen Token hinterlegen (oder ersetzen). Ein leerer Token wird abgewiesen
	 * — zum Entfernen dient {@see destroy()}.
	 *
	 * @param string $token Der persönliche GitHub-Token (fine-grained PAT).
	 */
	#[NoAdminRequired]
	public function update(string $token): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->github->storeToken($this->userId, $token);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['present' => true]);
	}

	/**
	 * Den Token wieder entfernen. Idempotent: Ist keiner da, geschieht nichts.
	 */
	#[NoAdminRequired]
	public function destroy(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$this->github->deleteToken($this->userId);

		return new JSONResponse(['present' => false]);
	}
}
