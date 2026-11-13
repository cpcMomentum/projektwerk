<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\ContentLimits;
use OCA\Projektwerk\Service\StepService;
use OCA\Projektwerk\Service\TicketService;
use OCP\Server;

class ContentLimitsTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;
	private TicketService $ticketService;
	private StepService $steps;
	private TicketMapper $tickets;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
		$this->ticketService = Server::get(TicketService::class);
		$this->steps = Server::get(StepService::class);
		$this->tickets = Server::get(TicketMapper::class);
	}

	public function testTicketTitleAndDescriptionAtTheLimitAreAccepted(): void {
		$ticket = $this->ticketService->create(
			$this->owner(),
			str_repeat('ä', ContentLimits::TICKET_TITLE),
			str_repeat('ä', ContentLimits::TICKET_DESCRIPTION),
			TicketScope::VISIBILITY_PUBLIC,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
		);

		$this->assertSame(ContentLimits::TICKET_TITLE, mb_strlen((string)$ticket->getTitle()));
		$this->assertSame(ContentLimits::TICKET_DESCRIPTION, mb_strlen((string)$ticket->getDescription()));
	}

	public function testTicketCreateRejectsAnOverlongDescription(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->ticketService->create(
			$this->owner(),
			'Zu lang',
			str_repeat('x', ContentLimits::TICKET_DESCRIPTION + 1),
			TicketScope::VISIBILITY_PUBLIC,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
		);
	}

	public function testTicketCreateRejectsAnOverlongTitle(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->ticketService->create(
			$this->owner(),
			str_repeat('x', ContentLimits::TICKET_TITLE + 1),
			null,
			TicketScope::VISIBILITY_PUBLIC,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
		);
	}

	public function testTicketUpdateRejectsAnOverlongDescriptionAndKeepsTheOldOne(): void {
		$viewer = $this->owner();
		$ticketId = $this->fixture->ticketIds['public/anna'];
		$vorher = $this->tickets->findVisible($viewer, $ticketId);

		try {
			$this->ticketService->update($viewer, $ticketId, (int)$vorher->getVersion(), [
				'description' => str_repeat('x', ContentLimits::TICKET_DESCRIPTION + 1),
			]);
			$this->fail('Eine zu lange Beschreibung wurde angenommen.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}

		$nachher = $this->tickets->findVisible($viewer, $ticketId);
		$this->assertSame($vorher->getDescription(), $nachher->getDescription());
		$this->assertSame((int)$vorher->getVersion(), (int)$nachher->getVersion());
	}

	public function testStepCreateRejectsAnOverlongTitleOrDescription(): void {
		$viewer = $this->owner();
		$ticketId = $this->fixture->ticketIds['public/anna'];
		$vorher = $this->stepCount($ticketId);

		foreach ([
			[str_repeat('x', ContentLimits::STEP_TITLE + 1), null],
			['Titel', str_repeat('x', ContentLimits::STEP_TEXT + 1)],
		] as [$title, $description]) {
			try {
				$this->steps->create($viewer, $ticketId, $title, null, null, $description);
				$this->fail('Ein zu langer Schritt wurde angenommen.');
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertSame($vorher, $this->stepCount($ticketId));
	}

	public function testStepUpdateRejectsAnOverlongResultButAcceptsTheLimit(): void {
		$viewer = $this->owner();
		$step = $this->steps->create($viewer, $this->fixture->ticketIds['public/anna'], 'Mit Ergebnis');

		try {
			$this->steps->update($viewer, (int)$step->getId(), ['result' => str_repeat('x', ContentLimits::STEP_TEXT + 1)]);
			$this->fail('Ein zu langes Ergebnis wurde angenommen.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}

		$amLimit = $this->steps->update($viewer, (int)$step->getId(), ['result' => str_repeat('ü', ContentLimits::STEP_TEXT)]);
		$this->assertSame(ContentLimits::STEP_TEXT, mb_strlen((string)$amLimit->getResult()));
	}

	public function testATicketHoldsAtMostTheMaximumNumberOfSteps(): void {
		$viewer = $this->owner();
		$ticketId = $this->fixture->ticketIds['public/anna'];

		for ($i = $this->stepCount($ticketId); $i < ContentLimits::STEPS_PER_TICKET; $i++) {
			$this->steps->create($viewer, $ticketId, 'Schritt ' . $i);
		}

		try {
			$this->steps->create($viewer, $ticketId, 'Einer zu viel');
			$this->fail('Der Vorgang nahm mehr Schritte an als erlaubt.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}

		$this->assertSame(ContentLimits::STEPS_PER_TICKET, $this->stepCount($ticketId));
	}

	private function stepCount(int $ticketId): int {
		return count(Server::get(StepMapper::class)->findForTickets([$ticketId]));
	}

	private function owner(): ViewerContext {
		return $this->fixture->contextFor(LeakMatrixFixture::ANNA);
	}
}
