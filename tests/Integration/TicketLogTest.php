<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Command\TicketRestore;
use OCA\Projektwerk\Service\ColumnService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\StepService;
use OCA\Projektwerk\Service\TicketReadModel;
use OCA\Projektwerk\Service\TicketService;
use OCP\Server;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class TicketLogTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;
	private TicketService $tickets;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
		$this->tickets = Server::get(TicketService::class);
	}

	public function testEveryChangeWritesItsEntry(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$ticket = $this->create($anna, TicketScope::VISIBILITY_PUBLIC);

		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['title' => 'Neu']);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['description' => 'Text']);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['responsibleUserId' => LeakMatrixFixture::CARLA]);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['dueDate' => '2026-12-24']);
		$ticket = $this->tickets->move($anna, $this->id($ticket), (int)$ticket->getVersion(), $this->fixture->columnIds[LeakMatrixFixture::COLUMN_B], null, null);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['closed' => true, 'outcome' => Ticket::OUTCOME_DISCARDED]);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['closed' => false]);

		$steps = Server::get(StepService::class);
		$step = $steps->create($anna, $this->id($ticket), 'Schritt', LeakMatrixFixture::ANNA);
		$steps->update($anna, (int)$step->getId(), ['done' => true]);
		$steps->delete($anna, (int)$step->getId());

		$history = $this->history($anna, $ticket);
		$this->assertSame([
			TicketLog::CREATED,
			TicketLog::TITLE,
			TicketLog::DESCRIPTION,
			TicketLog::RESPONSIBLE,
			TicketLog::DUE_DATE,
			TicketLog::MOVED,
			TicketLog::CLOSED,
			TicketLog::REOPENED,
			TicketLog::STEP_ADDED,
			TicketLog::STEP_ASSIGNED,
			TicketLog::STEP_DONE,
			TicketLog::STEP_DELETED,
		], array_map(static fn (TicketLog $entry): string => $entry->getAction(), $history));

		$this->assertSame(['Vorgang', 'Neu'], [$history[1]->getOldValue(), $history[1]->getNewValue()]);
		$this->assertNull($history[2]->getNewValue(), 'Die Beschreibung steht nie im Verlauf.');
		$this->assertSame([LeakMatrixFixture::COLUMN_A, LeakMatrixFixture::COLUMN_B], [$history[5]->getOldValue(), $history[5]->getNewValue()]);
		$this->assertSame(Ticket::OUTCOME_DISCARDED, $history[6]->getNewValue());
		$this->assertSame('Schritt', $history[11]->getSubject());
	}

	public function testAReorderWithinTheColumnIsNoMove(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$ticket = $this->create($anna, TicketScope::VISIBILITY_PUBLIC);

		$this->tickets->move($anna, $this->id($ticket), (int)$ticket->getVersion(), $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A], null, null);

		$this->assertSame([TicketLog::CREATED], $this->actions($anna, $ticket));
	}

	public function testTheOriginIsRecorded(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$ticket = $this->create($anna, TicketScope::VISIBILITY_PUBLIC);
		$this->tickets->update($anna->viaTool(), $this->id($ticket), (int)$ticket->getVersion(), ['title' => 'Vom Werkzeug']);

		$history = $this->history($anna, $ticket);
		$this->assertNull($history[0]->getVia());
		$this->assertSame(ViewerContext::VIA_TOOL, $history[1]->getVia());
	}

	public function testTheCustomerDoesNotSeeTheInternalLeadUp(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$carla = $this->fixture->contextFor(LeakMatrixFixture::CARLA);

		$ticket = $this->create($anna, TicketScope::VISIBILITY_INTERNAL);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['title' => 'Interner Arbeitstitel']);
		$ticket = $this->tickets->changeVisibility($anna, $this->id($ticket), (int)$ticket->getVersion(), TicketScope::VISIBILITY_PUBLIC);
		$this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['title' => 'Für alle']);

		$this->assertSame(
			[TicketLog::CREATED, TicketLog::TITLE, TicketLog::VISIBILITY, TicketLog::TITLE],
			$this->actions($anna, $ticket),
		);
		$this->assertSame(TicketScope::VISIBILITY_INTERNAL, $this->history($anna, $ticket)[2]->getOldValue());

		$forCustomer = $this->history($carla, $ticket);
		$this->assertSame([TicketLog::VISIBILITY, TicketLog::TITLE], array_map(static fn (TicketLog $entry): string => $entry->getAction(), $forCustomer));
		$this->assertNull($forCustomer[0]->getOldValue(), 'Der alte Stand verriete den internen Vorlauf.');
		$this->assertSame('Interner Arbeitstitel', $forCustomer[1]->getOldValue(), 'Der Titel vor der Änderung war zu dem Zeitpunkt öffentlich.');
	}

	public function testAPrivateTicketKeepsItsHistoryToItsCreator(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$bert = $this->fixture->contextFor(LeakMatrixFixture::BERT);

		$ticket = $this->create($anna, TicketScope::VISIBILITY_PRIVATE);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['title' => 'Notiz']);
		$ticket = $this->tickets->changeVisibility($anna, $this->id($ticket), (int)$ticket->getVersion(), TicketScope::VISIBILITY_INTERNAL);

		$this->assertSame([TicketLog::VISIBILITY], $this->actions($bert, $ticket));
		$this->assertNull($this->history($bert, $ticket)[0]->getOldValue());
		$this->assertSame([TicketLog::CREATED, TicketLog::TITLE, TicketLog::VISIBILITY], $this->actions($anna, $ticket));
	}

	public function testClosingAgainWithAnotherOutcomeIsRecorded(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$ticket = $this->create($anna, TicketScope::VISIBILITY_PUBLIC);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['closed' => true, 'outcome' => Ticket::OUTCOME_DONE]);
		$this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['closed' => true, 'outcome' => Ticket::OUTCOME_DISCARDED]);

		$history = $this->history($anna, $ticket);
		$this->assertSame([TicketLog::CREATED, TicketLog::CLOSED, TicketLog::CLOSED], array_map(static fn (TicketLog $entry): string => $entry->getAction(), $history));
		$this->assertSame([Ticket::OUTCOME_DONE, Ticket::OUTCOME_DISCARDED], [$history[2]->getOldValue(), $history[2]->getNewValue()]);
	}

	public function testRemovingAColumnRecordsTheMoveOfItsTickets(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$ticket = $this->create($anna, TicketScope::VISIBILITY_PUBLIC);

		Server::get(ColumnService::class)->delete($anna, $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A], $this->fixture->columnIds[LeakMatrixFixture::COLUMN_B]);

		$history = $this->history($anna, $ticket);
		$last = end($history);
		$this->assertSame(TicketLog::MOVED, $last->getAction());
		$this->assertSame([LeakMatrixFixture::COLUMN_A, LeakMatrixFixture::COLUMN_B], [$last->getOldValue(), $last->getNewValue()]);
		$this->assertSame(LeakMatrixFixture::ANNA, $last->getActorUserId());
	}

	public function testRemovingAMemberRecordsTheLiftedAssignments(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$ticket = $this->create($anna, TicketScope::VISIBILITY_PUBLIC);
		$ticket = $this->tickets->update($anna, $this->id($ticket), (int)$ticket->getVersion(), ['responsibleUserId' => LeakMatrixFixture::CARLA]);
		Server::get(StepService::class)->create($anna, $this->id($ticket), 'Abnahme', LeakMatrixFixture::CARLA);

		Server::get(MemberService::class)->remove($anna, LeakMatrixFixture::CARLA);

		$history = $this->history($anna, $ticket);
		$lifted = array_values(array_filter($history, static fn (TicketLog $entry): bool => $entry->getNewValue() === null && $entry->getOldValue() === LeakMatrixFixture::CARLA));
		$this->assertSame([TicketLog::RESPONSIBLE, TicketLog::STEP_ASSIGNED], array_map(static fn (TicketLog $entry): string => $entry->getAction(), $lifted));
		$this->assertSame('Abnahme', $lifted[1]->getSubject());
		$this->assertSame(LeakMatrixFixture::ANNA, $lifted[0]->getActorUserId());
	}

	public function testRestoringPerOccIsRecordedAsSystem(): void {
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$ticket = $this->create($anna, TicketScope::VISIBILITY_PUBLIC);
		$this->tickets->delete($anna, $this->id($ticket), (int)$ticket->getVersion());

		$command = Server::get(TicketRestore::class);
		$this->assertSame(0, $command->run(new ArrayInput(['ticketId' => (string)$this->id($ticket)]), new BufferedOutput()));

		$history = $this->history($anna, $ticket);
		$last = end($history);
		$this->assertSame(TicketLog::RESTORED, $last->getAction());
		$this->assertSame(TicketLog::ACTOR_SYSTEM, $last->getActorUserId());
	}

	private function create(ViewerContext $viewer, string $visibility): Ticket {
		return $this->tickets->create($viewer, 'Vorgang', null, $visibility, $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A]);
	}

	/** @return TicketLog[] */
	private function history(ViewerContext $viewer, Ticket $ticket): array {
		return Server::get(TicketReadModel::class)->history($viewer, $this->id($ticket));
	}

	/** @return string[] */
	private function actions(ViewerContext $viewer, Ticket $ticket): array {
		return array_map(static fn (TicketLog $entry): string => $entry->getAction(), $this->history($viewer, $ticket));
	}

	private function id(Ticket $ticket): int {
		return (int)$ticket->getId();
	}
}
