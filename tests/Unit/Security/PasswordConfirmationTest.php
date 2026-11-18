<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Security;

use OCA\Projektwerk\Controller\ReplyMailboxController;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Wege, die mit dem gespeicherten Postfach-Passwort arbeiten, verlangen die Bestätigung des eigenen Passworts.
 */
class PasswordConfirmationTest extends TestCase {

	/** @return iterable<string, array{0: class-string, 1: string}> */
	public static function routes(): iterable {
		yield 'reply_mailbox#save' => [ReplyMailboxController::class, 'save'];
		yield 'reply_mailbox#test' => [ReplyMailboxController::class, 'test'];
	}

	/** @param class-string $class */
	#[DataProvider('routes')]
	public function testTheRouteAsksForPasswordConfirmation(string $class, string $method): void {
		$this->assertCount(1, (new \ReflectionMethod($class, $method))->getAttributes(PasswordConfirmationRequired::class), $class . '::' . $method);
	}
}
