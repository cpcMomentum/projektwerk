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
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\ColumnService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\NotManagerException;
use OCA\Projektwerk\Service\NotOwnerException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

/**
 * Die Schreibwege der Board-Einstellungen: Projekt, Spalten, Mitglieder.
 *
 * Ein Controller für alle drei, weil sie dieselbe Sperre teilen und in der
 * Oberfläche auf **einer** Seite liegen (§9: Board-Einstellungen mit den
 * Abschnitten Mitglieder, Spalten, Dateiablage, Projektchat). Drei Controller
 * hätten dreimal denselben Rahmen getragen.
 *
 * **Kein Lese-Endpunkt.** Die Einstellungsseite liest über `board#show` — den
 * gibt es schon, er liefert Board, Mitglieder und Spalten in einem Zug und ist
 * in der Leak-Matrix registriert. Ein zweiter Lesepfad für dieselben Daten wäre
 * genau der sechste, gegen den die ganze Bauform gerichtet ist.
 */
class SettingsController extends Controller {

	public function __construct(
		IRequest $request,
		private BoardService $boardService,
		private ColumnService $columnService,
		private MemberService $memberService,
		private BoardAccess $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Ein neues Projekt. Ohne Board-Kontext — den gibt es vorher noch nicht.
	 */
	#[NoAdminRequired]
	public function createBoard(
		string $title,
		?string $description = null,
		?string $orgInternal = null,
		?string $orgExternal = null,
	): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(
				$this->boardService->create($this->userId, $title, $description, $orgInternal, $orgExternal),
				Http::STATUS_CREATED,
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function updateBoard(
		int $boardId,
		?string $title = null,
		?string $description = null,
		?string $orgInternal = null,
		?string $orgExternal = null,
		?string $chatUrl = null,
		?string $folderPublicPath = null,
		?string $folderInternalPath = null,
	): JSONResponse {
		// **Die beiden Ordner kommen als Pfad, gespeichert wird die Datei-ID.**
		// Der Pfad benennt den Ordner nur; was in der Datenbank landet, loest
		// {@see BoardService::update()} daraus auf (§5.18). Der leere String
		// entfernt die Zuordnung — `onlyGiven` unterscheidet „nicht
		// mitgeschickt" allein am `null`, ein ausdrueckliches „auf nichts
		// setzen" waere damit sonst nicht ausdrueckbar.
		$changes = $this->onlyGiven([
			'title' => $title,
			'description' => $description,
			'orgInternal' => $orgInternal,
			'orgExternal' => $orgExternal,
			'chatUrl' => $chatUrl,
			'folderPublicPath' => $folderPublicPath,
			'folderInternalPath' => $folderInternalPath,
		]);

		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->boardService->update($viewer, $changes));
	}

	#[NoAdminRequired]
	public function archiveBoard(int $boardId, bool $archived): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->boardService->setArchived($viewer, $archived));
	}

	#[NoAdminRequired]
	public function createColumn(int $boardId, string $title, ?string $color = null): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->columnService->create($viewer, $title, $color), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function renameColumn(int $boardId, int $columnId, string $title): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->columnService->rename($viewer, $columnId, $title));
	}

	/**
	 * @param int[] $columnIds alle Spalten des Boards in Sollreihenfolge
	 */
	#[NoAdminRequired]
	public function reorderColumns(int $boardId, array $columnIds): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->columnService->reorder($viewer, $columnIds));
	}

	/**
	 * Eine Spalte entfernen — die Zielspalte ist **Pflicht**, nicht optional.
	 *
	 * Ohne Vorbelegung und ohne stillen Rückfall auf „irgendeine andere": Wohin
	 * die Vorgänge wandern, ist eine Entscheidung, und eine geratene Antwort
	 * verschöbe fremde Arbeit an einen Ort, den niemand gewählt hat.
	 *
	 * Der Parameter steht im Rumpf, nicht in der Adresse — Nextclouds Request
	 * decodiert einen JSON-Rumpf auch bei DELETE.
	 *
	 * Antwortet mit 204: Eine Anzahl zurückzugeben wäre eine Auskunft über die
	 * ungefilterte Menge.
	 */
	#[NoAdminRequired]
	public function deleteColumn(int $boardId, int $columnId, int $targetColumnId): JSONResponse {
		return $this->write($boardId, function (ViewerContext $viewer) use ($columnId, $targetColumnId): mixed {
			$this->columnService->delete($viewer, $columnId, $targetColumnId);

			return null;
		}, Http::STATUS_NO_CONTENT);
	}

	#[NoAdminRequired]
	public function addMember(
		int $boardId,
		string $userId,
		string $role,
		bool $isManager = false,
		?string $displayName = null,
	): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->memberService->add($viewer, $userId, $role, $isManager, $displayName), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateMember(
		int $boardId,
		string $userId,
		?string $role = null,
		?bool $isManager = null,
		?string $displayName = null,
	): JSONResponse {
		$changes = $this->onlyGiven([
			'role' => $role,
			'isManager' => $isManager,
			'displayName' => $displayName,
		]);

		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> $this->memberService->update($viewer, $userId, $changes));
	}

	/**
	 * Wie viele private Vorgänge das Entfernen dieses Mitglieds löschen würde
	 * (§5.29) — für die bezifferte Rückfrage vor dem Entfernen.
	 */
	#[NoAdminRequired]
	public function memberRemovalImpact(int $boardId, string $userId): JSONResponse {
		return $this->write($boardId, fn (ViewerContext $viewer): mixed
			=> ['privateTickets' => $this->memberService->removalImpact($viewer, $userId)]);
	}

	/**
	 * Ein Mitglied aus dem Projekt entfernen (§5.29). Antwortet mit 204.
	 *
	 * Die Kennung steht in der Adresse, kein Rumpf nötig. Der Eigentümer lässt
	 * sich nicht entfernen — der Dienst weist das mit 400 ab.
	 */
	#[NoAdminRequired]
	public function removeMember(int $boardId, string $userId): JSONResponse {
		return $this->write($boardId, function (ViewerContext $viewer) use ($userId): mixed {
			$this->memberService->remove($viewer, $userId);

			return null;
		}, Http::STATUS_NO_CONTENT);
	}

	/**
	 * Nur das übernehmen, was tatsächlich geschickt wurde — ein nicht genanntes
	 * Feld darf nicht auf null zurückfallen.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function onlyGiven(array $fields): array {
		return array_filter($fields, static fn ($value): bool => $value !== null);
	}

	/**
	 * @param callable(ViewerContext): mixed $write
	 */
	private function write(int $boardId, callable $write, int $status = Http::STATUS_OK): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$viewer = $this->access->contextFor($this->userId, $boardId);
		} catch (NotAMemberException) {
			// Dieselbe Antwort wie für ein Board, das es nicht gibt.
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse($write($viewer), $status);
		} catch (NotManagerException | NotOwnerException $e) {
			// 403, nicht 404: Der Betrachter ist Mitglied und sieht das Board.
			// Zu verbergen gibt es nichts mehr — nur zu erklären. Zwei
			// Ausnahmen mit einer Antwort, aber getrennten Sätzen: Wer eine
			// Spalte entfernen will, hat das Verwaltungsrecht womöglich.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException | NotPermittedException $e) {
			// **`NotPermittedException` ist hier 400 und nicht 403.** Sie sagt
			// nichts über die Rechte am Board — die stehen längst fest, sonst
			// wäre oben schon Schluss gewesen —, sondern über den *Wert*: Der
			// eingetragene Ordner ist keiner, ist unerreichbar oder nicht
			// beschreibbar. Ein 403 läse sich als „Sie dürfen die Einstellungen
			// nicht ändern", und genau das stimmt nicht.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
