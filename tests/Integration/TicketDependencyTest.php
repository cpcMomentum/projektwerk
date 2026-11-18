<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Mcp\AuthenticatedCaller;
use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\McpRateLimiter;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Mcp\Tools\AddDependency;
use OCA\Projektwerk\Mcp\Tools\GetTicket;
use OCA\Projektwerk\Mcp\Tools\RemoveDependency;
use OCA\Projektwerk\Service\TicketDependencyService;
use OCA\Projektwerk\Service\TicketReadModel;
use OCA\Projektwerk\Service\TicketService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\Server;

class TicketDependencyTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;

	protected function tearDown(): void {
		Server::get(IAppConfig::class)->deleteKey('projektwerk', McpConfig::KEY_PUBLIC_WRITE);
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
	}

	public function testBothSidesShowTheLinkAndTheCustomerNeverSeesAnInternalPartner(): void {
		$this->service()->add($this->as(LeakMatrixFixture::ANNA), $this->id('public/anna'), $this->id('internal/anna'));
		$this->service()->add($this->as(LeakMatrixFixture::ANNA), $this->id('public/anna'), $this->id('public/bert'));

		$anna = $this->detail(LeakMatrixFixture::ANNA, 'public/anna');
		$this->assertEqualsCanonicalizing(['internal/anna', 'public/bert'], array_column($anna['dependsOn'], 'title'));
		$this->assertSame(['public/anna'], array_column($this->detail(LeakMatrixFixture::ANNA, 'public/bert')['neededBy'], 'title'));

		$carla = $this->detail(LeakMatrixFixture::CARLA, 'public/anna');
		$this->assertSame(['public/bert'], array_column($carla['dependsOn'], 'title'), 'Der interne Partner bleibt für die Kundenseite unsichtbar.');
	}

	public function testAHiddenPartnerIsRefusedLikeAMissingOne(): void {
		$this->expectException(DoesNotExistException::class);
		$this->service()->add($this->as(LeakMatrixFixture::CARLA), $this->id('public/anna'), $this->id('internal/anna'));
	}

	public function testATicketOfAnotherBoardIsRefused(): void {
		$this->expectException(DoesNotExistException::class);
		$this->service()->add($this->as(LeakMatrixFixture::BERT), $this->id('public/anna'), $this->id('b:internal/bert'));
	}

	public function testSelfReferenceAndTheDirectReverseAreRefused(): void {
		$anna = $this->as(LeakMatrixFixture::ANNA);
		$this->service()->add($anna, $this->id('public/anna'), $this->id('public/bert'));

		foreach ([['public/anna', 'public/anna'], ['public/bert', 'public/anna']] as [$waiting, $target]) {
			try {
				$this->service()->add($anna, $this->id($waiting), $this->id($target));
				$this->fail($waiting . ' → ' . $target . ' hätte abgelehnt werden müssen.');
			} catch (\InvalidArgumentException) {
			}
		}
		$this->assertSame(1, $this->rowCount());
	}

	public function testAddingTwiceKeepsOneLinkAndRemoveDeletesIt(): void {
		$anna = $this->as(LeakMatrixFixture::ANNA);
		$this->service()->add($anna, $this->id('public/anna'), $this->id('public/bert'));
		$links = $this->service()->add($anna, $this->id('public/anna'), $this->id('public/bert'));

		$this->assertCount(1, $links['dependsOn']);
		$this->assertSame(1, $this->rowCount());

		$this->assertSame([], $this->service()->remove($anna, $this->id('public/anna'), $this->id('public/bert'))['dependsOn']);
		$this->assertSame(0, $this->rowCount());
	}

	public function testPurgingTheBoardRemovesItsLinks(): void {
		$anna = $this->as(LeakMatrixFixture::ANNA);
		$this->service()->add($anna, $this->id('public/anna'), $this->id('public/bert'));

		$qb = $this->db->getQueryBuilder();
		$qb->update('pwerk_tickets')
			->set('deleted_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($this->fixture->boardId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		Server::get(TicketMapper::class)->purgeDeletedInBoard($anna);

		$this->assertSame(0, $this->rowCount());
	}

	public function testTheAiToolAsksBeforeTouchingACustomerVisibleTicketAndMarksItsLink(): void {
		Server::get(McpConfig::class)->setPublicWriteAllowed(true);
		$args = ['ticket_id' => $this->id('internal/anna'), 'depends_on_id' => $this->id('public/bert')];

		$refused = $this->call(AddDependency::class, $args);
		$this->assertTrue($refused->isError());
		$this->assertStringContainsString('customer-visible', $this->text($refused));
		$this->assertSame(0, $this->rowCount());

		$added = $this->call(AddDependency::class, $args + ['confirm_public' => true]);
		$this->assertFalse($added->isError(), $this->text($added));
		$this->assertSame(['public/bert'], array_column($added->structured()['dependsOn'], 'title'));
		$this->assertSame([ViewerContext::VIA_TOOL], $this->createdVia());

		$read = $this->call(GetTicket::class, ['ticket_id' => $this->id('public/bert')]);
		$this->assertSame(['internal/anna'], array_column($read->structured()['neededBy'], 'title'));

		$removed = $this->call(RemoveDependency::class, $args + ['confirm_public' => true]);
		$this->assertFalse($removed->isError(), $this->text($removed));
		$this->assertSame(0, $this->rowCount());
	}

	public function testAddingAndRemovingALinkShowsInTheHistoryOfTheWaitingTicket(): void {
		$anna = $this->as(LeakMatrixFixture::ANNA);
		$this->service()->add($anna, $this->id('public/anna'), $this->id('public/bert'));
		$this->service()->remove($anna, $this->id('public/anna'), $this->id('public/bert'));

		$eintraege = $this->verlauf(LeakMatrixFixture::CARLA, 'public/anna');
		$this->assertSame([TicketLog::DEPENDENCY_ADDED, TicketLog::DEPENDENCY_REMOVED], array_column($eintraege, 'action'));
		$this->assertSame([LeakMatrixFixture::ANNA, LeakMatrixFixture::ANNA], array_column($eintraege, 'actor'));
		$this->assertStringContainsString('public/bert', $eintraege[0]['subject']);
	}

	public function testTheHistoryNeverNamesAPartnerTheViewerCannotSee(): void {
		$anna = $this->as(LeakMatrixFixture::ANNA);
		$this->service()->add($anna, $this->id('public/anna'), $this->id('internal/anna'));
		$this->service()->remove($anna, $this->id('public/anna'), $this->id('internal/anna'));

		$this->assertCount(2, $this->verlauf(LeakMatrixFixture::ANNA, 'public/anna'));
		$this->assertSame([], $this->verlauf(LeakMatrixFixture::CARLA, 'public/anna'), 'Die Kundenseite erfährt weder Titel noch Existenz des internen Vorgangs.');
	}

	public function testAPartnerThatLaterBecomesVisibleDoesNotRevealItsHiddenPast(): void {
		$anna = $this->as(LeakMatrixFixture::ANNA);
		$tickets = Server::get(TicketService::class);
		$partner = $tickets->create($anna, 'Heikler Arbeitstitel', null, 'internal', $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A]);
		$partnerId = (int)$partner->getId();
		$this->service()->add($anna, $this->id('public/anna'), $partnerId);

		$partner = $tickets->update($anna, $partnerId, (int)$partner->getVersion(), ['title' => 'Unverfänglich']);
		$tickets->changeVisibility($anna, $partnerId, (int)$partner->getVersion(), 'public');

		$this->assertSame([], $this->verlauf(LeakMatrixFixture::CARLA, 'public/anna'), 'Der Eintrag aus der internen Zeit bleibt der Kundenseite verborgen.');
		$this->assertSame('#' . $partner->getNumber() . ' Unverfänglich', $this->verlauf(LeakMatrixFixture::ANNA, 'public/anna')[0]['subject']);
		$this->assertSame(0, $this->storedTitles('Heikler Arbeitstitel'), 'Der Titel steht nie im Eintrag.');

		$this->service()->remove($anna, $this->id('public/anna'), $partnerId);
		$this->assertSame([TicketLog::DEPENDENCY_REMOVED], array_column($this->verlauf(LeakMatrixFixture::CARLA, 'public/anna'), 'action'), 'Was nach dem Wechsel geschieht, sieht die Kundenseite.');
	}

	private function storedTitles(string $title): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('pwerk_ticket_log')
			->where($qb->expr()->in('action', $qb->createNamedParameter(TicketLog::DEPENDENCY_ACTIONS, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->like('subject', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($title) . '%')));

		return (int)$qb->executeQuery()->fetchOne();
	}

	public function testRepeatingALinkOrRemovingAMissingOneLeavesNoEntry(): void {
		$anna = $this->as(LeakMatrixFixture::ANNA);
		$this->service()->add($anna, $this->id('public/anna'), $this->id('public/bert'));
		$this->service()->add($anna, $this->id('public/anna'), $this->id('public/bert'));
		$this->service()->remove($anna, $this->id('public/bert'), $this->id('internal/anna'));

		$this->assertCount(1, $this->verlauf(LeakMatrixFixture::ANNA, 'public/anna'));
		$this->assertSame([], $this->verlauf(LeakMatrixFixture::ANNA, 'public/bert'));
	}

	/**
	 * Nur die Einträge zu Abhängigkeiten, älteste zuerst.
	 *
	 * @return list<array{action: string, actor: string, subject: ?string}>
	 */
	private function verlauf(string $userId, string $label): array {
		$alle = Server::get(TicketReadModel::class)->history($this->as($userId), $this->id($label));

		return array_values(array_map(
			static fn (TicketLog $e): array => ['action' => $e->getAction(), 'actor' => $e->getActorUserId(), 'subject' => $e->getSubject()],
			array_filter($alle, static fn (TicketLog $e): bool => in_array($e->getAction(), TicketLog::DEPENDENCY_ACTIONS, true)),
		));
	}

	private function service(): TicketDependencyService {
		return Server::get(TicketDependencyService::class);
	}

	private function as(string $userId): ViewerContext {
		return $this->fixture->contextFor($userId);
	}

	private function id(string $label): int {
		return $this->fixture->ticketIds[$label];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function detail(string $userId, string $label): array {
		return Server::get(TicketReadModel::class)->show($this->as($userId), $this->id($label));
	}

	private function rowCount(): int {
		return count($this->createdVia());
	}

	/**
	 * @return list<?string>
	 */
	private function createdVia(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.created_via')
			->from('pwerk_ticket_deps', 'd')
			->innerJoin('d', 'pwerk_tickets', 't', $qb->expr()->eq('t.id', 'd.ticket_id'))
			->where($qb->expr()->eq('d.board_id', $qb->createNamedParameter($this->fixture->boardId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		return array_values($rows);
	}

	/**
	 * @param class-string<\OCA\Projektwerk\Mcp\Tool> $class
	 * @param array<string, mixed> $arguments
	 */
	private function call(string $class, array $arguments): ToolResult {
		$limiter = $this->createStub(McpRateLimiter::class);
		$limiter->method('allowWrite')->willReturn(true);
		$limiter->method('allowRead')->willReturn(true);

		$parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
		$tool = new $class(...array_map(
			static fn (\ReflectionParameter $p): mixed => (string)$p->getType() === McpRateLimiter::class ? $limiter : Server::get((string)$p->getType()),
			$parameters,
		));

		return $tool->call(new AuthenticatedCaller(LeakMatrixFixture::ANNA, 'test-client'), ['board_id' => $this->fixture->boardId] + $arguments);
	}

	private function text(ToolResult $result): string {
		return (string)$result->toArray()['content'][0]['text'];
	}
}
