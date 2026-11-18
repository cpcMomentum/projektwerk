<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Controller;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Controller\SettingsController;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\ColumnService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\ProjectLogService;
use OCA\Projektwerk\Service\WerkPlusLimitException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SettingsControllerLimitTest extends TestCase {

	public function testALimitOfTheFreeScopeIsMarkedForTheWerkPlusLink(): void {
		$boards = $this->createStub(BoardService::class);
		$boards->method('create')->willThrowException(new WerkPlusLimitException('Im freien Umfang sind 10 aktive Projekte enthalten. WerkPlus schaltet weitere frei.'));
		$controller = new SettingsController(
			$this->createStub(IRequest::class),
			$boards,
			$this->createStub(ColumnService::class),
			$this->createStub(MemberService::class),
			$this->createStub(ProjectLogService::class),
			$this->createStub(BoardAccess::class),
			'alice',
		);

		$response = $controller->createBoard('Elftes Projekt');

		$this->assertSame(Http::STATUS_PAYMENT_REQUIRED, $response->getStatus());
		$this->assertSame([
			'error' => 'Im freien Umfang sind 10 aktive Projekte enthalten. WerkPlus schaltet weitere frei.',
			'werkplusLimit' => true,
		], $response->getData());
	}
}
