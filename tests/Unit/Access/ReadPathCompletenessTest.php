<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Access;

use OCA\Projektwerk\Tests\ReadPathRegistry;
use OCP\AppFramework\Db\QBMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Der Vollstaendigkeitstest der Leak-Matrix — die Haelfte, die **ohne**
 * Datenbank auskommt.
 *
 * Die Matrix selbst braucht echte Zeilen und laeuft deshalb in der
 * Integrationssuite. Die Frage „gibt es einen Lesepfad, zu dem niemand eine
 * Erwartung formuliert hat?" braucht sie nicht: Sie ist eine Frage an den Code,
 * nicht an die Daten. Deshalb steht sie hier, laeuft in jedem PR mit und kostet
 * nichts.
 *
 * Das ist die eigentliche Verteidigung. Eine Matrix, die grosszuegig prueft, aber
 * einen neuen Lesepfad nicht bemerkt, waere in dem Moment nutzlos, in dem sie
 * gebraucht wird — beim sechsten Lesepfad, den jemand in Phase 4 nachtraegt.
 *
 * Zwei der Tests pruefen nicht den Code, sondern **den Waechter**: Sie fuehren
 * dieselbe Pruefroutine gegen einen kuenstlich unvollstaendigen Eingang und
 * verlangen, dass sie anschlaegt. Damit ist das Akzeptanzkriterium aus #5 („der
 * Vollstaendigkeitstest scheitert nachweislich") ein Test statt einer
 * Vorfuehrung von Hand.
 */
class ReadPathCompletenessTest extends TestCase {

	private const DB_DIR = __DIR__ . '/../../../lib/Db';
	private const ROUTES_FILE = __DIR__ . '/../../../appinfo/routes.php';

	/**
	 * Jeder Lesepfad in `lib/Db/` steht in der Matrix.
	 *
	 * Das ist die Richtung, die zubeisst: Wer eine Lesemethode hinzufuegt, muss
	 * eine Erwartung dazu formulieren — fuer jeden der vier Betrachter. Genau
	 * dieser Zwang ist der Unterschied zwischen einer Matrix und einer
	 * Sammlung von Tests, die zufaellig existieren.
	 */
	public function testEveryMapperReadPathIsRegistered(): void {
		$missing = array_values(array_diff($this->discoverMapperReadPaths(), ReadPathRegistry::MAPPER_PATHS));

		$this->assertSame([], $missing, implode("\n", [
			'Lesepfad ohne Eintrag in der Leak-Matrix:',
			'  ' . implode("\n  ", $missing),
			'',
			'Jeder Lesepfad braucht eine Erwartung je Betrachter. Eintragen in',
			'tests/ReadPathRegistry.php::MAPPER_PATHS und in LeakMatrixTest eine',
			'Erwartung ergaenzen — nicht umgekehrt.',
		]));
	}

	/**
	 * Und kein Eintrag ohne Methode.
	 *
	 * Ein Eintrag, der ins Leere zeigt, ist eine Erwartung, die niemand mehr
	 * prueft — die Matrix waere dann breiter, als sie deckt.
	 */
	public function testNoRegisteredPathIsStale(): void {
		$stale = array_values(array_diff(ReadPathRegistry::MAPPER_PATHS, $this->discoverMapperReadPaths()));

		$this->assertSame([], $stale, implode("\n", [
			'Eintrag in der Matrix ohne zugehoerige Methode:',
			'  ' . implode(', ', $stale),
		]));
	}

	/**
	 * Jede Lese-Route ist registriert oder ausdruecklich als datenlos vermerkt.
	 *
	 * `page#index` steht als datenlos vermerkt, `board#index` und `board#show`
	 * stehen in der Matrix. Der Waechter zwingt jede weitere GET-Route in eine
	 * der beiden Listen.
	 */
	public function testEveryReadRouteIsRegisteredOrExempt(): void {
		$unregistered = $this->unregisteredRoutes(
			$this->readRoutesFromFile(),
			ReadPathRegistry::ROUTE_PATHS,
			array_keys(ReadPathRegistry::ROUTES_WITHOUT_DATA),
		);

		$this->assertSame([], $unregistered, implode("\n", [
			'GET-Route, die weder in der Matrix steht noch als datenlos vermerkt ist:',
			'  ' . implode(', ', $unregistered),
			'',
			'Entweder in ReadPathRegistry::ROUTE_PATHS eintragen und in der Matrix',
			'eine Erwartung je Betrachter ergaenzen — oder in ROUTES_WITHOUT_DATA',
			'mit Begruendung. Eine Ausnahme ohne Grund gibt es nicht.',
		]));
	}

	/**
	 * Der Waechter beisst zu — an einer Route.
	 *
	 * Akzeptanzkriterium aus #5, mechanisch statt vorgefuehrt: dieselbe
	 * Pruefroutine, ein kuenstlicher Eingang mit einer nicht registrierten
	 * Route.
	 */
	public function testTheRouteGuardBitesOnAnUnregisteredRoute(): void {
		$unregistered = $this->unregisteredRoutes(
			['board#index', 'ticket#index', 'page#index'],
			['board#index'],
			['page#index'],
		);

		$this->assertSame(
			['ticket#index'],
			$unregistered,
			'Der Vollstaendigkeitstest haette die nicht registrierte Route melden muessen.',
		);
	}

