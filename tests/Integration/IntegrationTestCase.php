<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Basis der DB-gestuetzten Suite.
 *
 * **Der Schalter `PWERK_REQUIRE_DB` ist der Kern dieser Klasse.** Ohne
 * laufende Nextcloud kann diese Suite nicht pruefen, was sie pruefen soll — und
 * ein Test, der sich selbst ueberspringt, meldet gruen. Ein uebersprungener
 * Waechter ist schlimmer als gar keiner: Er erzeugt Vertrauen, ohne es zu
 * verdienen.
 *
 * Deshalb zwei Betriebsarten:
 *
 * | Umgebung | `PWERK_REQUIRE_DB` | Ohne Nextcloud |
 * |---|---|---|
 * | Arbeitsplatz, containerfrei | nicht gesetzt | uebersprungen |
 * | CI, Integrationsjob | `1` | **Fehler** |
 *
 * In der CI ist eine fehlende Datenbank damit ein roter Lauf und keine stille
 * Null. Genau diese Falle steckt im Vinarium-Muster, von dem diese Klasse sonst
 * abgeschaut ist.
 *
 * Jeder Testfall laeuft in einer Transaktion, die am Ende zurueckgerollt wird.
 * Die Alternative — von Hand abraeumen wie in `tests/manual/` — laesst bei einem
 * fehlgeschlagenen Test Zeilen stehen, und der naechste Lauf prueft dann gegen
 * Muell.
 */
abstract class IntegrationTestCase extends TestCase {

	protected IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();

		if (!class_exists('OC')) {
			if (getenv('PWERK_REQUIRE_DB') === '1') {
				$this->fail(implode("\n", [
					'Diese Suite verlangt eine laufende Nextcloud mit Datenbank, hat aber keine gefunden.',
					'',
					'PWERK_REQUIRE_DB=1 ist gesetzt — also ist Ueberspringen hier kein gueltiges',
					'Ergebnis. Ein uebersprungener Waechter meldet gruen und prueft nichts.',
					'',
					'Erwartet: tests/bootstrap.php laedt base.php aus NEXTCLOUD_ROOT (Vorgabe',
					'/var/www/html). Stimmt der Pfad?',
				]));
			}

			$this->markTestSkipped(
				'Benoetigt eine laufende Nextcloud mit Datenbank. '
				. 'In der CI setzt der Integrationsjob PWERK_REQUIRE_DB=1, dann ist das ein Fehler.',
			);
		}

		$this->db = Server::get(IDBConnection::class);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		if (isset($this->db) && $this->db->inTransaction()) {
			$this->db->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * Ein Projekt-Dach für ein noch **nicht** eingefügtes Board anlegen (#246).
	 *
	 * Setzt `project_id` am Board und gibt die Projekt-Id zurück; der Aufrufer
	 * trägt sie danach an Mitglieder und Tickets. Test-Fixtures gehen an den
	 * Services vorbei — seit der Sichtbarkeitsverbund über `project_id` läuft,
	 * müssen sie das Projekt selbst stellen, sonst findet er nichts.
	 *
	 * @param Board $board Das vorbereitete, noch nicht eingefügte Board.
	 */
	protected function projektFuerBoard(Board $board): int {
		$now = new \DateTime();
		$project = new Project();
		$project->setTitle((string)$board->getTitle());
		$project->setOwnerUserId((string)$board->getOwnerUserId());
		$project->setOrgInternal($board->getOrgInternal());
		$project->setOrgExternal($board->getOrgExternal());
		$project->setTicketCounter(0);
		$project->setArchived(0);
		$project->setCreatedAt($now);
		$project->setUpdatedAt($now);
		$projectId = (int)Server::get(ProjectMapper::class)->insert($project)->getId();
		$board->setProjectId($projectId);

		return $projectId;
	}
}
