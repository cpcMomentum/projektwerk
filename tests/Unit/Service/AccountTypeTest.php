<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\AccountType;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Gast-Erkennung über das User-Backend (#280).
 *
 * Der Kern: Ein Gast trägt das Backend „Guests" (Guests-App), ein vollwertiges
 * Konto „Database"/„LDAP"/… Die Klasse liest das allein über Core-`IUserManager`
 * — kein `IGroupManager`, keine Guests-App-Abhängigkeit.
 */
class AccountTypeTest extends TestCase {

	private function withBackend(string $backend): IUserManager {
		$user = $this->createStub(IUser::class);
		$user->method('getBackendClassName')->willReturn($backend);

		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn($user);

		return $users;
	}

	public function testAGuestBackendIsAGuest(): void {
		$type = new AccountType($this->withBackend('Guests'));

		$this->assertTrue($type->isGuest('pw-carla'));
	}

	public function testAFullAccountIsNotAGuest(): void {
		foreach (['Database', 'LDAP', 'SAML'] as $backend) {
			$type = new AccountType($this->withBackend($backend));

			$this->assertFalse($type->isGuest('pw-anna'), "Backend $backend darf nicht als Gast gelten");
		}
	}

	/**
	 * Ohne Sitzung ist niemand Gast — der Aufrufer fällt ohnehin am eigenen
	 * Null-Check durch.
	 */
	public function testNullIsNotAGuest(): void {
		$users = $this->createStub(IUserManager::class);
		$type = new AccountType($users);

		$this->assertFalse($type->isGuest(null));
	}

	/**
	 * Ein unbekanntes Konto (der Manager liefert `null`) ist kein Gast — die
	 * Anlage-Sperre greift also nur bei einem **positiv erkannten** Gast
	 * (fail-closed am Aufrufer, der bei einem Gast sperrt).
	 */
	public function testAnUnknownUserIsNotAGuest(): void {
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn(null);
		$type = new AccountType($users);

		$this->assertFalse($type->isGuest('weg'));
	}
}
