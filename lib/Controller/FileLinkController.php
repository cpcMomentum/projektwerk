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
use OCA\Projektwerk\Service\FileLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

class FileLinkController extends Controller {

	public function __construct(
		IRequest $request,
		private FileLinkService $service,
		private BoardAccess $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function create(int $boardId, int $ticketId, string $path = ''): JSONResponse {
		return $this->run($boardId, fn (ViewerContext $viewer): mixed
			=> $this->service->link($viewer, $ticketId, $path), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function destroy(int $boardId, int $linkId): JSONResponse {
		return $this->run($boardId, function (ViewerContext $viewer) use ($linkId): array {
			$this->service->delete($viewer, $linkId);

			return [];
		});
	}

	private function run(int $boardId, callable $write, int $status = Http::STATUS_OK): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($write($this->access->contextFor($this->userId, $boardId)), $status);
		} catch (NotAMemberException|DoesNotExistException) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		} catch (NotPermittedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
