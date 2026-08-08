<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;

/**
 * Zwei der drei mechanischen Waechter aus §3.4 des Plans. Beide sind
 * container-frei und laufen damit in jedem CI-Lauf, nicht nur dort, wo eine
 * Nextcloud steht.
 *
 * Sie pruefen keine Funktion, sondern eine **Bauform**. Der Unterschied ist
 * der Punkt: „Sichtbarkeitsfilter vergessen" ist die teuerste Fehlerklasse
 * dieses Produkts und faellt sonst erst beim Kunden auf, still. Ein Test, der
 * die Bauform festhaelt, faengt sie beim Schreiben.
 */
class ArchitectureTest extends TestCase {

	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Waechter 1: `pwerk_tickets` darf nur an drei Stellen stehen.
	 *
	 * Jede weitere Stelle waere ein zweiter Ort, an dem die Sichtbarkeitsregel
	 * stimmen muesste — und der zweite Ort ist der, der ein internes Ticket an
	 * den Kunden ausliefert.
	 */
	public function testTicketTableIsReferencedOnlyInAllowedFiles(): void {
		$allowed = [
			'Db/TicketMapper.php',
			'Access/TicketScope.php',
		];

		$offenders = [];
		foreach ($this->phpFilesIn(self::LIB) as $relative => $path) {
			if (str_starts_with($relative, 'Migration/')) {
				// Migrationen legen die Tabelle an; ohne den Namen geht das nicht.
				continue;
			}
			if (in_array($relative, $allowed, true)) {
				continue;
			}
			if (str_contains((string)file_get_contents($path), 'pwerk_tickets')) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame([], $offenders, implode("\n", [
			'Die Tabelle pwerk_tickets wird ausserhalb der erlaubten Stellen angefasst:',
			'  ' . implode(', ', $offenders),
			'',
			'Jede Ticket-Abfrage laeuft ueber TicketMapper, und der filtert ueber',
			'TicketScope. Eine eigene Abfrage waere ein zweiter Ort, an dem die',
			'Sichtbarkeitsregel stimmen muesste.',
		]));
	}

	/**
	 * Waechter 3: keine Admin-Ausnahme, mechanisch.
	 *
	 * §10 verspricht, dass auch Administrator und Board-Eigentuemer nichts
	 * sehen, was die Regel verbirgt. Hier wird aus dem Versprechen eine
	 * Invariante.
	 */
	public function testNoAdminBackdoorInLib(): void {
		$forbidden = ['isAdmin', 'IGroupManager'];

		$offenders = [];
		foreach ($this->phpFilesIn(self::LIB) as $relative => $path) {
			$content = (string)file_get_contents($path);
			foreach ($forbidden as $needle) {
				if (str_contains($content, $needle)) {
					$offenders[] = $relative . ' (' . $needle . ')';
				}
			}
		}

		$this->assertSame([], $offenders, implode("\n", [
			'Admin-Sonderpfad in lib/ gefunden:',
			'  ' . implode(', ', $offenders),
			'',
			'Es gibt keine Admin-Ausnahme — auch nicht fuer den Board-Eigentuemer.',
			'Eine Hintertuer wuerde die Zusage entwerten, auf der das Produkt beruht.',
		]));
	}

	/**
	 * Ergaenzung zu Waechter 1: die einzige Tuer zu `ViewerContext` bleibt
	 * `BoardAccess`.
	 *
	 * PHP kann das nicht ausdruecken — eine statische Fabrik ist
	 * zwangslaeufig oeffentlich, auch wenn der Konstruktor privat ist. Also
	 * wird die Luecke hier bewacht statt wegargumentiert.
	 */
	public function testViewerContextIsCreatedOnlyByBoardAccess(): void {
		$offenders = [];
		foreach ($this->phpFilesIn(self::LIB) as $relative => $path) {
			if ($relative === 'Access/BoardAccess.php' || $relative === 'Access/ViewerContext.php') {
				continue;
			}
			if (str_contains((string)file_get_contents($path), 'ViewerContext::forMember')) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame([], $offenders, implode("\n", [
			'ViewerContext wird ausserhalb von BoardAccess erzeugt:',
			'  ' . implode(', ', $offenders),
			'',
			'Ein selbst gebauter Kontext ist ein ungeprueftes Zugriffsrecht.',
			'Der Weg fuehrt ueber BoardAccess::contextFor(), das pwerk_members liest.',
		]));
	}

	/**
	 * Der TicketMapper darf keine kontextfreie Lesemethode haben.
	 *
	 * `findAll()` oder `find(int $id)` waeren genau die bequemen Abkuerzungen,
	 * gegen die die ganze Bauform gerichtet ist.
	 */
	public function testTicketMapperHasNoContextFreeRead(): void {
		$mapper = self::LIB . '/Db/TicketMapper.php';
		if (!is_file($mapper)) {
			$this->markTestSkipped('TicketMapper existiert noch nicht (folgt in der naechsten Scheibe von #5).');
		}

		$content = (string)file_get_contents($mapper);
		$this->assertDoesNotMatchRegularExpression(
			'/function\s+(findAll|find)\s*\(\s*(int|\$)/',
			$content,
			'TicketMapper hat eine kontextfreie Lesemethode. Jede Signatur beginnt mit ViewerContext.',
		);
	}

	/**
	 * @return iterable<string, string> relativer Pfad => absoluter Pfad
	 */
	private function phpFilesIn(string $dir): iterable {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$path = $file->getPathname();
			yield ltrim(str_replace(realpath($dir), '', realpath($path)), '/') => $path;
		}
	}
}
