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
}
