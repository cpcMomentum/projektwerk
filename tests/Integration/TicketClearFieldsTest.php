<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Controller\TicketController;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketReadMapper;
use OCA\Projektwerk\Service\IdempotencyService;
use OCA\Projektwerk\Service\TicketDependencyService;
use OCA\Projektwerk\Service\TicketReadModel;
use OCA\Projektwerk\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Server;

class TicketClearFieldsTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
	}

	public function testAnExplicitNullClearsResponsibleAndDescription(): void {
		$ticket = $this->prepared();

		$cleared = $this->update($ticket, ['responsibleUserId' => null, 'description' => null]);

		$this->assertNull($cleared->getResponsibleUserId(), 'Der Zuständige muss sich über den Controller entfernen lassen.');
		$this->assertNull($cleared->getDescription(), 'Die Beschreibung ebenso.');
	}

	public function testAFieldThatWasNotSentStaysAsItWas(): void {
		$ticket = $this->prepared();

		$unchanged = $this->update($ticket, ['title' => 'Nur der Titel']);

		$this->assertSame('Nur der Titel', $unchanged->getTitle());
		$this->assertSame(LeakMatrixFixture::BERT, $unchanged->getResponsibleUserId());
		$this->assertSame('Beschreibung bleibt', $unchanged->getDescription());
	}

	private function prepared(): Ticket {
		$viewer = Server::get(BoardAccess::class)->contextFor(LeakMatrixFixture::ANNA, $this->fixture->boardId);

		return Server::get(TicketService::class)->create(
			$viewer,
			'Mit Zuständigem',
			'Beschreibung bleibt',
			'internal',
			(int)$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
			LeakMatrixFixture::BERT,
			null,
		);
	}

	/**
	 * @param array<string, mixed> $params Was der Browser schickt, `null` ausdrücklich genannt.
	 */
	private function update(Ticket $ticket, array $params): Ticket {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn($params + ['version' => $ticket->getVersion()]);

		$controller = new TicketController(
			$request,
			Server::get(TicketMapper::class),
			Server::get(TicketReadMapper::class),
			Server::get(TicketService::class),
			Server::get(TicketReadModel::class),
			Server::get(BoardAccess::class),
			Server::get(IdempotencyService::class),
			Server::get(TicketDependencyService::class),
			LeakMatrixFixture::ANNA,
		);

		$response = $controller->update(
			$this->fixture->boardId,
			(int)$ticket->getId(),
			(int)$ticket->getVersion(),
			$params['title'] ?? null,
			$params['description'] ?? null,
			$params['responsibleUserId'] ?? null,
		);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		return Server::get(TicketMapper::class)->findVisible(
			Server::get(BoardAccess::class)->contextFor(LeakMatrixFixture::ANNA, $this->fixture->boardId),
			(int)$ticket->getId(),
		);
	}
}
