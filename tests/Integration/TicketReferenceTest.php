<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Reference\TicketReferenceProvider;
use OCA\Projektwerk\Search\TicketSearchProvider;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\TicketService;
use OCA\Projektwerk\Service\TicketLink;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\L10N\IFactory;
use OCP\Search\ISearchQuery;
use OCP\Server;

class TicketReferenceTest extends IntegrationTestCase {

	private const VISIBLE = [
		LeakMatrixFixture::ANNA => ['public/anna', 'public/bert', 'public/carla', 'internal/anna', 'internal/bert', 'private/anna'],
		LeakMatrixFixture::CARLA => ['public/anna', 'public/bert', 'public/carla', 'internal/carla', 'private/carla'],
		LeakMatrixFixture::FREMD => [],
	];

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
	}

	public function testTheCardResolvesOnlyWhatTheViewerMaySee(): void {
		$urls = Server::get(IURLGenerator::class);

		foreach (self::VISIBLE as $userId => $expected) {
			$provider = $this->provider($userId);
			foreach ($this->fixture->ticketIds as $label => $ticketId) {
				$link = Server::get(TicketLink::class)->forTicket($ticketId);
				$this->assertTrue($provider->matchReference($link), $link);

				$reference = $provider->resolveReference($link);
				if (!in_array($label, $expected, true)) {
					$this->assertNull($reference, $userId . ' bekommt eine Karte für ' . $label);
					continue;
				}

				$this->assertNotNull($reference, $userId . ' / ' . $label);
				$card = $reference->getRichObject();
				$this->assertSame($label, $card['title']);
				$this->assertSame($ticketId, $card['id']);
			}
		}
	}

	/**
	 * Über den echten Manager samt Cache: Zuerst löst Anna die interne Karte auf, danach darf Carla sie nicht aus dem Cache bekommen.
	 */
	public function testTheCustomerDoesNotGetTheInternalCardFromTheCache(): void {
		$manager = Server::get(IReferenceManager::class);
		$session = Server::get(IUserSession::class);
		$link = Server::get(TicketLink::class)->forTicket($this->fixture->ticketIds['internal/anna']);

		try {
			foreach ([LeakMatrixFixture::ANNA => true, LeakMatrixFixture::CARLA => false, LeakMatrixFixture::FREMD => false] as $userId => $sees) {
				$user = $this->createStub(IUser::class);
				$user->method('getUID')->willReturn($userId);
				$session->setVolatileActiveUser($user);

				$reference = $manager->resolveReference($link);
				$this->assertSame($sees, $reference !== null && $reference->getRichObjectType() === TicketReferenceProvider::RICH_OBJECT_TYPE, $userId);
			}
		} finally {
			$session->setVolatileActiveUser(null);
		}
	}

	public function testAChangeToInternalDropsTheCachedCustomerCard(): void {
		$manager = Server::get(IReferenceManager::class);
		$session = Server::get(IUserSession::class);
		$tickets = Server::get(TicketService::class);
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);
		$ticket = $tickets->create($anna, 'Vorher öffentlich', null, TicketScope::VISIBILITY_PUBLIC, $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A]);
		$link = Server::get(TicketLink::class)->forTicket((int)$ticket->getId());

		try {
			$this->actAs($session, LeakMatrixFixture::CARLA);
			$this->assertNotNull($manager->resolveReference($link), 'Vorher sieht Carla die Karte.');

			$tickets->changeVisibility($anna, (int)$ticket->getId(), (int)$ticket->getVersion(), TicketScope::VISIBILITY_INTERNAL);

			$this->actAs($session, LeakMatrixFixture::CARLA);
			$after = $manager->resolveReference($link);
			$this->assertFalse($after !== null && $after->getRichObjectType() === TicketReferenceProvider::RICH_OBJECT_TYPE, 'Carla bekommt die alte Karte aus dem Cache.');
		} finally {
			$session->setVolatileActiveUser(null);
		}
	}

	private function actAs(IUserSession $session, string $userId): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session->setVolatileActiveUser($user);
	}

	public function testTheCacheKeyIsPerUser(): void {
		$this->assertSame(LeakMatrixFixture::ANNA, $this->provider(LeakMatrixFixture::ANNA)->getCacheKey('x'));
		$this->assertSame(LeakMatrixFixture::CARLA, $this->provider(LeakMatrixFixture::CARLA)->getCacheKey('x'));
	}

	public function testTheHashFormIsRecognisedToo(): void {
		$base = rtrim(Server::get(IURLGenerator::class)->getAbsoluteURL('/'), '/');
		$provider = $this->provider(LeakMatrixFixture::ANNA);

		$this->assertSame(42, $provider->ticketIdFrom($base . '/index.php/apps/projektwerk/#/boards/7?ticket=42'));
		$this->assertSame(42, $provider->ticketIdFrom($base . '/apps/projektwerk/t/42'));
		$this->assertNull($provider->ticketIdFrom('https://fremd.example/apps/projektwerk/t/42'));
		$this->assertNull($provider->ticketIdFrom($base . '/apps/deck/t/42'));
	}

	public function testSearchFindsOnlyTheVisibleSet(): void {
		$search = new TicketSearchProvider(
			Server::get(IURLGenerator::class),
			Server::get(TicketLink::class),
			$this->l10n(),
			Server::get(TicketMapper::class),
			Server::get(BoardMapper::class),
		);

		foreach (self::VISIBLE as $userId => $expected) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$query = $this->createStub(ISearchQuery::class);
			$query->method('getTerm')->willReturn('/');
			$query->method('getLimit')->willReturn(25);

			$titles = array_map(
				static fn (\OCP\Search\SearchResultEntry $entry): string => substr((string)$entry->jsonSerialize()["title"], 6),
				$search->search($user, $query)->jsonSerialize()['entries'],
			);
			sort($titles);
			$sorted = $expected;
			sort($sorted);
			$this->assertSame($sorted, $titles, $userId);
		}
	}

	private function provider(string $userId): TicketReferenceProvider {
		return new TicketReferenceProvider(
			Server::get(IURLGenerator::class),
			Server::get(TicketLink::class),
			$this->l10n(),
			Server::get(TicketMapper::class),
			Server::get(BoardAccess::class),
			Server::get(BoardMapper::class),
			Server::get(ProjectMapper::class),
			Server::get(ColumnMapper::class),
			Server::get(MemberService::class),
			$this->sessionOf($userId),
		);
	}

	private function sessionOf(string $userId): IUserSession {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	private function l10n(): IL10N {
		return Server::get(IFactory::class)->get('projektwerk', 'de');
	}
}
