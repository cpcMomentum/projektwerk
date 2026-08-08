<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Db\TicketChildMapper;
use OCA\Projektwerk\Db\TicketMapper;
use ReflectionClass;

/**
 * Prueft die Suite gegen sich selbst: Laeuft sie ueberhaupt gegen den Code, der
 * hier daneben liegt?
 *
 * Der Anlass ist ein konkreter Fehlschlag beim Bau der Matrix. Die Suite lief
 * gruen — gegen eine **andere** Kopie der App. Nextcloud registriert beim
 * Hochfahren einen eigenen Autoloader fuer `OCA\Projektwerk\`, und weil
 * `composer.json` mit `optimize-autoloader` arbeitet, stehen in der Classmap
 * absolute Pfade vom Rechner, auf dem sie erzeugt wurde. Zeigen die ins Leere,
 * faellt Composer stillschweigend durch und Nextclouds Autoloader liefert die
 * **installierte** App.
 *
 * Das ist die schlimmste Sorte Fehler: Absichtlich eingebaute Verstoesse gegen
 * die Sichtbarkeitsregel blieben gruen, weil sie in Dateien standen, die nie
 * geladen wurden. Ein Waechter, der am falschen Ort wacht, ist von einem
 * funktionierenden nicht zu unterscheiden — bis er gebraucht wird.
 *
 * Dieser Test kostet nichts und laeuft vor der Matrix.
 */
class SuiteWiringTest extends IntegrationTestCase {

	/**
	 * Die geladenen Klassen stammen aus dem `lib/` neben diesem `tests/`.
	 */
	public function testLoadedClassesComeFromThisCheckout(): void {
		$expectedLib = realpath(dirname(__DIR__, 2) . '/lib');

		$this->assertNotFalse($expectedLib, 'lib/ neben tests/ nicht gefunden.');

		foreach ([TicketScope::class, TicketMapper::class, TicketChildMapper::class, BoardAccess::class] as $class) {
			$file = (new ReflectionClass($class))->getFileName();

			$this->assertNotFalse($file, $class . ' hat keine Datei.');
			$this->assertStringStartsWith(
				$expectedLib . DIRECTORY_SEPARATOR,
				(string)$file,
				implode("\n", [
					$class . ' wird aus einer anderen Kopie der App geladen:',
					'  geladen:  ' . $file,
					'  erwartet: ' . $expectedLib . '/…',
					'',
					'Die Suite pruefte damit fremden Code. Die App muss unter apps/ bzw.',
					'custom_apps/ der Nextcloud liegen, gegen die getestet wird — nicht',
					'daneben. Siehe Klassenkommentar.',
				]),
			);
		}
	}

	/**
	 * Die Migration ist gelaufen, die Tabellen sind da.
	 *
	 * Ohne diesen Test meldet die Matrix bei fehlendem Schema einen SQL-Fehler,
	 * der nach einem kaputten Mapper aussieht statt nach einer nicht
	 * aktivierten App.
	 */
	public function testTheSchemaIsPresent(): void {
		// `tableExists()` und nicht `createSchema()->hasTable()`: Nextcloud
		// stellt jeder Tabelle das konfigurierte Praefix voran (`oc_` in der
		// Vorgabe). Der Schema-Weg fragt mit dem rohen Namen und meldet deshalb
		// „fehlt" fuer Tabellen, die es gibt.
		foreach ([
			'pwerk_boards',
			'pwerk_members',
			'pwerk_columns',
			'pwerk_tickets',
			'pwerk_ticket_users',
			'pwerk_steps',
			'pwerk_comments',
			'pwerk_attachments',
		] as $table) {
			$this->assertTrue(
				$this->db->tableExists($table),
				'Tabelle ' . $table . ' fehlt — ist die App aktiviert (`occ app:enable projektwerk`)?',
			);
		}
	}
}
