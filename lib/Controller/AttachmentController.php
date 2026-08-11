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
use OCA\Projektwerk\Service\AttachmentService;
use OCA\Projektwerk\Service\NoFolderException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

/**
 * Anhaenge anhaengen und wieder loesen.
 *
 * **Kein Lesepfad**, wie beim {@see StepController} und beim
 * {@see CommentController}: Die Anhaenge kommen ueber `ticket#show` mit, aus
 * derselben gefilterten Ticketmenge (§5.8). Und **kein Auslieferungspfad**: Die
 * Datei holt der Browser bei Nextcloud, nicht bei uns. Ein eigener Downloadweg
 * waere ein zweiter Ort, an dem der Dateizugriff stimmen muesste — bei einer
 * App, deren ganze Zusage darauf beruht, dass es einen gibt.
 *
 * **Ratenbegrenzung am Anhaengen.** Niedriger als bei den Kommentaren: Ein
 * Anhang kostet Platz, nicht nur eine Zeile, und die Kundenseite hat den
 * Knopf genauso wie die eigene.
 */
class AttachmentController extends Controller {

	public function __construct(
		IRequest $request,
		private AttachmentService $service,
		private BoardAccess $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Eine hochgeladene Datei anhaengen.
	 *
	 * Der Inhalt kommt als `multipart/form-data` unter `file` — nicht als
	 * Base64 im JSON-Rumpf: Das waere ein Drittel mehr Daten und laege
	 * vollstaendig im Arbeitsspeicher, waehrend `tmp_name` ein Datenstrom
	 * bleibt.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function create(int $boardId, int $ticketId): JSONResponse {
		$datei = $this->request->getUploadedFile('file');

		if ($datei === null || ($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			// Ein abgebrochener oder zu grosser Upload landet hier ebenso wie ein
			// fehlendes Feld. Alle drei sind eine Frage an den Aufruf, nicht an
			// die Rechte — und die Meldung nennt den haeufigsten Grund.
			return new JSONResponse(
				['error' => 'Die Datei ist nicht vollständig angekommen. Möglicherweise ist sie zu groß.'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$strom = @fopen($datei['tmp_name'], 'rb');

		if ($strom === false) {
			return new JSONResponse(
				['error' => 'Die hochgeladene Datei ließ sich nicht lesen.'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			return $this->run(
				$boardId,
				fn (ViewerContext $viewer): mixed => $this->service->create(
					$viewer,
					$ticketId,
					(string)($datei['name'] ?? ''),
					$strom,
				),
				Http::STATUS_CREATED,
			);
		} finally {
			// `newFile` schliesst den Strom nicht zuverlaessig, und ein offener
			// Griff je Upload summiert sich auf einem Server, der lange laeuft.
			if (is_resource($strom)) {
				fclose($strom);
			}
		}
	}

	/**
	 * Die Verknuepfung loesen — **die Datei bleibt liegen** (§5.18).
	 */
	#[NoAdminRequired]
	public function destroy(int $boardId, int $attachmentId): JSONResponse {
		return $this->run($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->delete($viewer, $attachmentId));
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
			// Dieselbe Antwort fuer Nichtmitglied, unbekanntes Board, verborgenen
			// Vorgang und unbekannten Anhang.
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		} catch (NoFolderException|NotPermittedException $e) {
			// 400 und nicht 403: Es fehlt kein Recht — es gibt nur nichts, woran
			// der Anhang haengen koennte, oder der hinterlegte Ordner traegt
			// nicht mehr. Die Begruendung steht bei NoFolderException.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