	/**
	 * Der Waechter beisst zu — an einer Mapper-Methode.
	 *
	 * Dieselbe Mengenoperation wie oben, gegen einen kuenstlichen Eingang. Faellt
	 * dieser Test, ist der Vergleich in `testEveryMapperReadPathIsRegistered`
	 * kaputt und meldet nichts mehr — ein Waechter, der immer gruen ist.
	 */
	public function testTheMapperGuardBitesOnAnUnregisteredMethod(): void {
		$discovered = ['TicketMapper::findVisible', 'TicketMapper::findNewSecretPath'];
		$registered = ['TicketMapper::findVisible'];

		$this->assertSame(
			['TicketMapper::findNewSecretPath'],
			array_values(array_diff($discovered, $registered)),
		);
	}

	/**
	 * Die Matrix deckt alle vier Kinder-Mapper einzeln ab.
	 *
	 * Eine Erwartung an `TicketChildMapper` waere eine Erwartung an niemanden —
	 * die abstrakte Klasse wird nie instanziiert. Der Test haelt fest, dass die
	 * Registry die konkreten Klassen nennt.
	 */
	public function testChildMappersAreRegisteredIndividually(): void {
		foreach (['CommentMapper', 'StepMapper', 'AttachmentMapper', 'TicketUserMapper'] as $mapper) {
			foreach (['findForTickets', 'countForTickets'] as $method) {
				$this->assertContains(
					$mapper . '::' . $method,
					ReadPathRegistry::MAPPER_PATHS,
					$mapper . '::' . $method . ' fehlt in der Matrix.',
				);
			}
		}

		$this->assertNotContains(
			'TicketChildMapper::findForTickets',
			ReadPathRegistry::MAPPER_PATHS,
			'Die abstrakte Basis gehoert nicht in die Matrix — sie wird nie instanziiert.',
		);
	}

	/**
	 * Die Mengenoperation hinter dem Routen-Waechter, isoliert und damit auch
	 * gegen einen kuenstlichen Eingang pruefbar.
	 *
	 * @param string[] $found      Routennamen aus routes.php
	 * @param string[] $registered Routennamen aus der Matrix
	 * @param string[] $exempt     Routennamen ohne fachliche Daten
	 * @return string[]
	 */
	private function unregisteredRoutes(array $found, array $registered, array $exempt): array {
		return array_values(array_diff($found, $registered, $exempt));
	}

	/**
	 * Alle GET-Routen aus `appinfo/routes.php`.
	 *
	 * Die Datei wird **ausgefuehrt**, nicht mit einem regulaeren Ausdruck
	 * gelesen: Ein `include` liefert genau das, was Nextcloud auch bekommt. Eine
	 * Textsuche wuerde an einer auskommentierten Zeile haengenbleiben — und
	 * genau davon stehen dort mehrere als Beispiel.
	 *
	 * @return string[]
	 */
	private function readRoutesFromFile(): array {
		$definition = require self::ROUTES_FILE;

		$this->assertIsArray($definition, 'routes.php liefert kein Array.');

		$names = [];
		foreach ($definition['routes'] ?? [] as $route) {
			if (strtoupper((string)($route['verb'] ?? 'GET')) !== 'GET') {
				continue;
			}
			$names[] = (string)$route['name'];
		}

		return $names;
	}

	/**
	 * Alle oeffentlichen Lesemethoden der konkreten Mapper, als
	 * `KurzeKlasse::methode`.
	 *
	 * Geerbtes zaehlt mit, solange es aus unserem Namensraum stammt: Die vier
	 * Kinder-Mapper erben `findForTickets()` von `TicketChildMapper`, und jeder
	 * von ihnen ist ein eigener Lesepfad mit eigener Erwartung. Was aus
	 * `QBMapper` kommt (`insert`, `update`, `delete`, `getTableName`), bleibt
	 * aussen vor.
	 *
	 * @return string[] sortiert, damit die Meldung stabil ist
	 */
	private function discoverMapperReadPaths(): array {
		$paths = [];

		foreach (glob(self::DB_DIR . '/*Mapper.php') ?: [] as $file) {
			$class = 'OCA\\Projektwerk\\Db\\' . basename($file, '.php');
			if (!class_exists($class) || !is_subclass_of($class, QBMapper::class)) {
				continue;
			}

			$reflection = new ReflectionClass($class);
			// Die abstrakte Basis wird nie instanziiert; ihre Methoden erscheinen
			// bei jedem der vier Kinder und werden dort gezaehlt.
			if ($reflection->isAbstract()) {
				continue;
			}

			foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->isConstructor()) {
					continue;
				}
				if (!str_starts_with($method->getDeclaringClass()->getName(), 'OCA\\Projektwerk\\')) {
					continue;
				}
				if (!$this->looksLikeRead($method->getName())) {
					continue;
				}
				$paths[] = $reflection->getShortName() . '::' . $method->getName();
			}
		}

		sort($paths);

		return $paths;
	}

	private function looksLikeRead(string $method): bool {
		foreach (ReadPathRegistry::READ_METHOD_PREFIXES as $prefix) {
			if (str_starts_with($method, $prefix)) {
				return true;
			}
		}

		return false;
	}
}
