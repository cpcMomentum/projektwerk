<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

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
use OCA\Projektwerk\Repair\RelocateAttachments;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Server;

/**
 * **Der Reparaturschritt zieht einen fehlplatzierten Anhang nach** (#188) — gegen
 * echte Ordner, aus demselben Grund wie {@see AttachmentRelocationTest}: Nur ein
 * echter Nutzer (`admin`) hat einen Dateibaum, in dem eine Datei wirklich wandern
 * kann.
 *
 * Der Ausgangszustand ist der abgebrochene Umzug aus #185: Der Vorgang steht auf
 * `public`, die Datei liegt aber (mitsamt `location = internal`) noch im internen
 * Ordner — so, als sei der Prozess mitten im Umzug gestorben. Der Schritt bringt
 * beide wieder in Einklang.
 *
 * Die Dateien liegen **ausserhalb** der Transaktion; {@see tearDown()} raeumt sie
 * von Hand, {@see setUp()} loescht einen etwaigen Rest zuerst.
 */
class AttachmentReconcileTest extends IntegrationTestCase {

	private const UID = 'admin';
	private const FILE = '0001_angebot.pdf';

	private Folder $home;
	private Folder $publicFolder;
	private Folder $internalFolder;
	private AttachmentMapper $attachments;
	private RelocateAttachments $repair;
	private int $ticketId;
	private int $attachmentId;

	protected function setUp(): void {
		parent::setUp();

		$this->home = Server::get(IRootFolder::class)->getUserFolder(self::UID);
		$this->removeFolders();
		$this->publicFolder = $this->home->newFolder('pwtest_public');
		$this->internalFolder = $this->home->newFolder('pwtest_internal');

		$board = new Board();
		$board->setTitle('Reparatur-Test');
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

		// Der Vorgang ist **oeffentlich** — sein Anhang gehoert in den
		// oeffentlichen Ordner.
		$ticket = new Ticket();
		$ticket->setBoardId($boardId);
		$ticket->setColumnId($columnId);
		$ticket->setNumber(1);
		$ticket->setTitle('Anhang haengt hinterher');
		$ticket->setVisibility(TicketScope::VISIBILITY_PUBLIC);
		$ticket->setCreatorUserId(self::UID);
		$ticket->setCreatorRole(ViewerContext::ROLE_INTERNAL);
		$ticket->setResponsibleUserId(self::UID);
		$ticket->setPosition(65536);
		$ticket->setVersion(1);
		$ticket->setCreatedAt(new \DateTime());
		$ticket->setUpdatedAt(new \DateTime());
		$this->ticketId = (int)Server::get(TicketMapper::class)->insert($ticket)->getId();

		// **Der abgebrochene Umzug:** Datei liegt im INTERNEN Ordner und der
		// Anhang traegt `location = internal`, obwohl der Vorgang oeffentlich ist.
		$file = $this->internalFolder->newFile(self::FILE, 'PDFINHALT');

		$this->attachments = Server::get(AttachmentMapper::class);
		$attachment = new Attachment();
		$attachment->setTicketId($this->ticketId);
		$attachment->setFileId($file->getId());
		$attachment->setFilePath('pwtest_internal/' . self::FILE);
		$attachment->setFileName(self::FILE);
		$attachment->setLocation(Attachment::LOCATION_INTERNAL);
		$attachment->setUploadedBy(self::UID);
		$attachment->setCreatedAt(new \DateTime());
		$this->attachmentId = (int)$this->attachments->insert($attachment)->getId();

		$this->repair = Server::get(RelocateAttachments::class);
	}

	protected function tearDown(): void {
		$this->removeFolders();

		parent::tearDown();
	}

	private function removeFolders(): void {
		foreach (['pwtest_public', 'pwtest_internal', 'ProjektWerk'] as $name) {
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
	 * **Der Kern:** Der Schritt zieht die Datei in den oeffentlichen Ordner und
	 * bringt `location` in Einklang mit der Sichtbarkeit des Vorgangs.
	 */
	public function testReconcileMovesTheMisplacedFileToTheCorrectFolder(): void {
		$ergebnis = $this->repair->reconcile();

		$this->assertSame(1, $ergebnis['healed'], 'Der fehlplatzierte Anhang wurde nicht nachgezogen.');
		$this->assertSame(0, $ergebnis['skipped']);

		$this->assertTrue($this->publicFolder->nodeExists(self::FILE), 'Die Datei liegt nicht im oeffentlichen Ordner.');
		$this->assertFalse($this->internalFolder->nodeExists(self::FILE), 'Die Datei liegt noch im internen Ordner.');

		$attachment = $this->attachmentOf($this->ticketId);
		$this->assertSame(Attachment::LOCATION_PUBLIC, $attachment->getLocation(), 'Der gespeicherte Ort wurde nicht nachgezogen.');

		$node = $this->home->getFirstNodeById((int)$attachment->getFileId());
		$this->assertNotNull($node, 'Die Datei ist ueber ihre neue ID nicht erreichbar.');
		$this->assertSame('PDFINHALT', $node->getContent(), 'Der Inhalt hat den Umzug nicht ueberlebt.');
	}

	/**
	 * **Zweiter Lauf ist ein No-op** (Idempotenz): Nach dem Heilen passt jeder
	 * Ort zu seiner Sichtbarkeit, es gibt nichts mehr nachzuziehen.
	 */
	public function testASecondRunHealsNothing(): void {
		$this->repair->reconcile();

		$ergebnis = $this->repair->reconcile();

		$this->assertSame(0, $ergebnis['healed'], 'Der zweite Lauf hat unnoetig verschoben.');
	}

	/**
	 * **Uploader ist weg → ueberspringen, nicht abbrechen** (die einfache
	 * Variante). Der Move braucht den Dateibaum der anlegenden Person; ist sie
	 * kein Mitglied mehr, laesst sich der Anhang nicht in ihrem Namen ziehen. Er
	 * bleibt unangetastet (fail-closed) und wird uebersprungen — der Lauf faellt
	 * darueber nicht um.
	 */
	public function testAnAttachmentWhoseUploaderLeftIsSkipped(): void {
		$attachment = $this->attachmentOf($this->ticketId);
		$attachment->setUploadedBy('pw-ghost-kein-mitglied');
		$this->attachments->update($attachment);

		$ergebnis = $this->repair->reconcile();

		$this->assertSame(0, $ergebnis['healed'], 'Ohne erreichbaren Uploader darf nichts gezogen werden.');
		$this->assertSame(1, $ergebnis['skipped'], 'Der Anhang haette uebersprungen gezaehlt werden muessen.');

		// Unangetastet: Datei bleibt, wo sie war, der gespeicherte Ort ebenso.
		$this->assertTrue($this->internalFolder->nodeExists(self::FILE), 'Die Datei wurde trotzdem verschoben.');
		$this->assertFalse($this->publicFolder->nodeExists(self::FILE));
		$this->assertSame(Attachment::LOCATION_INTERNAL, $this->attachmentOf($this->ticketId)->getLocation());
	}
}
