<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Db;

use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\Comment;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TicketChildMapper;
use OCA\Projektwerk\Db\TicketUser;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Die beiden Teile von {@see TicketChildMapper}, die ohne Datenbank pruefbar
 * sind — und beide sind es wert.
 */
class TicketChildMapperTest extends TestCase {

	/**
	 * Eine leere Ticket-Menge fuehrt zu **keiner** Abfrage.
	 *
	 * Das ist der Normalfall fuer einen Betrachter, der nichts sehen darf, und
	 * er tritt bei jedem leeren Board auf. Ohne die Abkuerzung stuende dort ein
	 * `IN ()`, das die drei Datenbanken verschieden beantworten — SQLite still,
	 * MySQL mit Syntaxfehler.
	 */
	public function testEmptyTicketSetTouchesNoDatabase(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$mapper = new SortableTestChildMapper($db);

		$this->assertSame([], $mapper->findForTickets([]));
		$this->assertSame([], $mapper->countForTickets([]));
	}

	/**
	 * Sortiert wird nach Ticket, dann nach der Eigenschaft des Kindes, dann
	 * nach ID.
	 *
	 * Die dritte Stufe ist kein Beiwerk: Ohne sie waere die Reihenfolge zweier
	 * Schritte mit derselben Position von der Datenbank abhaengig — und damit
	 * zwischen SQLite, MySQL und PostgreSQL verschieden.
	 */
	public function testSortsByTicketThenPropertyThenId(): void {
		$mapper = new SortableTestChildMapper($this->createStub(IDBConnection::class));

		$sorted = $mapper->sortForTest([
			$this->step(id: 5, ticketId: 2, position: 65536),
			$this->step(id: 1, ticketId: 1, position: 131072),
			$this->step(id: 9, ticketId: 1, position: 65536),
			$this->step(id: 2, ticketId: 1, position: 65536),
		]);

		$this->assertSame(
			[[1, 65536, 2], [1, 65536, 9], [1, 131072, 1], [2, 65536, 5]],
			array_map(
				static fn (Step $s): array => [$s->getTicketId(), $s->getPosition(), $s->getId()],
				$sorted,
			),
		);
	}

	/**
	 * Die Sortierung ueberlebt Blockgrenzen.
	 *
	 * Ueber 1000 Ticket-IDs zerfaellt die Abfrage in Bloecke; die Datenbank
	 * ordnet dann nur innerhalb eines Blocks. Deshalb sortiert PHP — dieser
	 * Test haelt fest, dass die Sortierung eine bereits blockweise geordnete
	 * Liste tatsaechlich wieder zusammenfuehrt.
	 */
	public function testSortRepairsBlockwiseOrder(): void {
		$mapper = new SortableTestChildMapper($this->createStub(IDBConnection::class));

		// So kaeme es aus zwei Bloecken zurueck: je Block geordnet, zusammen nicht.
		$blockwise = [
			$this->step(id: 1, ticketId: 1, position: 65536),
			$this->step(id: 2, ticketId: 3, position: 65536),
			$this->step(id: 3, ticketId: 2, position: 65536),
			$this->step(id: 4, ticketId: 4, position: 65536),
		];

		$this->assertSame(
			[1, 2, 3, 4],
			array_map(
				static fn (Step $s): int => $s->getTicketId(),
				$mapper->sortForTest($blockwise),
			),
		);
	}

	/**
	 * `sortProperty()` nennt eine **Eigenschaft**, keine Spalte.
	 *
	 * Ein `created_at` statt `createdAt` faellt sonst erst zur Laufzeit auf,
	 * beim Sortieren einer nicht leeren Liste — also genau dann, wenn Daten da
	 * sind und nicht im Test.
	 *
	 * @param class-string<TicketChildMapper> $mapperClass
	 * @param class-string $entityClass
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('childMappers')]
	public function testSortPropertyNamesAnExistingEntityProperty(string $mapperClass, string $entityClass): void {
		$mapper = new $mapperClass($this->createStub(IDBConnection::class));

		$method = new ReflectionMethod($mapper, 'sortProperty');
		$property = $method->invoke($mapper);

		$this->assertIsString($property);
		$this->assertTrue(
			property_exists($entityClass, $property),
			$mapperClass . '::sortProperty() nennt "' . $property . '" — das ist keine Eigenschaft von ' . $entityClass . '.',
		);
		$this->assertArrayHasKey(
			$property,
			(new $entityClass())->getFieldTypes(),
			$mapperClass . '::sortProperty() nennt eine Eigenschaft ohne Typzuordnung.',
		);
	}

	/**
	 * @return iterable<string, array{class-string, class-string}>
	 */
	public static function childMappers(): iterable {
		yield 'Kommentare' => [CommentMapper::class, Comment::class];
		yield 'Arbeitsschritte' => [StepMapper::class, Step::class];
		yield 'Anhaenge' => [AttachmentMapper::class, Attachment::class];
		yield 'Mitarbeitende' => [TicketUserMapper::class, TicketUser::class];
	}

	private function step(int $id, int $ticketId, int $position): Step {
		$step = new Step();
		$step->setId($id);
		$step->setTicketId($ticketId);
		$step->setPosition($position);

		return $step;
	}
}

/**
 * Ein Kind-Mapper zum Anfassen: Er macht die geschuetzte Sortierung
 * aufrufbar, ohne dass die Basisklasse sie oeffentlich machen muesste.
 *
 * @template-extends TicketChildMapper<Step>
 */
final class SortableTestChildMapper extends TicketChildMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_steps', Step::class);
	}

	#[\Override]
	protected function sortProperty(): string {
		return 'position';
	}

	/**
	 * @param Step[] $entities
	 * @return Step[]
	 */
	public function sortForTest(array $entities): array {
		return $this->sortEntities($entities);
	}
}
