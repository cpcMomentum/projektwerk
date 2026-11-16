<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\License;

use OCA\Projektwerk\License\InstanceId;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstanceIdTest extends TestCase {

	/** @return array<string, array{string, string}> */
	public static function contractVectors(): array {
		return [
			'ocx7k2m9qz4t' => ['ocx7k2m9qz4t', '8cb3c100-9e4c-5375-9e63-1f4d122ea315'],
			'oc1a2b3c4d5e' => ['oc1a2b3c4d5e', '62536685-aff2-57b3-b406-0cdac03ffb2b'],
		];
	}

	#[DataProvider('contractVectors')]
	public function testDerivesTheUuidFromTheContract(string $instanceId, string $uuid): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('instanceid')->willReturn($instanceId);

		$this->assertSame($uuid, (new InstanceId($config))->uuid());
	}

	public function testTheInstanceIdIsNotTrimmed(): void {
		$this->assertNotSame(InstanceId::derive('ocx7k2m9qz4t'), InstanceId::derive(' ocx7k2m9qz4t'));
	}
}
