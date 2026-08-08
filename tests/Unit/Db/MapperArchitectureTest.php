<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Db;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TicketChildMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCP\AppFramework\Db\QBMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Die Bauform der Zugriffsschicht, mechanisch festgehalten.
 *
 * `ArchitectureTest` bewacht, **wo** die Ticket-Tabelle stehen darf. Hier geht
 * es um die Signaturen: Eine Lesemethode, die eine nackte ID aus dem Request
 * entgegennimmt, ist genau die bequeme Abkuerzung, gegen die die ganze Bauform
 * gerichtet ist — und sie liesse sich in einer stillen Zeile nachtragen.
 */
class MapperArchitectureTest extends TestCase {

	private const DB_DIR = __DIR__ . '/../../../lib/Db';

	private const CHILD_MAPPERS = [
		CommentMapper::class,
		StepMapper::class,
		AttachmentMapper::class,
		TicketUserMapper::class,
	];

	/**
	 * Kein Mapper nimmt eine nackte ID als erstes Argument.
	 *
	 * `find(int $id)`, `findForBoard(int $boardId)`, `findForTicket(int $id)` —
	 * jede dieser Signaturen sagt: „Die Berechtigung hat jemand anders
	 * geprueft." Genau diese Annahme faellt beim sechsten Lesepfad um. Erlaubt
	 * ist, was den Nachweis mitbringt: ein {@see ViewerContext}, eine
	 * Benutzerkennung oder eine bereits gefilterte ID-Menge.
	 */
	public function testNoMapperReadTakesABareIdFirst(): void {
		$offenders = [];

		foreach ($this->mapperClasses() as $class) {
			foreach ($this->declaredPublicReads($class) as $method) {
				$parameters = $method->getParameters();
				if ($parameters === []) {
					continue;
				}
				$type = $parameters[0]->getType();
				if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
					$offenders[] = $class . '::' . $method->getName() . '(int $' . $parameters[0]->getName() . ')';
				}
			}
		}

		$this->assertSame([], $offenders, implode("\n", [
			'Lesemethode mit nackter ID als erstem Argument:',
			'  ' . implode(', ', $offenders),
			'',
			'Das erste Argument traegt den Nachweis, dass die Berechtigung geprueft',
			'wurde: ViewerContext, Benutzerkennung oder eine gefilterte ID-Menge.',
		]));
	}

	/**
	 * Jede Lesemethode des TicketMapper beginnt mit dem Kontext.
	 *
	 * Das ist die Zusage aus §3.1 — „Sichtbarkeitsfilter vergessen" wird zum
	 * Typfehler statt zum Review-Versaeumnis. Sie gilt nur, solange es keine
	 * Ausnahme gibt.
	 */
	public function testEveryTicketReadStartsWithAContext(): void {
		$allowed = [ViewerContext::class, 'string'];

		$offenders = [];
		foreach ($this->declaredPublicReads(TicketMapper::class) as $method) {
			$parameters = $method->getParameters();
			$type = $parameters === [] ? null : $parameters[0]->getType();
			$name = $type instanceof ReflectionNamedType ? $type->getName() : '(kein Typ)';
			if (!in_array($name, $allowed, true)) {
				$offenders[] = $method->getName() . ' beginnt mit ' . $name;
			}
		}

		$this->assertSame([], $offenders, implode("\n", $offenders));
		$this->assertGreaterThanOrEqual(
			4,
			count(iterator_to_array($this->declaredPublicReads(TicketMapper::class))),
			'Der Plan nennt vier Lesemethoden; weniger heisst, eine fehlt.',
		);
	}

	/**
	 * Die Kinder bleiben Kinder.
	 *
	 * Sie erben genau eine Lesesignatur und duerfen keine zweite nachtragen —
	 * sonst waere „Kommentare zu Ticket 42" wieder moeglich, an der gefilterten
	 * Ticket-Menge vorbei.
	 */
	public function testChildMappersAddNoOwnReadMethods(): void {
		$offenders = [];

		foreach (self::CHILD_MAPPERS as $class) {
			$reflection = new ReflectionClass($class);
			$this->assertTrue(
				$reflection->isSubclassOf(TicketChildMapper::class),
				$class . ' erbt nicht von TicketChildMapper.',
			);

			foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				if ($method->isConstructor()) {
					continue;
				}
				$offenders[] = $class . '::' . $method->getName();
			}
		}

		$this->assertSame([], $offenders, implode("\n", [
			'Kinder-Mapper mit eigener oeffentlicher Methode:',
			'  ' . implode(', ', $offenders),
			'',
			'Die einzige Lesesignatur ist die geerbte findForTickets(int[]).',
		]));
	}

	/**
	 * Die Basisklasse selbst darf nicht aufweichen, was sie durchsetzt.
	 */
	public function testChildBaseExposesOnlySetReads(): void {
		$reflection = new ReflectionClass(TicketChildMapper::class);

		$own = [];
		foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== TicketChildMapper::class) {
				continue;
			}
			if ($method->isConstructor()) {
				continue;
			}
			$own[] = $method->getName();
		}
		sort($own);

		$this->assertSame(['countForTickets', 'findForTickets'], $own);
	}

	/**
	 * Alle Mapper-Klassen unter `lib/Db`.
	 *
	 * Bewusst ueber das Verzeichnis statt ueber eine Liste: Ein neuer Mapper
	 * soll von den Regeln erfasst werden, ohne dass jemand daran denkt.
	 *
	 * @return iterable<class-string<QBMapper>>
	 */
	private function mapperClasses(): iterable {
		foreach (glob(self::DB_DIR . '/*Mapper.php') ?: [] as $file) {
			$class = 'OCA\\Projektwerk\\Db\\' . basename($file, '.php');
			if (class_exists($class) && is_subclass_of($class, QBMapper::class)) {
				yield $class;
			}
		}
	}

	/**
	 * Selbst deklarierte oeffentliche Lesemethoden — Geerbtes aus QBMapper
	 * (`insert`, `update`, `delete`, …) bleibt aussen vor.
	 *
	 * @param class-string $class
	 * @return iterable<ReflectionMethod>
	 */
	private function declaredPublicReads(string $class): iterable {
		$reflection = new ReflectionClass($class);
		foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $class) {
				continue;
			}
			if ($method->isConstructor()) {
				continue;
			}
			if (!str_starts_with($method->getName(), 'find') && !str_starts_with($method->getName(), 'count')) {
				continue;
			}
			yield $method;
		}
	}
}
