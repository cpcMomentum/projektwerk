<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Security;

use OCA\Projektwerk\Controller\CommentController;
use OCA\Projektwerk\Controller\StepController;
use OCA\Projektwerk\Controller\TicketController;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WriteRateLimitTest extends TestCase {

	/**
	 * @return iterable<string, array{0: class-string, 1: string}>
	 */
	public static function writeRoutes(): iterable {
		yield 'ticket#create' => [TicketController::class, 'create'];
		yield 'ticket#update' => [TicketController::class, 'update'];
		yield 'step#create' => [StepController::class, 'create'];
		yield 'step#update' => [StepController::class, 'update'];
		yield 'comment#create' => [CommentController::class, 'create'];
	}

	/** @param class-string $class */
	#[DataProvider('writeRoutes')]
	public function testEveryWriteRouteThatCanTriggerMailIsRateLimited(string $class, string $method): void {
		$attributes = (new \ReflectionMethod($class, $method))->getAttributes(UserRateLimit::class);

		$this->assertCount(1, $attributes, $class . '::' . $method);
	}
}
