<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Middleware;

use OCA\Projektwerk\Access\AllowedInArchive;
use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Sperrt jeden Schreibweg mit `{boardId}` in einem archivierten Board oder Projekt.
 *
 * Eine Stelle für alle Controller, damit ein neuer Schreibweg ohne Zutun gesperrt ist.
 */
class ArchiveMiddleware extends Middleware {

	private const READ_VERBS = ['GET', 'HEAD', 'OPTIONS'];

	public function __construct(
		private IRequest $request,
		private BoardAccess $access,
		private IL10N $l10n,
		private ?string $userId,
	) {
	}

	public function beforeController($controller, $methodName): void {
		if (in_array($this->request->getMethod(), self::READ_VERBS, true) || $this->userId === null) {
			return;
		}
		$boardId = $this->request->getParam('boardId');
		if ($boardId === null || !is_numeric($boardId)) {
			return;
		}
		if (self::allowedInArchive($controller, $methodName)) {
			return;
		}

		try {
			$viewer = $this->access->contextFor($this->userId, (int)$boardId);
		} catch (NotAMemberException) {
			// Der Controller antwortet mit seinem 404, sonst verriete die Sperre das Board.
			return;
		}

		if ($viewer->readOnly) {
			throw new ArchivedBoardException();
		}
	}

	public function afterException($controller, $methodName, \Exception $exception): Response {
		if ($exception instanceof ArchivedBoardException) {
			return new JSONResponse(
				['error' => $this->l10n->t('Dieses Board ist archiviert und kann nur gelesen werden.')],
				Http::STATUS_LOCKED,
			);
		}

		throw $exception;
	}

	public static function allowedInArchive(object|string $controller, string $methodName): bool {
		if (!method_exists($controller, $methodName)) {
			return false;
		}

		return (new \ReflectionMethod($controller, $methodName))->getAttributes(AllowedInArchive::class) !== [];
	}
}
