<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\TicketService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Server;

/**
 * **Der Anhang zieht mit der Sichtbarkeit um** (#185) — gegen echte Ordner.
 *
 * Die Leak-Matrix-Fixture kann das nicht prüfen: Ihre Mitglieder sind keine
 * echten Nextcloud-Nutzer, haben also keinen Dateibaum, in dem ein Ordner läge.
 * Dieser Test nimmt deshalb den echten `admin` und legt zwei echte Ordner in
 * dessen Files an — nur so lässt sich belegen, dass die Datei wirklich wandert
 * und danach erreichbar bleibt (die offene Frage aus §11.3 / Spike S2).
 *
 * Die Dateien liegen **ausserhalb** der Transaktion, die {@see IntegrationTestCase}
 * um jeden Fall legt — ein Verschieben ist keine DB-Operation. Deshalb räumt
 * {@see tearDown()} sie von Hand weg, und {@see setUp()} löscht einen etwaigen
 * Rest eines abgebrochenen Laufs zuerst.
 */
class AttachmentRelocationTest extends IntegrationTestCase {

	private const UID = 'admin';
	private const FILE = '0001_angebot.pdf';

	private Folder $home;
	private Folder $publicFolder;
	private Folder $internalFolder;
	private ViewerContext $viewer;
	private TicketService $service;
	private AttachmentMapper $attachments;
	private int $ticketId;
	private int $originalFileId;

	protected function setUp(): void {
		parent::setUp();

		$this->home = Server::get(IRootFolder::class)->getUserFolder(self::UID);
		$this->removeFolders();
		$this->publicFolder = $this->home->newFolder('pwtest_public');
		$this->internalFolder = $this->home->newFolder('pwtest_internal');

		$board = new Board();
		$board->setTitle('Umzug-Test');
		$board->setOwnerUserId(self::UID);
		$board->setOrgInternal('cpc');
		$board->setOrgExternal('Kunde');
		$board->setFolderPublicId($this->publicFolder->getId());
		$board->setFolderInternalId($this->internalFolder->getId());
		$board->setCreatedAt(new \DateTime());
		$board->setUpdatedAt(new \DateTime());
		$boardId = (int)Server::get(BoardMapper::class)->insert($board)->getId();

		$member = new Member();
		$member->setBoardId($boardId);
		$member->setUserId(self::UID);
		$member->setRole(ViewerContext::ROLE_INTERNAL);
		$member->setIsManager(1);
		$member->setAddedBy(self::UID);
		$member->setAddedAt(new \DateTime());
		Server::get(MemberMapper::class)->insert($member);

		$column = new Column();
		$column->setBoardId($boardId);
		$column->setTitle('Offen');
		$column->setPosition(0);
		$columnId = (int)Server::get(ColumnMapper::class)->insert($column)->getId();

		$ticket = new Ticket();
		$ticket->setBoardId($boardId);
		$ticket->setColumnId($columnId);
		$ticket->setNumber(1);
		$ticket->setTitle('Datei zieht mit');
		$ticket->setVisibility(TicketScope::VISIBILITY_PUBLIC);
		$ticket->setCreatorUserId(self::UID);
		$ticket->setCreatorRole(ViewerContext::ROLE_INTERNAL);
		$ticket->setResponsibleUserId(self::UID);
		$ticket->setPosition(65536);
		$ticket->setVersion(1);
		$ticket->setCreatedAt(new \DateTime());
		$ticket->setUpdatedAt(new \DateTime());
		$this->ticketId = (int)Server::get(TicketMapper::class)->insert($ticket)->getId();

		$file = $this->publicFolder->newFile(self::FILE, 'PDFINHALT');
		$this->originalFileId = $file->getId();

		$this->attachments = Server::get(AttachmentMapper::class);
		$attachment = new Attachment();
		$attachment->setTicketId($this->ticketId);
		$attachment->setFileId($this->originalFileId);
		$attachment->setFilePath('pwtest_public/' . self::FILE);
		$attachment->setFileName(self::FILE);
		$attachment->setLocation(Attachment::LOCATION_PUBLIC);
		$attachment->setUploadedBy(self::UID);
		$attachment->setCreatedAt(new \DateTime());
		$this->attachments->insert($attachment);

		$this->viewer = Server::get(BoardAccess::class)->contextFor(self::UID, $boardId);
		$this->service = Server::get(TicketService::class);
	}

	protected function tearDown(): void {
		$this->removeFolders();

		parent::tearDown();
	}

	private function removeFolders(): void {
		foreach (['pwtest_public', 'pwtest_internal'] as $name) {
			if ($this->home->nodeExists($name)) {
				$this->home->get($name)->delete();
			}
		}
	}

	/** Der Anhang eines Vorgangs, frisch gelesen. */
	private function attachmentOf(int $ticketId): Attachment {
		return $this->attachments->findForTickets([$ticketId])[0];
	}

	/**
	 * **Herabstufen zieht die Datei in den engeren Ordner** (§5.18).
	 *
	 * Der Kern: Nach `public → internal` liegt die Datei im internen Ordner und
	 * ist aus dem öffentlichen verschwunden — die Kundenseite erreicht sie nicht
	 * mehr. `location` folgt, die Datei-ID bleibt auflösbar (hier unverändert,
	 * weil beide Ordner in derselben Storage liegen), der Inhalt ist intakt.
	 */
	public function testDowngradeMovesTheFileToTheInternalFolder(): void {
		$this->service->changeVisibility($this->viewer, $this->ticketId, 1, TicketScope::VISIBILITY_INTERNAL);

		$this->assertTrue($this->internalFolder->nodeExists(self::FILE), 'Die Datei liegt nicht im internen Ordner.');
		$this->assertFalse($this->publicFolder->nodeExists(self::FILE), 'Die Datei liegt noch im öffentlichen Ordner.');

		$attachment = $this->attachmentOf($this->ticketId);
		$this->assertSame(Attachment::LOCATION_INTERNAL, $attachment->getLocation());

		$node = $this->home->getFirstNodeById((int)$attachment->getFileId());
		$this->assertNotNull($node, 'Die Datei ist über ihre ID nicht mehr erreichbar.');
		$this->assertSame('PDFINHALT', $node->getContent(), 'Der Inhalt hat den Umzug nicht überlebt.');
	}

	/**
	 * **Hochstufen zieht die Datei in den offeneren Ordner.**
	 *
	 * Die Gegenrichtung, aus dem internen Stand heraus: Nach `internal → public`
	 * liegt die Datei im Austauschordner, den die Kundenseite sieht.
	 */
	public function testUpgradeMovesTheFileToThePublicFolder(): void {
		$internal = $this->service->changeVisibility($this->viewer, $this->ticketId, 1, TicketScope::VISIBILITY_INTERNAL);
		$this->service->changeVisibility($this->viewer, $this->ticketId, (int)$internal->getVersion(), TicketScope::VISIBILITY_PUBLIC);

		$this->assertTrue($this->publicFolder->nodeExists(self::FILE), 'Die Datei liegt nicht im öffentlichen Ordner.');
		$this->assertFalse($this->internalFolder->nodeExists(self::FILE), 'Die Datei liegt noch im internen Ordner.');

		$attachment = $this->attachmentOf($this->ticketId);
		$this->assertSame(Attachment::LOCATION_PUBLIC, $attachment->getLocation());
		$this->assertNotNull($this->home->getFirstNodeById((int)$attachment->getFileId()));
	}
}
