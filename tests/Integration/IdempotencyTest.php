<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use DateTime;
use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Controller\CommentController;
use OCA\Projektwerk\Controller\StepController;
use OCA\Projektwerk\Controller\TicketController;
use OCA\Projektwerk\Db\IdemKey;
use OCA\Projektwerk\Db\IdemKeyMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketReadMapper;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\IdempotencyService;
use OCA\Projektwerk\Service\StepService;
use OCA\Projektwerk\Service\TicketReadModel;
use OCA\Projektwerk\Service\TicketService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Server;

class IdempotencyTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
	}

	public function testTheSameKeyCreatesOneTicketAndReturnsItTwice(): void {
		$first = $this->createTicket(LeakMatrixFixture::ANNA, 'key-ticket-0001', 'Einmal');
		$second = $this->createTicket(LeakMatrixFixture::ANNA, 'key-ticket-0001', 'Einmal');

		$this->assertSame(201, $first->getStatus());
		$this->assertSame(201, $second->getStatus());
		$this->assertSame($this->idOf($first), $this->idOf($second));
		$this->assertSame(1, $this->countTicketsTitled('Einmal'));
	}

	public function testWithoutAKeyEveryRequestCreatesAsBefore(): void {
		$this->createTicket(LeakMatrixFixture::ANNA, null, 'Ohne Kennung');
		$this->createTicket(LeakMatrixFixture::ANNA, null, 'Ohne Kennung');

		$this->assertSame(2, $this->countTicketsTitled('Ohne Kennung'));
	}

	public function testAnotherUsersKeyIsNeverReplayed(): void {
		$anna = $this->createTicket(LeakMatrixFixture::ANNA, 'key-shared-0001', 'Gleiche Kennung');
		$bert = $this->createTicket(LeakMatrixFixture::BERT, 'key-shared-0001', 'Gleiche Kennung');

		$this->assertSame(201, $bert->getStatus());
		$this->assertNotSame($this->idOf($anna), $this->idOf($bert));
		$this->assertSame(2, $this->countTicketsTitled('Gleiche Kennung'));
	}

	public function testReusingAKeyForDifferentContentIsRefused(): void {
		$this->createTicket(LeakMatrixFixture::ANNA, 'key-mismatch-01', 'Erster Inhalt');
		$other = $this->createTicket(LeakMatrixFixture::ANNA, 'key-mismatch-01', 'Anderer Inhalt');

		$this->assertSame(422, $other->getStatus());
		$this->assertSame(0, $this->countTicketsTitled('Anderer Inhalt'));
	}

	public function testAMalformedKeyIsRefused(): void {
		$response = $this->createTicket(LeakMatrixFixture::ANNA, 'kurz', 'Kaputte Kennung');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(0, $this->countTicketsTitled('Kaputte Kennung'));
	}

	public function testAFailedCreateReleasesTheKey(): void {
		$failed = $this->createTicket(LeakMatrixFixture::ANNA, 'key-failed-0001', 'Scheitert', 'gibt-es-nicht');
		$this->assertSame(400, $failed->getStatus());

		$retry = $this->createTicket(LeakMatrixFixture::ANNA, 'key-failed-0001', 'Klappt');
		$this->assertSame(201, $retry->getStatus());
		$this->assertSame(1, $this->countTicketsTitled('Klappt'));
	}

	public function testAFailureAfterWritingRollsBackSoTheRetryCreatesOnce(): void {
		$service = Server::get(IdempotencyService::class);
		$viewer = Server::get(BoardAccess::class)->contextFor(LeakMatrixFixture::ANNA, $this->fixture->boardId);
		$create = fn () => Server::get(TicketService::class)->create($viewer, 'Nach dem Schreiben gescheitert', null, 'internal', (int)$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A], null, null);

		try {
			$service->once(LeakMatrixFixture::ANNA, 'key-rollback-01', ['x'], function () use ($create) {
				$create();
				throw new \RuntimeException('Benachrichtigung gescheitert');
			});
			$this->fail('Der Fehler hätte durchgereicht werden müssen.');
		} catch (\RuntimeException) {
		}
		$this->assertSame(0, $this->countTicketsTitled('Nach dem Schreiben gescheitert'));

		$service->once(LeakMatrixFixture::ANNA, 'key-rollback-01', ['x'], $create);
		$this->assertSame(1, $this->countTicketsTitled('Nach dem Schreiben gescheitert'));
	}

	public function testARequestStillInProgressAnswersConflictAndCreatesNothing(): void {
		$this->reserve(LeakMatrixFixture::ANNA, 'key-progress-01', 'Läuft noch', new DateTime());

		$response = $this->createTicket(LeakMatrixFixture::ANNA, 'key-progress-01', 'Läuft noch');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(0, $this->countTicketsTitled('Läuft noch'));
	}

	public function testAnAbandonedReservationIsReleasedAfterTheStaleWindow(): void {
		$this->reserve(LeakMatrixFixture::ANNA, 'key-stale-00001', 'Hing', new DateTime('-1 hour'));

		$this->assertSame(409, $this->createTicket(LeakMatrixFixture::ANNA, 'key-stale-00001', 'Hing')->getStatus());
		$this->assertSame(201, $this->createTicket(LeakMatrixFixture::ANNA, 'key-stale-00001', 'Hing')->getStatus());
		$this->assertSame(1, $this->countTicketsTitled('Hing'));
	}

	public function testTheSameKeyCreatesOneStep(): void {
		$ticketId = $this->fixture->ticketIds['public/anna'];
		$controller = new StepController(
			$this->request('key-step-00001'),
			Server::get(StepService::class),
			Server::get(BoardAccess::class),
			Server::get(IdempotencyService::class),
			LeakMatrixFixture::ANNA,
		);

		$first = $controller->create($this->fixture->boardId, $ticketId, 'Schritt einmal');
		$second = $controller->create($this->fixture->boardId, $ticketId, 'Schritt einmal');

		$this->assertSame(201, $first->getStatus());
		$this->assertSame($this->idOf($first), $this->idOf($second));
		$this->assertSame(1, $this->countRows('pwerk_steps', 'title', 'Schritt einmal'));
	}

	public function testTheSameKeyCreatesOneComment(): void {
		$ticketId = $this->fixture->ticketIds['public/anna'];
		$controller = new CommentController(
			$this->request('key-comment-001'),
			Server::get(CommentService::class),
			Server::get(BoardAccess::class),
			Server::get(IdempotencyService::class),
			LeakMatrixFixture::ANNA,
		);

		$first = $controller->create($this->fixture->boardId, $ticketId, 'Kommentar einmal');
		$second = $controller->create($this->fixture->boardId, $ticketId, 'Kommentar einmal');

		$this->assertSame(201, $first->getStatus());
		$this->assertSame($this->idOf($first), $this->idOf($second));
		$this->assertSame(1, $this->countRows('pwerk_comments', 'body', 'Kommentar einmal'));
	}

	private function createTicket(string $userId, ?string $key, string $title, string $visibility = 'internal'): JSONResponse {
		$controller = new TicketController(
			$this->request($key),
			Server::get(TicketMapper::class),
			Server::get(TicketReadMapper::class),
			Server::get(TicketService::class),
			Server::get(TicketReadModel::class),
			Server::get(BoardAccess::class),
			Server::get(IdempotencyService::class),
			$userId,
		);

		return $controller->create(
			$this->fixture->boardId,
			$title,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
			$visibility,
		);
	}

	private function request(?string $key): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $name === IdempotencyService::HEADER ? ($key ?? '') : '',
		);

		return $request;
	}

	private function reserve(string $userId, string $key, string $title, DateTime $createdAt): void {
		$request = ['ticket', $this->fixture->boardId, $title, (int)$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A], 'internal', null, null, null];
		$row = new IdemKey();
		$row->setUserId($userId);
		$row->setIdemKey($key);
		$row->setFingerprint(hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)));
		$row->setCreatedAt($createdAt);
		Server::get(IdemKeyMapper::class)->insert($row);
	}

	private function idOf(JSONResponse $response): int {
		$data = $response->getData();

		return (int)(is_array($data) ? $data['id'] : $data->jsonSerialize()['id']);
	}

	private function countTicketsTitled(string $title): int {
		return $this->countRows('pwerk_tickets', 'title', $title);
	}

	private function countRows(string $table, string $column, string $value): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();

		return $n;
	}
}
