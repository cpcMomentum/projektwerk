<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Mcp;

use OCA\Projektwerk\Mcp\SchemaValidator;
use OCA\Projektwerk\Mcp\ToolResult;
use OCA\Projektwerk\Mcp\Tools\Cursor;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase {

	private const SCHEMA = [
		'type' => 'object',
		'properties' => [
			'board_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'x'],
			'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5, 'description' => 'x'],
			'visibility' => ['type' => 'string', 'enum' => ['public', 'internal'], 'description' => 'x'],
			'flag' => ['type' => 'boolean', 'description' => 'x'],
		],
		'required' => ['board_id'],
		'additionalProperties' => false,
	];

	public function testValidArgumentsPass(): void {
		$this->assertSame([], (new SchemaValidator())->validate(self::SCHEMA, ['board_id' => 3, 'title' => 'äöü', 'visibility' => 'public', 'flag' => false]));
	}

	public function testEachViolationIsNamed(): void {
		$errors = (new SchemaValidator())->validate(self::SCHEMA, [
			'title' => 'zu lang hier',
			'visibility' => 'secret',
			'flag' => 'true',
			'extra' => 1,
		]);

		$this->assertSame([
			'arguments.board_id is required.',
			'arguments.title must be at most 5 characters long.',
			'arguments.visibility must be one of: public, internal.',
			'arguments.flag must be of type boolean.',
			'arguments.extra is not a known argument.',
		], $errors);
	}

	public function testIntegersAreNotNumericStringsOrFloats(): void {
		$validator = new SchemaValidator();

		$this->assertNotSame([], $validator->validate(self::SCHEMA, ['board_id' => '3']));
		$this->assertNotSame([], $validator->validate(self::SCHEMA, ['board_id' => 3.0]));
		$this->assertNotSame([], $validator->validate(self::SCHEMA, ['board_id' => 0]));
	}

	public function testAListIsNotAnObject(): void {
		$this->assertSame(['arguments must be of type object.'], (new SchemaValidator())->validate(self::SCHEMA, [1, 2]));
	}

	public function testUnsupportedKeywordIsAProgrammingError(): void {
		$this->expectException(\LogicException::class);

		(new SchemaValidator())->validate(['type' => 'object', 'oneOf' => []], []);
	}

	public function testCursorRoundTripsAndRejectsForgeries(): void {
		$this->assertSame(0, Cursor::decode(null));
		$this->assertSame(40, Cursor::decode(Cursor::encode(40)));
		$this->assertNull(Cursor::decode('abc'));
		$this->assertNull(Cursor::decode(base64_encode('o:-1')));
	}

	public function testRowsThatFitStaysUnderTheCap(): void {
		$rows = array_fill(0, 200, ['text' => str_repeat('x', 1000)]);

		$fit = ToolResult::rowsThatFit($rows);

		$this->assertGreaterThan(0, $fit);
		$this->assertLessThan(200, $fit);
		$result = ToolResult::ok(['rows' => array_slice($rows, 0, $fit)]);
		$this->assertLessThan(ToolResult::MAX_BYTES, strlen((string)json_encode($result->toArray())));
	}
}
