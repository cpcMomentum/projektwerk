<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\License;

use OCA\Projektwerk\Controller\LicenseController;
use OCA\Projektwerk\License\WerkPlusApiException;
use OCA\Projektwerk\License\LicenseService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LicenseControllerTrialTest extends TestCase {

	private LicenseService&MockObject $entitlement;

	protected function setUp(): void {
		parent::setUp();
		$this->entitlement = $this->createMock(LicenseService::class);
		$this->entitlement->method('getStatus')->willReturn(['keySet' => true, 'lastError' => null]);
	}

	private function controller(): LicenseController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => $text);
		return new LicenseController($this->createMock(IRequest::class), $this->entitlement, $l10n);
	}

	public function testReturnsTheKeyOnceTogetherWithTheStatus(): void {
		$this->entitlement->expects($this->once())->method('startTrial')->with()->willReturn('wp_NEU');
		$response = $this->controller()->trial();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('wp_NEU', $response->getData()['key']);
		$this->assertTrue($response->getData()['status']['keySet']);
	}

	public function testNoMethodIsOpenToNonAdmins(): void {
		foreach ((new \ReflectionClass(LicenseController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$this->assertSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class), $method->getName());
		}
	}

	public function testANewerSharedStoreBlocksRemovalWithAMessageInsteadOfAServerError(): void {
		$this->entitlement->method('removeKey')->willThrowException(new \InvalidArgumentException('storeOutdated'));
		$response = $this->controller()->destroy();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Bitte aktualisieren Sie ProjektWerk', $response->getData()['error']);
	}

	public function testStoredKeyBlocksTheTrial(): void {
		$this->entitlement->method('startTrial')->willThrowException(new \InvalidArgumentException('keySet'));
		$response = $this->controller()->trial();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('bereits ein WerkPlus-Schlüssel', $response->getData()['error']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function trialErrors(): array {
		return [
			'schon genutzt' => ['TRIAL_ALREADY_USED', 'bereits ein Testzeitraum genutzt'],
			'abgeschaltet' => ['TRIAL_UNAVAILABLE', 'werkwolke.de/testen'],
			'gebremst' => ['RATE_LIMITED', 'später erneut'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('trialErrors')]
	public function testTrialErrorsKeepTheirCodeAndGetTheirOwnText(string $code, string $text): void {
		$this->entitlement->method('startTrial')->willThrowException(new WerkPlusApiException($code, 'Servertext'));
		$response = $this->controller()->trial();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame($code, $response->getData()['code']);
		$this->assertStringContainsString($text, $response->getData()['error']);
	}
}
