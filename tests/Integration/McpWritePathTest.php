<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\Tool;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Mcp\Tools\AddAttachment;
use OCA\Projektwerk\Mcp\Tools\AddComment;
use OCA\Projektwerk\Mcp\Tools\AddStep;
use OCA\Projektwerk\Mcp\Tools\AttachFile;
use OCA\Projektwerk\Mcp\Tools\CloseTicket;
use OCA\Projektwerk\Mcp\Tools\CreateTicket;
use OCA\Projektwerk\Mcp\Tools\MoveTicket;
use OCA\Projektwerk\Mcp\Tools\TransferToGithub;
use OCA\Projektwerk\Mcp\Tools\UpdateStep;
use OCA\Projektwerk\Mcp\Tools\UpdateTicket;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Die Schreibwerkzeuge des MCP: Rückfrage bei Kundensichtbarem, Herkunft, dieselben Rechte wie REST.
 */
class McpWritePathTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
		Server::get(McpConfig::class)->setPublicWriteAllowed(true);
	}

	protected function tearDown(): void {
		Server::get(IAppConfig::class)->deleteKey('projektwerk', McpConfig::KEY_PUBLIC_WRITE);
		parent::tearDown();
	}

	/** @param array<string, mixed> $extra */
	#[\PHPUnit\Framework\Attributes\DataProvider('writesOnAnExistingTicket')]
	public function testWithTheSwitchOffACustomerVisibleTicketIsRefusedEvenWhenConfirmed(string $class, array $extra): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(false);
		$ticketId = $this->fixture->ticketIds['public/anna'];
		if ($class === MoveTicket::class) {
			$extra['target_column_id'] = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_B];
		}
		$before = $this->snapshot($ticketId);

		$result = $this->call($class, LeakMatrixFixture::ANNA, ['ticket_id' => $ticketId, 'confirm_public' => true] + $extra);

		$this->assertTrue($result->isError());
		$this->assertSame($before, $this->snapshot($ticketId));
	}

	public function testWithTheSwitchOffNoCustomerVisibleTicketIsCreatedButInternalWritesWork(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(false);

		$refused = $this->call(CreateTicket::class, LeakMatrixFixture::ANNA, [
			'column_id' => $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
			'title' => 'Von der KI',
			'visibility' => 'public',
			'confirm_public' => true,
		]);
		$this->assertTrue($refused->isError());
		$this->assertStringContainsString('switched off', $this->text($refused));

		$intern = $this->call(AddComment::class, LeakMatrixFixture::ANNA, [
			'ticket_id' => $this->fixture->ticketIds['internal/anna'],
			'body' => 'Notiz',
		]);
		$this->assertFalse($intern->isError(), $this->text($intern));
	}

	/**
	 * @return iterable<string, array{0: class-string<Tool>, 1: array<string, mixed>}>
	 */
	public static function writesOnAnExistingTicket(): iterable {
		yield 'update_ticket' => [UpdateTicket::class, ['title' => 'neu']];
		yield 'move_ticket' => [MoveTicket::class, ['target_column_id' => -1]];
		yield 'close_ticket' => [CloseTicket::class, ['outcome' => 'done']];
		yield 'add_step' => [AddStep::class, ['title' => 'Schritt']];
		yield 'update_step' => [UpdateStep::class, ['step_id' => 1, 'done' => true]];
		yield 'add_comment' => [AddComment::class, ['body' => 'Hallo Kunde']];
		yield 'add_attachment' => [AddAttachment::class, ['file_name' => 'a.txt', 'content' => 'x', 'encoding' => 'text']];
		yield 'attach_file' => [AttachFile::class, ['path' => 'nicht/da.txt']];
		yield 'transfer_to_github' => [TransferToGithub::class, []];
	}

	/** @param array<string, mixed> $extra */
	#[\PHPUnit\Framework\Attributes\DataProvider('writesOnAnExistingTicket')]
	public function testACustomerVisibleTicketIsRefusedWithoutConfirmationAndStaysUnchanged(string $class, array $extra): void {
		$ticketId = $this->fixture->ticketIds['public/anna'];
		if ($class === MoveTicket::class) {
			$extra['target_column_id'] = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_B];
		}
		$before = $this->snapshot($ticketId);

		$result = $this->call($class, LeakMatrixFixture::ANNA, ['ticket_id' => $ticketId] + $extra);

		$this->assertTrue($result->isError());
		$this->assertStringContainsString('customer-visible', $this->text($result));
		$this->assertSame($before, $this->snapshot($ticketId), 'Ohne Bestätigung ändert sich nichts.');
	}

	/**
	 * `attach_file` liest aus dem ganzen Dateibaum der Person. An einen
	 * kundensichtbaren Vorgang kopiert es deshalb nie — auch nicht mit der
	 * Bestätigung, die das Modell selbst setzt.
	 */
	public function testAttachFileIsRefusedOnACustomerVisibleTicketEvenWhenConfirmed(): void {
		$ticketId = $this->fixture->ticketIds['public/anna'];
		$before = $this->snapshot($ticketId);

		$result = $this->call(AttachFile::class, LeakMatrixFixture::ANNA, [
			'ticket_id' => $ticketId,
			'path' => 'nicht/da.txt',
			'confirm_public' => true,
		]);

		$this->assertTrue($result->isError());
		$this->assertStringContainsString('attach_file is not available', $this->text($result));
		$this->assertSame($before, $this->snapshot($ticketId));
	}

	/**
	 * An einem internen Vorgang greift die Ablehnung nicht: Sie gilt der
	 * Sichtbarkeit, nicht dem Werkzeug. Das Fixture-Konto hat keinen Dateibaum,
	 * der Aufruf endet deshalb erst bei der Dateisuche — mit einer Fehlermeldung
	 * oder einer Ausnahme, je nach Plattform.
	 */
	public function testAttachFileIsNotRefusedOnAnInternalTicket(): void {
		try {
			$text = $this->text($this->call(AttachFile::class, LeakMatrixFixture::ANNA, [
				'ticket_id' => $this->fixture->ticketIds['internal/anna'],
				'path' => 'nicht/da.txt',
			]));
		} catch (\Throwable $e) {
			$text = $e->getMessage();
		}

		$this->assertStringNotContainsString('attach_file is not available', $text);
		$this->assertStringNotContainsString('customer-visible', $text);
	}

	public function testCreatingACustomerVisibleTicketNeedsConfirmation(): void {
		$args = [
			'column_id' => $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
			'title' => 'Von der KI',
			'visibility' => 'public',
		];

		$refused = $this->call(CreateTicket::class, LeakMatrixFixture::ANNA, $args);
		$this->assertTrue($refused->isError());
		$this->assertStringContainsString('customer-visible', $this->text($refused));

		$created = $this->call(CreateTicket::class, LeakMatrixFixture::ANNA, $args + ['confirm_public' => true]);
		$this->assertFalse($created->isError(), $this->text($created));
		$this->assertSame(ViewerContext::VIA_TOOL, $this->ticket($created->structured()['ticket']['id'])->getCreatedVia());
	}

	public function testAnInternalTicketNeedsNoConfirmationAndSetsResponsibleAndDueDate(): void {
		$created = $this->call(CreateTicket::class, LeakMatrixFixture::ANNA, [
			'column_id' => $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
			'title' => 'Intern von der KI',
			'visibility' => 'internal',
			'responsible_user_id' => LeakMatrixFixture::BERT,
			'due_date' => '2026-12-01',
		]);

		$this->assertFalse($created->isError(), $this->text($created));
		$ticket = $this->ticket($created->structured()['ticket']['id']);
		$this->assertSame(LeakMatrixFixture::BERT, $ticket->getResponsibleUserId());
		$this->assertSame('2026-12-01', $ticket->getDueDate()?->format('Y-m-d'));

		$cleared = $this->call(UpdateTicket::class, LeakMatrixFixture::ANNA, [
			'ticket_id' => (int)$ticket->getId(),
			'responsible_user_id' => '',
			'due_date' => '',
		]);
		$this->assertFalse($cleared->isError(), $this->text($cleared));
		$ticket = $this->ticket((int)$ticket->getId());
		$this->assertNull($ticket->getResponsibleUserId());
		$this->assertNull($ticket->getDueDate());
	}

	public function testResponsibleMustBeAbleToSeeTheTicket(): void {
		$result = $this->call(UpdateTicket::class, LeakMatrixFixture::ANNA, [
			'ticket_id' => $this->fixture->ticketIds['internal/anna'],
			'responsible_user_id' => LeakMatrixFixture::CARLA,
		]);

		$this->assertTrue($result->isError(), 'Die Kundin sieht Annas internen Vorgang nicht und darf ihn nicht bekommen.');
		$this->assertNotSame(LeakMatrixFixture::CARLA, $this->ticket($this->fixture->ticketIds['internal/anna'])->getResponsibleUserId());
	}

	public function testAConfirmedCustomerCommentIsWrittenAndMarkedAsToolInCommentAndMail(): void {
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$result = $this->call(AddComment::class, LeakMatrixFixture::ANNA, [
			'ticket_id' => $ticketId,
			'body' => 'Bitte @lm-bert draufschauen.',
			'confirm_public' => true,
		]);

		$this->assertFalse($result->isError(), $this->text($result));
		$commentId = $result->structured()['comment']['id'];
		$comment = array_values(array_filter(
			Server::get(CommentMapper::class)->findForTickets([$ticketId]),
			static fn ($c): bool => (int)$c->getId() === $commentId,
		))[0];
		$this->assertSame(ViewerContext::VIA_TOOL, $comment->getCreatedVia());
		$this->assertSame(LeakMatrixFixture::ANNA, $comment->getAuthorUserId(), 'Autor bleibt die Person, nicht das Werkzeug.');

		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('actor_via')->from('pwerk_mail_outbox')
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId)))
			->andWhere($qb->expr()->eq('recipient_uid', $qb->createNamedParameter(LeakMatrixFixture::BERT)));
		$via = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
		$this->assertNotEmpty($via, 'Bert wird erwähnt und benachrichtigt.');
		$this->assertSame([ViewerContext::VIA_TOOL], array_values(array_unique($via)));
	}

	public function testAnInternalCommentNeedsNoConfirmation(): void {
		$result = $this->call(AddComment::class, LeakMatrixFixture::BERT, [
			'ticket_id' => $this->fixture->ticketIds['internal/anna'],
			'body' => 'Intern notiert.',
		]);

		$this->assertFalse($result->isError(), $this->text($result));
	}

	public function testAHiddenTicketIsNotFoundEvenWithConfirmation(): void {
		$notFound = ToolResult::encode(ToolResult::notFound()->toArray());

		foreach ([AddComment::class => ['body' => 'x'], CloseTicket::class => ['outcome' => 'done'], UpdateTicket::class => ['title' => 'x']] as $class => $extra) {
			$result = $this->call($class, LeakMatrixFixture::BERT, [
				'ticket_id' => $this->fixture->ticketIds['private/anna'],
				'confirm_public' => true,
			] + $extra);
			$this->assertSame($notFound, ToolResult::encode($result->toArray()), $class);
		}

		$outsider = $this->call(AddComment::class, LeakMatrixFixture::FREMD, [
			'ticket_id' => $this->fixture->ticketIds['public/anna'],
			'body' => 'x',
			'confirm_public' => true,
		]);
		$this->assertSame($notFound, ToolResult::encode($outsider->toArray()), 'Nichtmitglied');
	}

	public function testMoveAppendsToTheTargetColumnAndCloseSetsTheOutcome(): void {
		$ticketId = $this->fixture->ticketIds['internal/bert'];
		$target = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_B];

		$moved = $this->call(MoveTicket::class, LeakMatrixFixture::BERT, ['ticket_id' => $ticketId, 'target_column_id' => $target]);
		$this->assertFalse($moved->isError(), $this->text($moved));
		$visible = Server::get(TicketMapper::class)->findVisibleInBoard($this->fixture->contextFor(LeakMatrixFixture::BERT), $target);
		$this->assertSame($ticketId, (int)end($visible)->getId(), 'Ans Ende der Zielspalte.');

		$closed = $this->call(CloseTicket::class, LeakMatrixFixture::BERT, ['ticket_id' => $ticketId, 'outcome' => 'discarded']);
		$this->assertFalse($closed->isError(), $this->text($closed));
		$this->assertSame('discarded', $this->ticket($ticketId)->getClosedOutcome());

		$again = $this->call(CloseTicket::class, LeakMatrixFixture::BERT, ['ticket_id' => $ticketId, 'outcome' => 'done']);
		$this->assertTrue($again->isError());
	}

	public function testAStaleVersionIsAConflictNotAnOverwrite(): void {
		$ticketId = $this->fixture->ticketIds['internal/anna'];
		$version = (int)$this->ticket($ticketId)->getVersion();
		$this->call(UpdateTicket::class, LeakMatrixFixture::ANNA, ['ticket_id' => $ticketId, 'title' => 'erste']);

		$stale = $this->call(UpdateTicket::class, LeakMatrixFixture::ANNA, ['ticket_id' => $ticketId, 'title' => 'zweite', 'version' => $version]);

		$this->assertTrue($stale->isError());
		$this->assertSame('erste', $this->ticket($ticketId)->getTitle());
	}

	public function testStepsAreCreatedAssignedAndTickedOnlyOnTheirOwnTicket(): void {
		$ticketId = $this->fixture->ticketIds['internal/anna'];

		$added = $this->call(AddStep::class, LeakMatrixFixture::ANNA, [
			'ticket_id' => $ticketId,
			'title' => 'Angebot prüfen',
			'assigned_user_id' => LeakMatrixFixture::BERT,
			'due_date' => '2026-12-24',
		]);
		$this->assertFalse($added->isError(), $this->text($added));
		$stepId = $added->structured()['step']['id'];
		$this->assertSame(LeakMatrixFixture::BERT, $added->structured()['step']['assignedUserId']);
		$step = array_values(array_filter(
			Server::get(StepMapper::class)->findForTickets([$ticketId]),
			static fn ($s): bool => (int)$s->getId() === $stepId,
		))[0];
		$this->assertSame(ViewerContext::VIA_TOOL, $step->getCreatedVia());

		$wrongTicket = $this->call(UpdateStep::class, LeakMatrixFixture::ANNA, [
			'ticket_id' => $this->fixture->ticketIds['internal/bert'],
			'step_id' => $stepId,
			'done' => true,
		]);
		$this->assertSame(ToolResult::encode(ToolResult::notFound()->toArray()), ToolResult::encode($wrongTicket->toArray()));

		$ticked = $this->call(UpdateStep::class, LeakMatrixFixture::ANNA, ['ticket_id' => $ticketId, 'step_id' => $stepId, 'done' => true, 'assigned_user_id' => '']);
		$this->assertFalse($ticked->isError(), $this->text($ticked));
		$this->assertTrue($ticked->structured()['step']['done']);
		$this->assertNull($ticked->structured()['step']['assignedUserId']);
	}

	public function testTheCustomerSideCannotTransferToGithub(): void {
		$result = $this->call(TransferToGithub::class, LeakMatrixFixture::CARLA, [
			'ticket_id' => $this->fixture->ticketIds['internal/carla'],
		]);

		$this->assertTrue($result->isError());
		$this->assertNull($this->ticket($this->fixture->ticketIds['internal/carla'], LeakMatrixFixture::CARLA)->getGithubIssueNumber());
	}

	public function testAnExhaustedBudgetWritesNothing(): void {
		$ticketId = $this->fixture->ticketIds['internal/anna'];
		$before = $this->snapshot($ticketId);

		$result = $this->call(AddComment::class, LeakMatrixFixture::ANNA, ['ticket_id' => $ticketId, 'body' => 'x'], false);

		$this->assertTrue($result->isError());
		$this->assertStringContainsString('Rate limit', $this->text($result));
		$this->assertSame($before, $this->snapshot($ticketId));
	}

	/** @param class-string<Tool> $class */
	private function call(string $class, string $userId, array $arguments, bool $budget = true): ToolResult {
		$limiter = $this->createStub(McpRateLimiter::class);
		$limiter->method('allowWrite')->willReturn($budget);

		$parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
		$tool = new $class(...array_map(
			static fn (\ReflectionParameter $p): mixed => (string)$p->getType() === McpRateLimiter::class ? $limiter : Server::get((string)$p->getType()),
			$parameters,
		));

		return $tool->call(new AuthenticatedCaller($userId, 'test-client'), ['board_id' => $this->fixture->boardId] + $arguments);
	}

	private function text(ToolResult $result): string {
		return (string)$result->toArray()['content'][0]['text'];
	}

	private function ticket(int $ticketId, string $as = LeakMatrixFixture::ANNA): Ticket {
		return Server::get(TicketMapper::class)->findVisible($this->fixture->contextFor($as), $ticketId);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function snapshot(int $ticketId): array {
		$ticket = $this->ticket($ticketId);
		$ids = [$ticketId];

		return [
			'version' => $ticket->getVersion(),
			'columnId' => $ticket->getColumnId(),
			'closedAt' => $ticket->getClosedAt()?->format('c'),
			'comments' => count(Server::get(CommentMapper::class)->findForTickets($ids)),
			'steps' => count(Server::get(StepMapper::class)->findForTickets($ids)),
		];
	}
}
