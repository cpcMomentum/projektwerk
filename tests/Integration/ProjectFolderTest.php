<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Service\BoardService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Server;

/**
 * Der Projektordner (#351): über die Datei-ID verankert, der Pfad nur zur Anzeige.
 */
class ProjectFolderTest extends IntegrationTestCase {

	private const UID = 'admin';
	private const FOLDER = 'pwtest_projektordner';

	private Folder $home;
	private BoardService $boards;

	protected function setUp(): void {
		parent::setUp();

		$this->home = Server::get(IRootFolder::class)->getUserFolder(self::UID);
		$this->removeFolder();
		$this->boards = Server::get(BoardService::class);
	}

	protected function tearDown(): void {
		$this->removeFolder();

		parent::tearDown();
	}

	public function testTheProjectFolderIsStoredByIdAndShownByPath(): void {
		$folder = $this->home->newFolder(self::FOLDER);
		$viewer = $this->viewer();

		$this->boards->update($viewer, ['folderRootPath' => '/' . self::FOLDER . '//']);

		$this->assertSame(['id' => (int)$folder->getId(), 'path' => self::FOLDER, 'reachable' => true], $this->boards->projectFolder($viewer));
	}

	public function testThePathIsResolvedInTheViewersTreeNotTakenFromStorage(): void {
		$folder = $this->home->newFolder(self::FOLDER);
		$viewer = $this->viewer();
		$this->boards->update($viewer, ['folderRootPath' => self::FOLDER]);
		$id = (int)$folder->getId();

		$this->home->get(self::FOLDER)->move($this->home->getPath() . '/' . self::FOLDER . '_moved');

		$this->assertSame(['id' => $id, 'path' => self::FOLDER . '_moved', 'reachable' => true], $this->boards->projectFolder($viewer));
	}

	public function testAnUnreachableProjectFolderKeepsTheStoredPathAndSaysSo(): void {
		$folder = $this->home->newFolder(self::FOLDER);
		$viewer = $this->viewer();
		$this->boards->update($viewer, ['folderRootPath' => self::FOLDER]);
		$id = (int)$folder->getId();

		$folder->delete();

		$this->assertSame(['id' => $id, 'path' => self::FOLDER, 'reachable' => false], $this->boards->projectFolder($viewer));
	}

	public function testAnEmptyPathClearsTheProjectFolder(): void {
		$this->home->newFolder(self::FOLDER);
		$viewer = $this->viewer();
		$this->boards->update($viewer, ['folderRootPath' => self::FOLDER]);

		$this->boards->update($viewer, ['folderRootPath' => '']);

		$this->assertNull($this->boards->projectFolder($viewer));
	}

	public function testANewProjectHasNoProjectFolder(): void {
		$this->assertNull($this->boards->projectFolder($this->viewer()));
	}

	private function viewer(): ViewerContext {
		$board = $this->boards->create(self::UID, 'Projektordner-Test');

		return Server::get(BoardAccess::class)->contextFor(self::UID, (int)$board->getId());
	}

	private function removeFolder(): void {
		foreach ([self::FOLDER, self::FOLDER . '_moved'] as $name) {
			try {
				$this->home->get($name)->delete();
			} catch (NotFoundException) {
			}
		}
	}
}
