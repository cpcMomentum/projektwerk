<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Db\TicketReadMapper;
use OCP\Server;

/**
 * Der Lesestand (#79) gegen die echte Datenbank — Upsert, Skopierung und das
 * Aufräumen beim Löschen. Die Sichtbarkeits-Zusage prüft die Leak-Matrix
 * (`testReadStateIsScopedToItsOwner`); hier geht es um das Verhalten des
 * Speichers.
 *
 * Eigene Kennungen je Test, damit sich die Fälle nicht ins Gehege kommen.
 */
class TicketReadTest extends IntegrationTestCase {

	private TicketReadMapper $reads;

	protected function setUp(): void {
		parent::setUp();

		$this->reads = Server::get(TicketReadMapper::class);
	}

	public function testMarkSeenUpsertsAndStaysOneRow(): void {
		$user = 'read-upsert';

		$this->reads->markSeen($user, 42);
		$erst = $this->reads->findSeenForTickets($user, [42])[42];

		// Zweites Mal: aktualisiert, vervielfacht nicht.
		$this->reads->markSeen($user, 42);
		$seen = $this->reads->findSeenForTickets($user, [42]);

		$this->assertSame([42], array_keys($seen));
		$this->assertGreaterThanOrEqual($erst, $seen[42]);
	}

	public function testFindSeenReturnsOnlyTheAskedTickets(): void {
		$user = 'read-subset';

		$this->reads->markSeen($user, 7);
		// Nach 7 und 9 gefragt, aber nur 7 ist gelesen.
		$this->assertSame([7], array_keys($this->reads->findSeenForTickets($user, [7, 9])));
		// Leere Menge, leere Antwort — ohne Abfrage.
		$this->assertSame([], $this->reads->findSeenForTickets($user, []));
	}

	public function testDeleteForTicketRemovesEveryOnesRead(): void {
		$this->reads->markSeen('read-del-a', 100);
		$this->reads->markSeen('read-del-b', 100);
		$this->reads->markSeen('read-del-a', 101);

		$this->reads->deleteForTicket(100);

		$this->assertSame([], $this->reads->findSeenForTickets('read-del-a', [100]));
		$this->assertSame([], $this->reads->findSeenForTickets('read-del-b', [100]));
		// Der Stand zu einem anderen Vorgang bleibt.
		$this->assertSame([101], array_keys($this->reads->findSeenForTickets('read-del-a', [101])));
	}
}
