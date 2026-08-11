<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Service\ProjectFolderService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;

/**
 * Der Ablageort eines Vorgangs — und wer ihn nicht bekommt.
 *
 * **Die Zuordnung Sichtbarkeit → Ordner ist die Stelle, an der die
 * Sichtbarkeit physisch wird** (§3.10). Sie ist kein Datenbankfilter mehr,
 * sondern eine Entscheidung darüber, in welchem Verzeichnis eine Datei landet
 * — und eine falsche Zeile hier heilt keine spätere Codekorrektur, weil die
 * Datei dann schon liegt, wo sie nicht hingehört.
 *
 * Deshalb wird jeder der vier Fälle einzeln geprüft, auch die beiden, in denen
 * die Antwort „gar nicht" lautet. Ein `null`, das versehentlich zu einem Ordner
 * würde, wäre genau das Leck, gegen das die ganze Bauform gerichtet ist.
 *
 * Ohne Datenbank: Die Frage ist eine an den Code.
 */
class ProjectFolderServiceTest extends TestCase {

	private ProjectFolderService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new ProjectFolderService($this->createStub(IRootFolder::class));
	}

	/**
	 * @param string $visibility Sichtbarkeit des Vorgangs.
	 * @param string $creatorRole Rolle der anlegenden Person.
	 */
	private function ticket(string $visibility, string $creatorRole): Ticket {
		$ticket = new Ticket();
		$ticket->setVisibility($visibility);
		$ticket->setCreatorRole($creatorRole);

		return $ticket;
	}

	/**
	 * Ein Vorgang für alle Beteiligten gehört in den Austauschordner —
	 * unabhängig davon, wer ihn angelegt hat.
	 */
	public function testPublicTicketsGoToTheSharedFolderWhoeverCreatedThem(): void {
		foreach ([ViewerContext::ROLE_INTERNAL, ViewerContext::ROLE_EXTERNAL] as $role) {
			$this->assertSame(
				Attachment::LOCATION_PUBLIC,
				$this->service->locationFor($this->ticket(TicketScope::VISIBILITY_PUBLIC, $role)),
				"Öffentlicher Vorgang, angelegt als $role",
			);
		}
	}

	/**
	 * Ein interner Vorgang der Dienstleisterseite gehört in den internen
	 * Ordner.
	 */
	public function testInternalTicketsOfTheProviderSideGoToTheInternalFolder(): void {
		$this->assertSame(
			Attachment::LOCATION_INTERNAL,
			$this->service->locationFor(
				$this->ticket(TicketScope::VISIBILITY_INTERNAL, ViewerContext::ROLE_INTERNAL),
			),
		);
	}

	/**
	 * **Ein interner Vorgang der Kundenseite bekommt keinen Ordner.**
	 *
	 * Der interne Ordner ist der der Dienstleisterseite; die Kundenseite kommt
	 * dort nicht heran. Ein Anhang, den die anlegende Person selbst nicht mehr
	 * öffnen kann, wäre kein Anhang, sondern ein Verlust — und läge dazu in
	 * einem Ordner, den die andere Seite sehr wohl liest.
	 */
	public function testInternalTicketsOfTheCustomerSideGetNoFolder(): void {
		$this->assertNull(
			$this->service->locationFor(
				$this->ticket(TicketScope::VISIBILITY_INTERNAL, ViewerContext::ROLE_EXTERNAL),
			),
		);
	}

	/**
	 * **„Nur ich" bekommt keinen Ordner** — es gibt keinen dritten, und einer
	 * der beiden vorhandenen wäre in jedem Fall zu weit offen.
	 */
	public function testPrivateTicketsGetNoFolder(): void {
		foreach ([ViewerContext::ROLE_INTERNAL, ViewerContext::ROLE_EXTERNAL] as $role) {
			$this->assertNull(
				$this->service->locationFor($this->ticket(TicketScope::VISIBILITY_PRIVATE, $role)),
				"Privater Vorgang, angelegt als $role",
			);
		}
	}

	public function testTheBoardFolderIdIsPickedPerLocation(): void {
		$board = new Board();
		$board->setFolderPublicId(11);
		$board->setFolderInternalId(22);

		$this->assertSame(11, $this->service->folderIdFor($board, Attachment::LOCATION_PUBLIC));
		$this->assertSame(22, $this->service->folderIdFor($board, Attachment::LOCATION_INTERNAL));
	}

	/**
	 * Ein Board ohne hinterlegten Ordner liefert `null` statt einer 0.
	 *
	 * Eine 0 wäre eine Datei-ID, die es zu suchen lohnte — und die Suche
	 * schlüge irgendwo weiter unten fehl, weit weg von der Ursache.
	 */
	public function testAMissingFolderIsNullAndNotZero(): void {
		$this->assertNull($this->service->folderIdFor(new Board(), Attachment::LOCATION_PUBLIC));
	}

	/**
	 * Eine Datei ist kein Ordner — und wird auch nicht als einer angenommen.
	 */
	public function testAFileIsRejected(): void {
		$service = $this->serviceResolving($this->createStub(File::class));

		$this->expectException(NotPermittedException::class);
		$service->resolvePath('lm-intern', 'Projekte/notiz.txt');
	}

	/**
	 * Ein nicht vorhandener Pfad ergibt **dieselbe** Meldung wie eine Datei.
	 *
	 * Absicht: Ob es den Ordner anderswo gibt, geht die fragende Person nichts
	 * an. Zwei unterscheidbare Meldungen wären ein Weg, den fremden Dateibaum
	 * abzutasten.
	 */
	public function testAMissingPathGivesTheSameAnswerAsAFile(): void {
		$home = $this->createStub(Folder::class);
		$home->method('get')->willThrowException(new NotFoundException());

		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Dieser Ordner ist nicht erreichbar.');
		(new ProjectFolderService($root))->resolvePath('lm-intern', 'Gibt/Es/Nicht');
	}

	/**
	 * Ein Ordner ohne Schreibrecht wird beim Eintragen abgewiesen, nicht erst
	 * beim ersten Anhang.
	 *
	 * Sonst fiele der Fehler bei einer anderen Person auf als bei der, die ihn
	 * verursacht hat — womöglich Wochen später.
	 */
	public function testAReadOnlyFolderIsRejectedWhileItIsBeingSet(): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('isCreatable')->willReturn(false);

		$service = $this->serviceResolving($folder);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('In diesen Ordner darf nicht geschrieben werden.');
		$service->resolvePath('lm-intern', 'Fremd/Ordner');
	}

	/**
	 * Ein leerer Pfad ist keine Auflösung, sondern eine fehlende Angabe.
	 */
	public function testAnEmptyPathIsRefusedBeforeAnyLookup(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');

		$this->expectException(NotPermittedException::class);
		(new ProjectFolderService($root))->resolvePath('lm-intern', '   /  ');
	}

	/**
	 * Der Anzeigepfad ist der Pfad **ohne** den Kopf des Dateibaums.
	 *
	 * `/lm-intern/files/Projekte/90_Austausch` ist die interne Adresse; in den
	 * Einstellungen steht, was die Person in ihren Dateien sieht.
	 */
	public function testTheDisplayPathDropsTheHomePrefix(): void {
		$home = $this->createStub(Folder::class);
		$home->method('getPath')->willReturn('/lm-intern/files');

		$folder = $this->createStub(Folder::class);
		$folder->method('getPath')->willReturn('/lm-intern/files/Projekte/90_Austausch');

		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		$this->assertSame(
			'Projekte/90_Austausch',
			(new ProjectFolderService($root))->displayPath('lm-intern', $folder),
		);
	}

	/**
	 * @param object $node Was der Dateibaum zurückgeben soll.
	 */
	private function serviceResolving(object $node): ProjectFolderService {
		$home = $this->createStub(Folder::class);
		$home->method('get')->willReturn($node);

		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		return new ProjectFolderService($root);
	}
}
