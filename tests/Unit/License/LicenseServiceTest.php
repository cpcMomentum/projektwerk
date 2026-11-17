<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\License;

use OCA\Projektwerk\License\InstanceId;
use OCA\Projektwerk\License\SharedStore;
use OCA\Projektwerk\License\WerkPlusApiException;
use OCA\Projektwerk\License\LicenseService;
use OCA\Projektwerk\License\WerkPlusClient;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LicenseServiceTest extends TestCase {
	use EntitlementTokenFactory;
	use TraceStrings;

	private const KEY = 'wp_B7K2M9QX4TN6RJ8VC3HD5PKW2FSY7GZA3EFA69B6';

	/** @var array<string, string> Eigene App-Konfiguration */
	private array $config = [];
	/** @var array<string, string> Gemeinsame Ablage `werkwolke` */
	private array $shared = [];
	/** @var array<string, bool> */
	private array $sensitive = [];
	/** @var array<string, bool> */
	private array $enabledApps = [];
	private int $now = self::NOW;
	/** @var (\Closure(): void)|null Simuliert eine andere Werk-App, die zwischendurch schreibt. */
	private ?\Closure $otherApp = null;
	private WerkPlusClient&MockObject $client;
	private ?LoggerInterface $logger = null;

	protected function setUp(): void {
		parent::setUp();
		$this->config = [];
		$this->shared = [];
		$this->sensitive = [];
		$this->enabledApps = ['projektwerk' => true, 'rechnungswerk' => true];
		$this->now = self::NOW;
		$this->otherApp = null;
		$this->client = $this->createMock(WerkPlusClient::class);
		$this->logger = null;
	}

	/** @return array<string, string> */
	private function &bucket(string $app): array {
		if ($app === SharedStore::APP) {
			return $this->shared;
		}
		$this->assertSame('projektwerk', $app);
		return $this->config;
	}

	private function service(): LicenseService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->bucket($app)[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$bucket = &$this->bucket($app);
				$bucket[$key] = $value;
				$this->sensitive[$app . '/' . $key] = $sensitive;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			$bucket = &$this->bucket($app);
			unset($bucket[$key]);
		});
		$appConfig->method('clearCache')->willReturnCallback(function (): void {
			if ($this->otherApp !== null) {
				($this->otherApp)();
			}
		});
		$appConfig->expects($this->never())->method('deleteApp');
		$appConfig->expects($this->never())->method('getValueInt');

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(fn (string $app) => $this->enabledApps[$app] ?? false);
		$appManager->method('getAppVersion')->willReturnCallback(fn (string $app) => $app === 'projektwerk' ? '0.7.0' : '1.2.3');
		$instanceId = $this->createStub(InstanceId::class);
		$instanceId->method('uuid')->willReturn(self::UUID);

		return new LicenseService(
			$appConfig,
			new SharedStore($appConfig),
			$instanceId,
			$time,
			$this->client,
			$this->verifier(),
			$appManager,
			$this->logger ?? $this->createStub(LoggerInterface::class),
		);
	}

	private function storeToken(array $overrides = []): void {
		$this->shared['store_version'] = '1';
		$this->shared['key'] = self::KEY;
		$this->shared['token'] = $this->token($this->claims($overrides));
	}

	/**
	 * @return array{token: string, expiresAt: string, graceUntil: string}
	 */
	private function response(array $overrides = []): array {
		return ['token' => $this->token($this->claims($overrides)), 'expiresAt' => 'x', 'graceUntil' => 'y'];
	}

	public function testWithoutTokenEverythingIsFree(): void {
		$this->client->expects($this->never())->method('validate');
		$service = $this->service();

		$this->assertSame(LicenseService::STATE_NONE, $service->state());
		$this->assertSame('free', $service->tier());
		$this->assertFalse($service->hasFeature('pw_projects'));
	}

	public function testValidTokenUnlocksFeaturesWithoutAnyNetworkCall(): void {
		$this->storeToken();
		$this->client->expects($this->never())->method('validate');
		$service = $this->service();

		$this->assertSame('werkplus', $service->tier());
		$this->assertTrue($service->hasFeature('pw_projects'));
		$this->assertTrue($service->hasFeature('pw_mcp'));
		$this->assertFalse($service->hasFeature('pw_mail_reply'));
		$service->getStatus();
	}

	public function testGraceKeepsFeaturesAndAfterGraceFallsBackToFree(): void {
		$this->storeToken();
		$this->now = self::NOW + 31 * 86400;
		$service = $this->service();
		$this->assertSame('grace', $service->state());
		$this->assertTrue($service->hasFeature('pw_projects'));

		$this->now = self::NOW + 45 * 86400;
		$service = $this->service();
		$this->assertSame('expired', $service->state());
		$this->assertSame('free', $service->tier());
		$this->assertFalse($service->hasFeature('pw_projects'));
	}

	public function testTokenOfAnotherInstallationGrantsNothing(): void {
		$this->storeToken(['sub' => '00000000-0000-4000-8000-000000000000']);
		$service = $this->service();

		$this->assertSame(LicenseService::STATE_INVALID, $service->state());
		$this->assertFalse($service->hasFeature('pw_projects'));
	}

	public function testUnknownFlagIsAProgrammingError(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->hasFeature('api_keys');
	}

	public function testActivateStoresKeyAndTokenSensitively(): void {
		$this->client->expects($this->once())->method('validate')
			->with(self::KEY, self::UUID, ['rechnungswerk' => '1.2.3', 'projektwerk' => '0.7.0'])
			->willReturn($this->response());
		$service = $this->service();
		$service->activate('  ' . self::KEY . ' ');

		$this->assertSame(self::KEY, $this->shared['key']);
		$this->assertTrue($this->sensitive['werkwolke/key']);
		$this->assertSame('1', $this->shared['store_version']);
		$this->assertSame([], $this->config);
		$this->assertSame('2026-10-18T14:12:03Z', $service->getStatus()['lastCheckAt']);
		$this->assertTrue($service->hasFeature('pw_projects'));
		$this->assertNull($service->lastError());
	}

	public function testOnlyEnabledWerkAppsAreReported(): void {
		$this->enabledApps = ['projektwerk' => true, 'worktime' => true, 'contractmanager' => true, 'deck' => true];
		$this->client->expects($this->once())->method('validate')
			->with(self::KEY, self::UUID, ['projektwerk' => '0.7.0', 'worktime' => '1.2.3', 'contractmanager' => '1.2.3'])
			->willReturn($this->response());
		$this->service()->activate(self::KEY);
	}

	public function testFailedActivationStoresNoKey(): void {
		$this->client->method('validate')->willThrowException(new WerkPlusApiException('KEY_UNKNOWN', 'Schlüssel unbekannt'));
		$service = $this->service();
		try {
			$service->activate(self::KEY);
			$this->fail('Ausnahme erwartet');
		} catch (WerkPlusApiException $e) {
			$this->assertSame('KEY_UNKNOWN', $e->getErrorCode());
		}

		$this->assertArrayNotHasKey('key', $this->shared);
		$this->assertNull($service->lastError());
	}

	public function testKeyIsNormalizedLikeTheServerDoes(): void {
		$this->client->expects($this->once())->method('validate')
			->with(self::KEY)
			->willReturn($this->response());
		$this->service()->activate(' WP_b7k2-m9qx-4tn6 rj8v-c3hd-5pkw-2fsy-7gza 3efa69b6 ');

		$this->assertSame(self::KEY, $this->shared['key']);
	}

	public function testRevokedStoredKeyInDisplayFormStillFallsBackToFree(): void {
		$this->storeToken();
		$this->client->method('validate')->willThrowException(new WerkPlusApiException('KEY_REVOKED'));
		$service = $this->service();
		try {
			$service->activate('wp_b7k2-m9qx-4tn6-rj8v-c3hd-5pkw-2fsy-7gza-3efa-69b6');
		} catch (WerkPlusApiException) {
		}

		$this->assertSame('free', $service->tier());
	}

	public function testFailedNewKeyDoesNotBlockRenewalOfTheStoredOne(): void {
		$this->storeToken();
		$this->client->method('validate')->willThrowException(new WerkPlusApiException('KEY_UNKNOWN'));
		try {
			$this->service()->activate('wp_VERTIPPT');
		} catch (WerkPlusApiException) {
		}

		$this->assertArrayNotHasKey('werkplus_last_error', $this->config);
		$this->now = self::NOW + 8 * 86400;
		$this->assertTrue($this->service()->isRefreshDue());
	}

	public function testAFailedNewKeyRightNowStillLeavesTheStoredOneDueForRenewal(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$this->client->method('validate')->willThrowException(new WerkPlusApiException('KEY_UNKNOWN'));
		try {
			$this->service()->activate('wp_VERTIPPT');
		} catch (WerkPlusApiException) {
		}

		$this->assertArrayNotHasKey('werkplus_last_attempt_at', $this->config);
		$this->assertTrue($this->service()->isRefreshDue());
	}

	public function testTheNextJobRunSixHoursLaterIsNotSkipped(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$this->config['werkplus_last_attempt_at'] = (string)($this->now - 6 * 3600 + 30);

		$this->assertTrue($this->service()->isRefreshDue());
	}

	public function testTheSameServiceSeesTheGracePeriodEnd(): void {
		$this->storeToken();
		$service = $this->service();
		$this->assertTrue($service->hasFeature('pw_projects'));

		$this->now = self::NOW + 45 * 86400;
		$this->assertFalse($service->hasFeature('pw_projects'), 'Ein langer Prozess darf den alten Zustand nicht behalten.');
	}

	public function testNeitherKeyNorTokenAppearInATraceOrTheLog(): void {
		$jwt = $this->token($this->claims(), [], self::foreignKey()['private']);
		$this->client->method('validate')->willReturn(['token' => $jwt, 'expiresAt' => 'x', 'graceUntil' => 'y']);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $msg, array $context = []) use (&$logged): void {
			$logged[] = $context;
		});
		$this->logger = $logger;
		try {
			$this->service()->activate(self::KEY);
			$this->fail('Ausnahme erwartet');
		} catch (WerkPlusApiException $e) {
			$this->assertNull($e->getPrevious());
			self::assertNotInTrace(self::KEY, $e);
			self::assertNotInTrace(explode('.', $jwt)[2], $e);
		}
		$this->assertSame([['reason' => 'Signatur ungueltig']], $logged);
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function storeTargets(): array {
		return ['gespeicherter Schlüssel' => [true], 'neuer Schlüssel' => [false]];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('storeTargets')]
	public function testATokenThatCanNoLongerBeStoredStaysOutOfTheTrace(bool $storedKey): void {
		if ($storedKey) {
			$this->storeToken(['iat' => self::NOW - 3600]);
		}
		$jwt = $this->token($this->claims());
		$this->client->method('validate')->willReturnCallback(function () use ($jwt): array {
			// Eine neuere Werk-App schreibt die Ablage um, während der Abruf läuft.
			$this->shared['store_version'] = '2';
			return ['token' => $jwt, 'expiresAt' => 'x', 'graceUntil' => 'y'];
		});
		try {
			$this->service()->activate(self::KEY);
			$this->fail('Ausnahme erwartet');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('storeOutdated', $e->getMessage());
			self::assertNotInTrace(explode('.', $jwt)[2], $e);
			self::assertNotInTrace(self::KEY, $e);
		}
	}

	public function testARejectedTrialTokenIsLoggedOnlyWithItsReason(): void {
		$this->client->method('trial')->willReturn(['key' => self::KEY] + ['token' => $this->token($this->claims(), [], self::foreignKey()['private']), 'expiresAt' => 'x', 'graceUntil' => 'y']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->anything(), ['reason' => 'Signatur ungueltig']);
		$this->logger = $logger;

		$this->assertSame(self::KEY, $this->service()->startTrial());
	}

	public function testAnInvalidStoredTokenIsLoggedOnlyWithItsReason(): void {
		$this->storeToken();
		$this->shared['token'] = $this->token($this->claims(), [], self::foreignKey()['private']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('debug')->with($this->anything(), ['reason' => 'Signatur ungueltig']);
		$this->logger = $logger;

		$this->assertFalse($this->service()->hasFeature('pw_projects'));
	}

	public function testFailedRenewalIsLoggedWithoutTheException(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$this->client->method('validate')->willThrowException(new WerkPlusApiException(WerkPlusClient::UNREACHABLE));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with($this->anything(), ['reason' => WerkPlusApiException::class . ': WerkPlus: UNREACHABLE']);
		$this->logger = $logger;

		$this->service()->refreshIfDue();
	}

	public function testRevokedNewKeyDoesNotCostTheStoredToken(): void {
		$this->storeToken();
		$this->client->method('validate')->willThrowException(new WerkPlusApiException('KEY_REVOKED'));
		$service = $this->service();
		try {
			$service->activate('wp_ANDERERSCHLUESSEL');
		} catch (WerkPlusApiException) {
		}

		$this->assertSame(self::KEY, $this->shared['key']);
		$this->assertTrue($service->hasFeature('pw_projects'));
	}

	public function testRevokedStoredKeyFallsBackToFree(): void {
		$this->storeToken();
		$this->client->method('validate')->willThrowException(new WerkPlusApiException('KEY_REVOKED'));
		$service = $this->service();
		try {
			$service->refresh();
		} catch (WerkPlusApiException) {
		}

		$this->assertSame('free', $service->tier());
		$this->assertArrayHasKey('token', $this->shared, 'Die anderen Werk-Apps entscheiden selbst.');
		$this->assertSame(self::KEY, $this->shared['key']);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function transientErrors(): array {
		return [
			'RATE_LIMITED' => ['RATE_LIMITED'],
			'SERVER_ERROR' => ['SERVER_ERROR'],
			'UNREACHABLE' => [WerkPlusClient::UNREACHABLE],
			'INVALID_RESPONSE' => [WerkPlusClient::INVALID_RESPONSE],
			'INSTANCE_LIMIT_REACHED' => ['INSTANCE_LIMIT_REACHED'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('transientErrors')]
	public function testFailedRenewalKeepsTheOldToken(string $code): void {
		$this->storeToken();
		$before = $this->shared['token'];
		$this->client->method('validate')->willThrowException(new WerkPlusApiException($code));
		$service = $this->service();
		try {
			$service->refresh();
		} catch (WerkPlusApiException) {
		}

		$this->assertSame($before, $this->shared['token']);
		$this->assertTrue($service->hasFeature('pw_projects'));
	}

	public function testTokenThatFailsLocalVerificationIsNotStored(): void {
		$this->client->method('validate')->willReturn([
			'token' => $this->token($this->claims(), [], self::foreignKey()['private']),
			'expiresAt' => 'x',
			'graceUntil' => 'y',
		]);
		$service = $this->service();
		try {
			$service->activate(self::KEY);
			$this->fail('Ausnahme erwartet');
		} catch (WerkPlusApiException $e) {
			$this->assertSame(LicenseService::TOKEN_REJECTED, $e->getErrorCode());
		}

		$this->assertArrayNotHasKey('token', $this->shared);
		$this->assertArrayNotHasKey('key', $this->shared);
	}

	public function testInvalidInputIsRejectedBeforeAnyCall(): void {
		$this->client->expects($this->never())->method('validate');
		$service = $this->service();
		foreach (['', 'wp_<script>', 'wp_' . str_repeat('A', 130)] as $key) {
			try {
				$service->activate($key);
				$this->fail('Ausnahme erwartet fuer ' . $key);
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function testEmptyKeyReusesTheStoredOne(): void {
		$this->storeToken();
		$this->client->expects($this->once())->method('validate')
			->with(self::KEY, self::UUID, $this->anything())
			->willReturn($this->response());
		$this->service()->activate('');
	}

	public function testFreshTokenIsNotRenewed(): void {
		$this->storeToken();
		$this->now = self::NOW + 6 * 86400;
		$this->assertFalse($this->service()->isRefreshDue());
	}

	public function testTokenOlderThanSevenDaysIsRenewed(): void {
		$this->storeToken();
		$this->now = self::NOW + 7 * 86400;
		$this->assertTrue($this->service()->isRefreshDue());
	}

	public function testTokenCloseToExpiryIsRenewed(): void {
		$this->storeToken(['exp' => self::NOW + 10 * 86400, 'graceUntil' => self::NOW + 10 * 86400]);
		$this->now = self::NOW + 4 * 86400;
		$this->assertTrue($this->service()->isRefreshDue());
	}

	public function testNoRenewalWithoutKeyOrRightAfterAnAttempt(): void {
		$this->assertFalse($this->service()->isRefreshDue());

		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$this->config['werkplus_last_attempt_at'] = (string)($this->now - 3600);
		$this->assertFalse($this->service()->isRefreshDue());

		$this->config['werkplus_last_attempt_at'] = (string)($this->now - 7 * 3600);
		$this->assertTrue($this->service()->isRefreshDue());
	}

	public function testPermanentErrorsAreRetriedOnlyOnceADay(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$this->config['werkplus_last_error'] = json_encode(['code' => 'KEY_REVOKED', 'serverMessage' => '', 'at' => $this->now - 7 * 3600]);
		$this->assertFalse($this->service()->isRefreshDue());

		$this->config['werkplus_last_error'] = json_encode(['code' => 'KEY_REVOKED', 'serverMessage' => '', 'at' => $this->now - 25 * 3600]);
		$this->assertTrue($this->service()->isRefreshDue());

		$this->config['werkplus_last_error'] = json_encode(['code' => 'SERVER_ERROR', 'serverMessage' => '', 'at' => $this->now - 7 * 3600]);
		$this->assertTrue($this->service()->isRefreshDue());
	}

	public function testTrialStoresKeyAndTokenAndReturnsTheKey(): void {
		$this->client->expects($this->never())->method('validate');
		$this->client->expects($this->once())->method('trial')
			->with(self::UUID, ['rechnungswerk' => '1.2.3', 'projektwerk' => '0.7.0'])
			->willReturn(['key' => strtolower(self::KEY)] + $this->response());
		$service = $this->service();

		$this->assertSame(self::KEY, $service->startTrial());
		$this->assertSame(self::KEY, $this->shared['key']);
		$this->assertTrue($this->sensitive['werkwolke/key']);
		$this->assertSame('2026-10-18T14:12:03Z', $service->getStatus()['lastCheckAt']);
		$this->assertTrue($service->hasFeature('pw_projects'));
		$this->assertNull($service->lastError());
	}

	public function testNoTrialWhenAKeyIsStored(): void {
		$this->storeToken();
		$this->client->expects($this->never())->method('trial');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('keySet');
		$this->service()->startTrial();
	}

	public function testRefusedTrialStoresNothing(): void {
		$this->client->method('trial')->willThrowException(new WerkPlusApiException('TRIAL_ALREADY_USED', 'schon genutzt'));
		$service = $this->service();
		try {
			$service->startTrial();
			$this->fail('Ausnahme erwartet');
		} catch (WerkPlusApiException $e) {
			$this->assertSame('TRIAL_ALREADY_USED', $e->getErrorCode());
		}

		$this->assertArrayNotHasKey('key', $this->shared);
		$this->assertArrayNotHasKey('token', $this->shared);
		$this->assertNull($service->getStatus()['lastError']);
	}

	public function testTrialKeyIsKeptWhenItsTokenFailsVerification(): void {
		$this->client->method('trial')->willReturn([
			'key' => self::KEY,
			'token' => $this->token($this->claims(), [], self::foreignKey()['private']),
			'expiresAt' => 'x',
			'graceUntil' => 'y',
		]);
		$service = $this->service();

		$this->assertSame(self::KEY, $service->startTrial());
		$this->assertSame(self::KEY, $this->shared['key']);
		$this->assertArrayNotHasKey('token', $this->shared);
		$this->assertSame(LicenseService::TOKEN_REJECTED, $service->getStatus()['lastError']['code']);
		$this->assertSame('free', $service->tier());
		$this->assertTrue($service->isRefreshDue());
	}

	public function testTrialKeyIsKeptWhenTheTokenIsMissing(): void {
		$this->client->method('trial')->willReturn(['key' => self::KEY, 'token' => '', 'expiresAt' => '', 'graceUntil' => '']);
		$service = $this->service();

		$this->assertSame(self::KEY, $service->startTrial());
		$this->assertSame(self::KEY, $this->shared['key']);
		$this->assertSame(LicenseService::TOKEN_REJECTED, $service->lastError()['code']);
	}

	public function testRefreshIfDueSwallowsFailures(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$this->client->expects($this->once())->method('validate')->willThrowException(new WerkPlusApiException(WerkPlusClient::UNREACHABLE));
		$service = $this->service();
		$service->refreshIfDue();

		$this->assertSame(WerkPlusClient::UNREACHABLE, $service->lastError()['code']);
		$this->assertTrue($service->hasFeature('pw_projects'));
	}

	public function testRefreshIfDueDoesNothingWhenNotDue(): void {
		$this->storeToken();
		$this->client->expects($this->never())->method('validate');
		$this->service()->refreshIfDue();
	}

	public function testRemoveKeyClearsKeyAndTokenForAllWerkApps(): void {
		$this->storeToken();
		$this->config['werkplus_last_attempt_at'] = (string)self::NOW;
		$service = $this->service();
		$service->removeKey();

		$this->assertSame(['store_version' => '1'], $this->shared);
		$this->assertSame([], $this->config);
		$this->assertSame('free', $service->tier());
	}

	public function testStatusHidesAnOldErrorWhenNoKeyIsStored(): void {
		$this->config['werkplus_last_error'] = json_encode(['code' => 'UNREACHABLE', 'serverMessage' => '', 'at' => self::NOW]);
		$this->assertNull($this->service()->getStatus()['lastError']);

		$this->storeToken();
		$this->assertSame('UNREACHABLE', $this->service()->getStatus()['lastError']['code']);
	}

	public function testStatusMasksTheKey(): void {
		$this->storeToken();
		$status = $this->service()->getStatus();

		$this->assertSame('…69B6', $status['keyHint']);
		$this->assertStringNotContainsString(self::KEY, (string)json_encode($status));
		$this->assertSame('valid', $status['state']);
		$this->assertSame(['pw_projects', 'pw_mcp', 'pw_multi_board'], $status['features']);
	}

	public function testStatusShowsTheLicensedEmployeesFromTheToken(): void {
		$this->storeToken(['licensedEmployees' => 12]);

		$this->assertSame(12, $this->service()->getStatus()['licensedEmployees']);
	}

	public function testStatusHasNoLicensedEmployeesWithoutTheClaim(): void {
		$this->storeToken();
		$status = $this->service()->getStatus();

		$this->assertNull($status['licensedEmployees']);
		$this->assertArrayNotHasKey('employeeBucket', $status);
	}

	public function testAKeyStoredByAnotherWerkAppIsActiveWithoutInput(): void {
		$this->storeToken();
		$this->client->expects($this->never())->method('validate');
		$status = $this->service()->getStatus();

		$this->assertTrue($status['keySet']);
		$this->assertSame('valid', $status['state']);
		$this->assertFalse($status['storeOutdated']);
	}

	public function testALegacyKeyIsTakenOverOnceWhenTheSharedStoreIsEmpty(): void {
		$this->config = [
			'werkplus_key' => self::KEY,
			'werkplus_token' => 'alt',
			'werkplus_instance_uuid' => '0b6f4c1e-7d2a-4f3b-9e8c-5a1d2b3c4d5e',
			'werkplus_last_check_at' => '1',
			'werkplus_last_attempt_at' => (string)self::NOW,
		];
		$service = $this->service();

		$this->assertTrue($service->isRefreshDue(), 'Das alte Token gehört zur alten Kennung.');
		$this->assertSame(['store_version' => '1', 'key' => self::KEY], $this->shared);
		$this->assertSame([], $this->config);
	}

	public function testALegacyKeyNeverOverwritesTheSharedStore(): void {
		$this->storeToken();
		$before = $this->shared;
		$this->config = ['werkplus_key' => 'wp_ALT', 'werkplus_instance_uuid' => '0b6f4c1e-7d2a-4f3b-9e8c-5a1d2b3c4d5e'];
		$this->service()->state();

		$this->assertSame($before, $this->shared);
		$this->assertSame([], $this->config);
	}

	public function testANewerSharedStoreIsLeftAlone(): void {
		$this->storeToken();
		$this->shared['store_version'] = '2';
		$this->config = ['werkplus_key' => 'wp_ALT'];
		$before = $this->shared;
		$this->client->expects($this->never())->method('validate');
		$service = $this->service();

		$this->assertTrue($service->getStatus()['storeOutdated']);
		$this->assertFalse($service->isRefreshDue());
		foreach ([fn () => $service->activate(self::KEY), fn () => $service->refresh(), fn () => $service->removeKey(), fn () => $service->startTrial()] as $action) {
			try {
				$action();
				$this->fail('Ausnahme erwartet');
			} catch (\InvalidArgumentException $e) {
				$this->assertContains($e->getMessage(), ['storeOutdated', 'keySet']);
			}
		}
		$this->assertSame($before, $this->shared);
		$this->assertSame(['werkplus_key' => 'wp_ALT'], $this->config);
	}

	public function testRenewalNeverReplacesANewerToken(): void {
		$this->storeToken(['iat' => self::NOW + 3600]);
		$newer = $this->shared['token'];
		$this->client->method('validate')->willReturn($this->response());
		$this->service()->refresh();

		$this->assertSame($newer, $this->shared['token']);
	}

	public function testTwoRenewalsAtOnceLeaveTheNewerToken(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$newer = $this->token($this->claims(['iat' => $this->now + 60]));
		$this->client->method('validate')->willReturnCallback(function () use ($newer): array {
			$this->otherApp = function () use ($newer): void {
				$this->shared['token'] = $newer;
			};
			return $this->response(['iat' => $this->now]);
		});
		$this->service()->refresh();

		$this->assertSame($newer, $this->shared['token']);
	}

	public function testRenewalReplacesAnOlderToken(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$fresh = $this->response(['iat' => $this->now]);
		$this->client->method('validate')->willReturn($fresh);
		$this->service()->refresh();

		$this->assertSame($fresh['token'], $this->shared['token']);
	}

	public function testANewKeyReplacesKeyAndTokenTogether(): void {
		$this->storeToken(['iat' => self::NOW + 3600]);
		$fresh = $this->response();
		$this->client->method('validate')->willReturn($fresh);
		$this->service()->activate('wp_NEUERSCHLUESSEL');

		$this->assertSame('wp_NEUERSCHLUESSEL', $this->shared['key']);
		$this->assertSame($fresh['token'], $this->shared['token']);
	}

	public function testTheJobSkipsTheCallWhenAnotherWerkAppAlreadyRenewed(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$service = $this->service();
		$this->assertTrue($service->isRefreshDue());

		$this->otherApp = function (): void {
			$this->shared['token'] = $this->token($this->claims(['iat' => $this->now - 60]));
		};
		$this->client->expects($this->never())->method('validate');
		$service->refreshIfDue();
	}

	public function testANewerTokenFromAnotherWerkAppEndsTheRevokedStateDespiteClockSkew(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400 + 600;
		$this->client->method('validate')->willThrowException(new WerkPlusApiException('KEY_REVOKED'));
		try {
			$this->service()->refresh();
		} catch (WerkPlusApiException) {
		}
		$this->assertFalse($this->service()->hasFeature('pw_projects'));

		$this->shared['token'] = $this->token($this->claims(['iat' => self::NOW + 8 * 86400]));
		$this->assertTrue($this->service()->hasFeature('pw_projects'), 'Die eigene Uhr geht vor, das neue Token zählt trotzdem.');
	}

	public function testATokenForAKeyReplacedMeanwhileIsDropped(): void {
		$this->storeToken();
		$this->now = self::NOW + 8 * 86400;
		$this->client->method('validate')->willReturnCallback(function (): array {
			$this->otherApp = function (): void {
				$this->shared['key'] = 'wp_ANDERERSCHLUESSEL';
				$this->shared['token'] = $this->token($this->claims(['features' => ['pw_projects']]));
			};
			return $this->response(['iat' => $this->now]);
		});
		$this->service()->refresh();

		$this->assertSame('wp_ANDERERSCHLUESSEL', $this->shared['key']);
		$this->assertSame(['pw_projects'], $this->service()->getStatus()['features']);
	}
}
