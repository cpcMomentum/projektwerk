<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Controller\SettingsController;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\ColumnService;
use OCA\Projektwerk\Service\MemberService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Server;

/** Über den Controller geprüft: Nur dort ging ein gesendetes `null` als „nicht geschickt“ verloren. */
class BoardClearFieldsTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();

		Server::get(BoardService::class)->update($this->viewer(), [
			'description' => 'Beschreibung',
			'chatUrl' => 'https://chat.example.org/pw',
			'customer' => 'Kunde GmbH',
		]);
		Server::get(MemberService::class)->update($this->viewer(), LeakMatrixFixture::BERT, ['company' => 'Subunternehmer']);
	}

	public function testAnExplicitNullClearsTheProjectFields(): void {
		$response = $this->controller([
			'description' => null,
			'orgInternal' => null,
			'customer' => null,
			'chatUrl' => null,
		])->updateBoard($this->fixture->boardId);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$board = Server::get(BoardMapper::class)->findForViewer($this->viewer());
		$project = Server::get(ProjectMapper::class)->findForViewer($this->viewer());

		$this->assertNull($board->getDescription(), 'Beschreibung');
		$this->assertNull($board->getOrgInternal(), 'Eigene Firma am Board');
		$this->assertNull($board->getCustomer(), 'Kunde am Board');
		$this->assertNull($project->getOrgInternal(), 'Eigene Firma am Projekt');
		$this->assertNull($project->getCustomer(), 'Kunde am Projekt');
		$this->assertNull($project->getChatUrl(), 'Projektchat-Adresse');
	}

	public function testAFieldThatWasNotSentStaysAsItWas(): void {
		$response = $this->controller(['title' => 'Nur der Titel'])
			->updateBoard($this->fixture->boardId, 'Nur der Titel');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$board = Server::get(BoardMapper::class)->findForViewer($this->viewer());
		$project = Server::get(ProjectMapper::class)->findForViewer($this->viewer());

		$this->assertSame('Nur der Titel', $board->getTitle());
		$this->assertSame('Beschreibung', $board->getDescription());
		$this->assertSame(LeakMatrixFixture::ORG_INTERNAL, $board->getOrgInternal());
		$this->assertSame('Kunde GmbH', $board->getCustomer());
		$this->assertSame('https://chat.example.org/pw', $project->getChatUrl());
	}

	/** Der Titel ist nicht leerbar, `null` bleibt dort „nicht geschickt“. */
	public function testAnExplicitNullOnTheTitleChangesNothing(): void {
		$before = Server::get(BoardMapper::class)->findForViewer($this->viewer())->getTitle();

		$response = $this->controller(['title' => null])->updateBoard($this->fixture->boardId);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($before, Server::get(BoardMapper::class)->findForViewer($this->viewer())->getTitle());
	}

	public function testAnExplicitNullClearsTheCompanyOfAMember(): void {
		$response = $this->controller(['company' => null])
			->updateMember($this->fixture->boardId, LeakMatrixFixture::BERT);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$this->assertNull($this->member(LeakMatrixFixture::BERT)->getCompany());
	}

	public function testAMemberUpdateWithoutCompanyKeepsIt(): void {
		$response = $this->controller(['displayName' => 'Bert B.'])
			->updateMember($this->fixture->boardId, LeakMatrixFixture::BERT, displayName: 'Bert B.');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$this->assertSame('Subunternehmer', $this->member(LeakMatrixFixture::BERT)->getCompany());
	}

	private function viewer(): ViewerContext {
		return Server::get(BoardAccess::class)->contextFor(LeakMatrixFixture::ANNA, $this->fixture->boardId);
	}

	private function member(string $userId): Member {
		foreach (Server::get(MemberMapper::class)->findForBoard($this->viewer()) as $member) {
			if ($member->getUserId() === $userId) {
				return $member;
			}
		}
		$this->fail("Mitglied $userId fehlt");
	}

	/**
	 * @param array<string, mixed> $params Was der Browser schickt, `null` ausdrücklich genannt.
	 */
	private function controller(array $params): SettingsController {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new SettingsController(
			$request,
			Server::get(BoardService::class),
			Server::get(ColumnService::class),
			Server::get(MemberService::class),
			Server::get(BoardAccess::class),
			LeakMatrixFixture::ANNA,
		);
	}
}
