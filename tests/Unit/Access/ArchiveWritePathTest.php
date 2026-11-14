<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Access;

use OCA\Projektwerk\Middleware\ArchiveMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Welche Schreibwege mit `{boardId}` im Archiv offen bleiben, ist eine bewusste Liste.
 */
class ArchiveWritePathTest extends TestCase {

	private const ROUTES_FILE = __DIR__ . '/../../../appinfo/routes.php';

	private const OPEN_IN_ARCHIVE = [
		'board#setPin',
		'ticket#read',
		'settings#archiveBoard',
		'settings#archiveProject',
		'settings#deleteBoard',
		'settings#removeMember',
		'notifyPref#update',
	];

	public function testOnlyTheListedWritePathsStayOpenInTheArchive(): void {
		$open = [];
		foreach ($this->boardWriteRoutes() as $name) {
			[$controller, $method] = explode('#', $name);
			$class = 'OCA\\Projektwerk\\Controller\\' . ucfirst($controller) . 'Controller';
			$this->assertTrue(method_exists($class, $method), $name . ' zeigt auf keine Methode.');
			if (ArchiveMiddleware::allowedInArchive($class, $method)) {
				$open[] = $name;
			}
		}
		sort($open);
		$expected = self::OPEN_IN_ARCHIVE;
		sort($expected);

		$this->assertSame($expected, $open, 'Ein Schreibweg im Archiv ist eine Produktentscheidung: Liste hier und #[AllowedInArchive] gemeinsam ändern.');
	}

	public function testThereAreWritePathsToGuard(): void {
		$this->assertGreaterThan(20, count($this->boardWriteRoutes()));
	}

	/** @return list<string> */
	private function boardWriteRoutes(): array {
		$routes = require self::ROUTES_FILE;
		$names = [];
		foreach ($routes['routes'] as $route) {
			if (in_array($route['verb'], ['GET', 'HEAD'], true) || !self::takesBoardId($route)) {
				continue;
			}
			$names[] = $route['name'];
		}

		return array_values(array_unique($names));
	}

	/** Auch eine `boardId` im Body zählt, die Sperre liest sie über getParam(). */
	private static function takesBoardId(array $route): bool {
		if (str_contains($route['url'], '{boardId}')) {
			return true;
		}
		[$controller, $method] = explode('#', $route['name']);
		$class = 'OCA\\Projektwerk\\Controller\\' . ucfirst($controller) . 'Controller';
		if (!method_exists($class, $method)) {
			return false;
		}
		foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
			if ($parameter->getName() === 'boardId') {
				return true;
			}
		}

		return false;
	}
}
