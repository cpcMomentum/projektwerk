<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Imap;

use OCA\Projektwerk\Imap\ImapClient;
use OCA\Projektwerk\Imap\ImapCommandRejectedException;
use OCA\Projektwerk\Imap\ImapException;
use PHPUnit\Framework\TestCase;

/**
 * Der Aufrufer muss „Befehl abgelehnt" von „Verbindung weg" unterscheiden können.
 */
class ImapClientTest extends TestCase {

	/** @var resource|null */
	private $gegenstelle = null;

	/** Ein Client, dessen Gegenstelle die Zeilen aus `$antwort` schon bereithält; `null` heißt: Verbindung weg. */
	private function clientMit(?string $antwort): ImapClient {
		[$hier, $dort] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
		if ($antwort === null) {
			stream_socket_shutdown($dort, STREAM_SHUT_WR);
		} else {
			fwrite($dort, $antwort);
		}
		$this->gegenstelle = $dort;

		$client = new ImapClient('imap.example.org', 993, 'ssl');
		(new \ReflectionProperty(ImapClient::class, 'stream'))->setValue($client, $hier);

		return $client;
	}

	public function testASearchReturnsTheUids(): void {
		$client = $this->clientMit("* SEARCH 4 9 12\r\nA001 OK done\r\n");

		$this->assertSame([4, 9, 12], $client->uidSearch('UNSEEN'));
	}

	public function testARejectedCommandIsItsOwnKindOfFailure(): void {
		$client = $this->clientMit("A001 BAD Unknown search key\r\n");

		$this->expectException(ImapCommandRejectedException::class);
		$client->uidSearch('UNSEEN SUBJECT "[PW-"');
	}

	public function testAClosedConnectionIsNotARejectedCommand(): void {
		$client = $this->clientMit(null);

		try {
			$client->uidSearch('UNSEEN');
			$this->fail('Die geschlossene Verbindung hätte auffallen müssen.');
		} catch (ImapException $e) {
			$this->assertNotInstanceOf(ImapCommandRejectedException::class, $e);
		}
	}
}
