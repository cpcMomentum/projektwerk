<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Access\ChangeHighlighter;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Access\WaitStateCalculator;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketReadMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCA\Projektwerk\Service\AttachmentService;
use OCA\Projektwerk\Service\TicketReadModel;
use PHPUnit\Framework\TestCase;

/**
 * Die Schritt-Titel in der Board-Antwort (#373).
 *
 * Sie kommen aus denselben Zeilen wie die Zähler; wer sie aus einer eigenen
 * Abfrage holte, hätte einen zweiten Lesepfad an der Sichtbarkeit vorbei.
 */
class TicketReadModelTest extends TestCase {

	private function ticket(int $id): Ticket {
		$ticket = new Ticket();
		$ticket->setId($id);
		return $ticket;
	}

	private function step(int $ticketId, string $title): Step {
		$step = new Step();
		$step->setTicketId($ticketId);
		$step->setTitle($title);
		$step->setDone(0);
		return $step;
	}

	public function testStepTitlesAreGroupedByTicketFromTheSameRows(): void {
		$tickets = $this->createStub(TicketMapper::class);
		$tickets->method('findVisibleInBoard')->willReturn([$this->ticket(1), $this->ticket(2), $this->ticket(3)]);

		$steps = $this->createMock(StepMapper::class);
		$steps->expects($this->once())
			->method('findForTickets')
			->with([1, 2, 3])
			->willReturn([
				$this->step(1, 'Angebot schreiben'),
				$this->step(2, 'Logo liefern'),
				$this->step(1, 'Freigabe holen'),
			]);

		$highlighter = $this->createStub(ChangeHighlighter::class);
		$highlighter->method('detect')->willReturn([]);

		$model = new TicketReadModel(
			$tickets,
			$this->createStub(CommentMapper::class),
			$steps,
			$this->createStub(AttachmentMapper::class),
			$this->createStub(TicketUserMapper::class),
			$this->createStub(TicketReadMapper::class),
			$this->createStub(AttachmentService::class),
			$this->createStub(WaitStateCalculator::class),
			$highlighter,
		);

		$data = $model->index(ViewerContext::forMember('u', 1, 9, ViewerContext::ROLE_INTERNAL, false, false));

		$this->assertSame(
			[1 => ['Angebot schreiben', 'Freigabe holen'], 2 => ['Logo liefern']],
			$data['stepTitles'],
		);
		// Ticket 3 hat keine Schritte und fehlt — kein leeres Array je Vorgang.
		$this->assertArrayNotHasKey(3, $data['stepTitles']);
	}
}
