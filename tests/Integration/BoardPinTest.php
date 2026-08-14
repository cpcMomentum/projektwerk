<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Service\BoardPinService;
use OCP\Server;

/**
 * Die Pin-Liste als Nutzer-Einstellung (#115), gegen den **echten**
 * `IUserConfig` statt einen Mock.
 *
 * Bewusst Integration und nicht Unit: `IUserConfig` ist eine große
 * Schnittstelle, ihr Mock erzeugt nur Rauschen. Der Dienst ist ohnehin dünn —
 * was zählt, ist das Zusammenspiel mit dem echten Einstellungsspeicher.
 *
 * Jeder Test benutzt eine **eigene Kennung**, damit sich die Fälle nicht ins
 * Gehege kommen, ohne auf eine Transaktionsrücknahme angewiesen zu sein.
 */
class BoardPinTest extends IntegrationTestCase {

	private BoardPinService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->service = Server::get(BoardPinService::class);
	}

	public function testEmptyByDefault(): void {
		$this->assertSame([], $this->service->pinnedIds('pin-empty'));
	}

	public function testPinAddsTheIdAndUnpinRemovesExactlyThatOne(): void {
		$user = 'pin-add-remove';

		$this->service->setPin($user, 7, true);
		$this->service->setPin($user, 9, true);
		$this->assertSame([7, 9], $this->service->pinnedIds($user));

		$this->service->setPin($user, 7, false);
		$this->assertSame([9], $this->service->pinnedIds($user));
	}

	public function testPinningTwiceChangesNothing(): void {
		$user = 'pin-idempotent';

		$this->service->setPin($user, 7, true);
		$this->service->setPin($user, 7, true);

		$this->assertSame([7], $this->service->pinnedIds($user));
	}

	public function testUnpinningWhatIsNotPinnedIsHarmless(): void {
		$user = 'pin-remove-absent';

		$this->service->setPin($user, 7, false);

		$this->assertSame([], $this->service->pinnedIds($user));
	}

	public function testAGarbageValueYieldsAnEmptyList(): void {
		$user = 'pin-garbage';
		// Direkt am Speicher vorbei am Dienst: eine kaputte, aber lesbare Zeile.
		Server::get(\OCP\Config\IUserConfig::class)
			->setValueString($user, 'projektwerk', 'pinned_boards', 'kein json');

		$this->assertSame([], $this->service->pinnedIds($user));
	}

	public function testDuplicatesAndStringsAreNormalised(): void {
		$user = 'pin-normalise';
		Server::get(\OCP\Config\IUserConfig::class)
			->setValueString($user, 'projektwerk', 'pinned_boards', '["7", 7, "9"]');

		$this->assertSame([7, 9], $this->service->pinnedIds($user));
	}
}
