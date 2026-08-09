<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Db;

use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\Comment;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketUser;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * Haelt Entities und Migration aneinander.
 *
 * Ein Entity, dem eine Spalte fehlt, ist kein lauter Fehler: Der Wert wird
 * beim Lesen still verworfen und beim Schreiben nie gesetzt. Auf einer Spalte
 * wie `visibility` oder `creator_role` waere das kein Anzeigefehler, sondern
 * ein Leck — deshalb wird der Abgleich hier mechanisch gemacht und nicht
 * gelesen.
 *
 * Der Test parst die Migration, statt ein Schema zu erwarten. Damit prueft er
 * gegen das, was tatsaechlich ausgeliefert wird, und braucht keine Datenbank.
 */
class EntitySchemaTest extends TestCase {

	private const MIGRATION = __DIR__ . '/../../../lib/Migration/Version000001Date20260808000000.php';

	/**
	 * Die Zuordnung ist die Registrierung: Eine Tabelle ohne Eintrag laesst
	 * {@see testEveryTableHasAnEntity()} fallen.
	 *
	 * @return array<string, class-string<Entity>>
	 */
	private static function entitiesByTable(): array {
		return [
			'pwerk_boards' => Board::class,
			'pwerk_members' => Member::class,
			'pwerk_columns' => Column::class,
			'pwerk_tickets' => Ticket::class,
			'pwerk_ticket_users' => TicketUser::class,
			'pwerk_steps' => Step::class,
			'pwerk_comments' => Comment::class,
			'pwerk_attachments' => Attachment::class,
			'pwerk_notify_prefs' => NotifyPref::class,
			'pwerk_mail_outbox' => MailOutbox::class,
		];
	}

	/**
	 * Typfamilien: Das Entity darf `bigint` als `integer` fuehren (tut es
	 * ueberall), aber niemals eine Zahl als Text oder umgekehrt.
	 */
	private const FAMILIES = [
		Types::BIGINT => 'int',
		Types::INTEGER => 'int',
		Types::SMALLINT => 'int',
		Types::STRING => 'text',
		Types::TEXT => 'text',
		Types::DATE => 'time',
		Types::DATETIME => 'time',
	];

	public function testEveryTableHasAnEntity(): void {
		$tables = array_keys($this->parseMigration());

		$this->assertSame(
			[],
			array_diff($tables, array_keys(self::entitiesByTable())),
			'Migration legt eine Tabelle an, zu der kein Entity registriert ist.',
		);
		$this->assertCount(10, $tables, 'Migration 1 legt zehn Tabellen an.');
	}

	public function testEntityFieldsMatchMigrationColumns(): void {
		$schema = $this->parseMigration();

		foreach (self::entitiesByTable() as $table => $class) {
			$entity = new $class();
			$this->assertInstanceOf(Entity::class, $entity);

			$expected = array_keys($schema[$table]);
			sort($expected);

			$actual = ['id'];
			foreach (array_keys($entity->getFieldTypes()) as $property) {
				if ($property === 'id') {
					continue;
				}
				$actual[] = $entity->propertyToColumn($property);
			}
			sort($actual);

			$this->assertSame(
				$expected,
				$actual,
				$class . ' deckt die Spalten von ' . $table . ' nicht genau ab. '
					. 'Eine fehlende Spalte wird still verworfen — auf visibility oder '
					. 'creator_role waere das ein Leck, kein Anzeigefehler.',
			);
		}
	}

	/**
	 * Jedes registrierte Feld hat auch eine deklarierte Eigenschaft.
	 *
	 * `getFieldTypes()` wird von `addType()` gefuellt, nicht von den
	 * Eigenschaften — ein `addType()` ohne passende Eigenschaft kommt an
	 * {@see testEntityFieldsMatchMigrationColumns()} vorbei. Nextclouds `Entity`
	 * legt dann beim Setzen eine **dynamische** Eigenschaft an: In PHP 8.2
	 * eine Verwarnung, ab PHP 9 ein Fehler. Der Test faengt das heute, wo es
	 * eine Zeile kostet.
	 *
	 * Gefunden bei einer Gegenprobe zu `last_editor_user_id`: Die Eigenschaft
	 * zu entfernen und `addType()` stehen zu lassen liess die Suite gruen.
	 */
	public function testEveryRegisteredFieldHasADeclaredProperty(): void {
		$missing = [];

		foreach (self::entitiesByTable() as $table => $class) {
			$entity = new $class();
			$reflection = new \ReflectionClass($class);

			foreach (array_keys($entity->getFieldTypes()) as $property) {
				if (!$reflection->hasProperty($property)) {
					$missing[] = $class . '::$' . $property;
				}
			}
		}

		$this->assertSame($missing, [], implode("\n", [
			'Registriertes Feld ohne deklarierte Eigenschaft:',
			'  ' . implode(', ', $missing),
			'',
			'Entity legt dann eine dynamische Eigenschaft an — Verwarnung in PHP 8.2,',
			'Fehler ab PHP 9.',
		]));
	}

