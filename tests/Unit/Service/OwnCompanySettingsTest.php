<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\OwnCompanySettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class OwnCompanySettingsTest extends TestCase {

	public function testNameIsTrimmedAndStored(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueString')->with('projektwerk', 'own_company', 'cpcMomentum GmbH');

		$this->assertSame('cpcMomentum GmbH', (new OwnCompanySettings($config))->set('  cpcMomentum GmbH '));
	}

	public function testEmptyNameRemovesTheSetting(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('deleteKey')->with('projektwerk', 'own_company');
		$config->expects($this->never())->method('setValueString');

		$this->assertNull((new OwnCompanySettings($config))->set('   '));
	}

	public function testTooLongNameIsRefused(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');

		$this->expectException(\InvalidArgumentException::class);
		(new OwnCompanySettings($config))->set(str_repeat('ä', 256));
	}

	public function testUnsetOrBlankReadsAsNull(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnOnConsecutiveCalls('', '  ', ' cpc ');
		$settings = new OwnCompanySettings($config);

		$this->assertNull($settings->get());
		$this->assertNull($settings->get());
		$this->assertSame('cpc', $settings->get());
	}
}
