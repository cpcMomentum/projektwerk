<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Db;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * **Der Write-Time-Wächter auf `tickets.project_id`** (#246).
 *
 * {@see TicketScope} joint die Mitgliedschaft über `t.project_id`. Ein Ticket
 * ohne gültiges `project_id` wäre über den regulären Lesepfad unsichtbar, mit
 * einem *falschen* Wert der Kundenseite eines fremden Projekts sichtbar. Der
 * Wächter in {@see TicketMapper::insert()} weist beides an der Schreibseite ab,
 * bevor eine Zeile entsteht — der Fehler fällt am schuldigen Insert, nicht erst
 * beim (womöglich falschen) Betrachter.
 *
 * Der Wächter wirft **vor** dem Datenbankzugriff; deshalb genügt hier ein
 * Mapper mit gemockten Abhängigkeiten, ohne laufende Nextcloud. Dass der gültige
 * Weg (mit `project_id`) durchgeht, prüft die Integration: jede Leak-Matrix-
 * Fixtur fügt Tickets mit `project_id` ein.
 */
class TicketWriteGuardTest extends TestCase {

	private function mapper(): TicketMapper {
		return new TicketMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(TicketScope::class),
		);
	}

	public function testInsertRejectsATicketWithoutProjectId(): void {
		$ticket = new Ticket();
		$ticket->setBoardId(7);
		// project_id bewusst nicht gesetzt — der Normalfall des Fehlers.

		$this->expectException(\LogicException::class);
		$this->mapper()->insert($ticket);
	}

	public function testInsertRejectsAZeroProjectId(): void {
		$ticket = new Ticket();
		$ticket->setBoardId(7);
		$ticket->setProjectId(0);

		$this->expectException(\LogicException::class);
		$this->mapper()->insert($ticket);
	}

	public function testInsertRejectsANegativeProjectId(): void {
		$ticket = new Ticket();
		$ticket->setBoardId(7);
		$ticket->setProjectId(-1);

		$this->expectException(\LogicException::class);
		$this->mapper()->insert($ticket);
	}
}