	public function testEntityTypesMatchColumnTypes(): void {
		$schema = $this->parseMigration();

		$mismatches = [];
		foreach (self::entitiesByTable() as $table => $class) {
			$entity = new $class();
			foreach ($entity->getFieldTypes() as $property => $type) {
				if ($property === 'id') {
					continue;
				}
				$column = $entity->propertyToColumn($property);
				$columnType = $schema[$table][$column] ?? null;
				if ($columnType === null) {
					// Von testEntityFieldsMatchMigrationColumns bereits gemeldet.
					continue;
				}
				$entityFamily = self::FAMILIES[$type] ?? $type;
				$columnFamily = self::FAMILIES[$columnType] ?? $columnType;
				if ($entityFamily !== $columnFamily) {
					$mismatches[] = sprintf(
						'%s::$%s ist %s, Spalte %s.%s ist %s',
						$class, $property, $type, $table, $column, $columnType,
					);
				}
			}
		}

		$this->assertSame([], $mismatches, implode("\n", $mismatches));
	}

	/**
	 * Hausregel aus CLAUDE.md, hier als Invariante: `Types::BOOLEAN` mit
	 * `notnull` erzeugt Schema-Fehler, und `PARAM_BOOL` schreibt auf PostgreSQL
	 * `'f'` statt `0`. Beides faellt erst auf einer fremden Installation auf.
	 */
	public function testNoEntityUsesBooleanType(): void {
		$offenders = [];
		foreach (self::entitiesByTable() as $class) {
			$entity = new $class();
			foreach ($entity->getFieldTypes() as $property => $type) {
				if ($type === Types::BOOLEAN) {
					$offenders[] = $class . '::$' . $property;
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Boolesche Felder sind SMALLINT 0/1: ' . implode(', ', $offenders),
		);
	}

	/**
	 * Alle Benutzerkennungen sind `varchar(64)` — Gast-UIDs sind Hashes mit
	 * exakt 64 Zeichen (S1, 07.08.2026) und wuerden bei 32 still abgeschnitten.
	 * „Still" ist der Punkt: Der Zugriff schluege danach fehl, ohne dass
	 * irgendwo ein Fehler stuende.
	 */
	public function testUserIdColumnsAreLongEnoughForGuestHashes(): void {
		$source = (string)file_get_contents(self::MIGRATION);

		$tooShort = [];
		preg_match_all(
			"/addColumn\('([a-z_]*(?:user_id|_uid|uploaded_by|added_by))', Types::STRING, \[([^\]]*)\]/",
			$source,
			$matches,
			PREG_SET_ORDER,
		);
		$this->assertNotEmpty($matches, 'Keine Benutzerkennungs-Spalte gefunden — Muster veraltet?');

		foreach ($matches as $match) {
			if (!str_contains($match[2], "'length' => 64")) {
				$tooShort[] = $match[1];
			}
		}

		$this->assertSame([], $tooShort, 'Zu kurz fuer Gast-UIDs: ' . implode(', ', $tooShort));
	}

	/**
	 * @return array<string, array<string, string>> Tabelle => Spalte => Typ
	 */
	private function parseMigration(): array {
		$source = (string)file_get_contents(self::MIGRATION);

		$schema = [];
		$table = null;
		foreach (explode("\n", $source) as $line) {
			if (preg_match("/createTable\('([a-z_]+)'\)/", $line, $m) === 1) {
				$table = $m[1];
				$schema[$table] = [];
				continue;
			}
			if ($table !== null && preg_match("/addColumn\('([a-z_]+)', Types::([A-Z_]+)/", $line, $m) === 1) {
				$schema[$table][$m[1]] = constant(Types::class . '::' . $m[2]);
			}
		}

		return $schema;
	}
}
