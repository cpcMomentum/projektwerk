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
use OCA\Projektwerk\Service\StepService;
use OCA\Projektwerk\Service\TicketReadModel;
use OCA\Projektwerk\Service\TicketService;
use OCP\Server;

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
