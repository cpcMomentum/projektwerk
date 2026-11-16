<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\License;

use OCA\Projektwerk\License\SharedStore;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class SharedStoreTest extends TestCase {

	public function testAValueOfAnotherTypeFromAnotherWerkAppDoesNotBreakTheRead(): void {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new AppConfigTypeConflictException('typ'));
		$store = new SharedStore($appConfig);

		$this->assertSame('', $store->token());
		$this->assertFalse($store->isOutdated());
	}

	/**
	 * @return array<string, array{\Closure(SharedStore): void}>
	 */
	public static function tokenWrites(): array {
		return [
			'neuer Schlüssel' => [fn (SharedStore $store) => $store->setKey('wp_KEY', 'jwt')],
			'erneuertes Token' => [fn (SharedStore $store) => $store->setToken('jwt')],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('tokenWrites')]
	public function testTheTokenIsStoredAsSensitiveBecauseOtherServicesAcceptItAsProof(\Closure $write): void {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('1');
		$written = [];
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$written): bool {
				$written[$key] = ['sensitive' => $sensitive, 'lazy' => $lazy];
				return true;
			},
		);

		$write(new SharedStore($appConfig));

		$this->assertSame(['sensitive' => true, 'lazy' => false], $written['token']);
		$this->assertFalse($written['store_version']['lazy']);
	}
}
