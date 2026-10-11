<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Mcp;

use OCA\Projektwerk\Mcp\BearerAuthenticator;
use OCA\Projektwerk\Mcp\McpAuthException;
use OCA\Projektwerk\Mcp\McpConfig;
use OCA\Projektwerk\Mcp\TokenValidator;
use OCA\Projektwerk\Service\AccountType;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class BearerAuthenticatorTest extends TestCase {

	private const RESOURCE = 'https://cloud.example/apps/projektwerk/mcp';
	private const PRM = 'https://cloud.example/apps/projektwerk/mcp/oauth-protected-resource';
	private const CLIENT = 'claude-client';

	private McpConfig $config;
	private TokenValidator $validator;
	private AccountType $accountType;
	private bool $userEnabled = true;
	private bool $appEnabledForUser = true;
	private bool $userExists = true;
	/** @var array<string, mixed> */
	private array $cache = [];

	protected function setUp(): void {
		$this->config = $this->createStub(McpConfig::class);
		$this->config->method('isEnabled')->willReturn(true);
		$this->config->method('resourceUrl')->willReturn(self::RESOURCE);
		$this->config->method('allowedClients')->willReturn([self::CLIENT]);
		$this->validator = $this->createStub(TokenValidator::class);
		$this->accountType = $this->createStub(AccountType::class);
	}

	public function testDisabledEndpointIs404(): void {
		$config = $this->createStub(McpConfig::class);
		$config->method('isEnabled')->willReturn(false);

		$this->assertRejected(404, null, fn () => $this->authenticator($config)->authenticate('Bearer ' . $this->token()));
	}

	public function testMissingTokenGetsTheExactChallenge(): void {
		$this->assertRejected(
			401,
			'Bearer resource_metadata="' . self::PRM . '", scope="openid profile offline_access"',
			fn () => $this->authenticator()->authenticate(''),
		);
	}

	public function testValidAccessTokenYieldsTheCaller(): void {
		$this->validator->method('userIdFor')->willReturn('anna');

		$caller = $this->authenticator()->authenticate('Bearer ' . $this->token());

		$this->assertSame('anna', $caller->userId);
		$this->assertSame(self::CLIENT, $caller->clientId);
	}

	/**
	 * Gültig laut oidc reicht nicht: Das Token muss für diesen Endpunkt und einen zugelassenen
	 * Client sein, ein Zugriffstoken und keins für eine andere Person.
	 */
	public function testTokensThatOidcAcceptsButAreNotForUsAreRejected(): void {
		$this->validator->method('userIdFor')->willReturn('anna');
		$cases = [
			'id token' => $this->token(header: ['typ' => 'JWT']),
			'foreign client' => $this->token(['client_id' => 'other-app', 'azp' => 'other-app']),
			'foreign audience' => $this->token(['aud' => 'https://other.example/api']),
			'other subject' => $this->token(['sub' => 'bert']),
			'no openid scope' => $this->token(['scope' => 'profile']),
			'opaque' => 'not-a-jwt',
		];

		foreach ($cases as $label => $token) {
			$this->assertRejected(401, null, fn () => $this->authenticator()->authenticate('Bearer ' . $token), $label);
		}
	}

	public function testInvalidTokenIs401InvalidTokenAndCountsTowards429(): void {
		$this->validator->method('userIdFor')->willReturn(null);
		$authenticator = $this->authenticator();
		$token = $this->token();

		for ($i = 0; $i < 10; $i++) {
			$this->assertRejected(401, null, fn () => $authenticator->authenticate('Bearer ' . $token));
		}
		$this->assertRejected(429, null, fn () => $authenticator->authenticate('Bearer ' . $token));
	}

	public function testMalformedTokensNeitherReachOidcNorFillTheCounter(): void {
		$validator = $this->createMock(TokenValidator::class);
		$validator->expects($this->never())->method('userIdFor');
		$this->validator = $validator;
		$authenticator = $this->authenticator();

		foreach (['not-a-jwt', $this->token(header: ['typ' => 'JWT']), $this->token(['client_id' => 'x', 'azp' => 'x'])] as $token) {
			$this->assertRejected(401, null, fn () => $authenticator->authenticate('Bearer ' . $token));
		}
		$this->assertSame([], $this->cache);
	}

	public function testExpiredTokenIsRejectedEvenIfOidcSaysValid(): void {
		$this->validator->method('userIdFor')->willReturn('anna');

		$this->assertRejected(401, null, fn () => $this->authenticator()->authenticate('Bearer ' . $this->token(['exp' => 999])));
	}

	/**
	 * Dieselbe Grenze wie die Weboberfläche: gesperrtes Konto oder App per Gruppe nicht freigegeben.
	 */
	public function testUsersTheWebUiWouldTurnAwayAreRejected(): void {
		$this->validator->method('userIdFor')->willReturn('anna');

		foreach (['userExists' => 'gelöscht', 'userEnabled' => 'deaktiviert', 'appEnabledForUser' => 'App nicht freigegeben'] as $flag => $label) {
			$this->userExists = $this->userEnabled = $this->appEnabledForUser = true;
			$this->{$flag} = false;

			$this->assertRejected(401, null, fn () => $this->authenticator()->authenticate('Bearer ' . $this->token()), $label);
		}
	}

	public function testGuestsAreForbidden(): void {
		$this->validator->method('userIdFor')->willReturn('anna');
		$this->accountType->method('isGuest')->willReturn(true);

		$this->assertRejected(403, null, fn () => $this->authenticator()->authenticate('Bearer ' . $this->token()));
	}

	public function testAudienceMayBeAList(): void {
		$this->validator->method('userIdFor')->willReturn('anna');

		$caller = $this->authenticator()->authenticate('Bearer ' . $this->token(['aud' => ['x', self::RESOURCE]]));

		$this->assertSame('anna', $caller->userId);
	}

	private function authenticator(?McpConfig $config = null): BearerAuthenticator {
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn(self::PRM);

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cache[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->cache[$key] = $value;

			return true;
		});
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$user = $this->createStub(IUser::class);
		$user->method('isEnabled')->willReturnCallback(fn (): bool => $this->userEnabled);
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (): ?IUser => $this->userExists ? $user : null);
		$apps = $this->createStub(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (): bool => $this->appEnabledForUser);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);

		return new BearerAuthenticator($config ?? $this->config, $this->validator, $this->accountType, $urls, $users, $apps, $time, $factory);
	}

	/**
	 * @param array<string, mixed> $claims
	 * @param array<string, mixed> $header
	 */
	private function token(array $claims = [], array $header = ['typ' => 'at+jwt', 'alg' => 'RS256']): string {
		$claims += [
			'iss' => 'https://cloud.example',
			'sub' => 'anna',
			'aud' => self::RESOURCE,
			'client_id' => self::CLIENT,
			'azp' => self::CLIENT,
			'scope' => 'openid profile offline_access',
			'exp' => 1_800_000_900,
		];
		$encode = static fn (array $part): string => rtrim(strtr(base64_encode((string)json_encode($part)), '+/', '-_'), '=');

		return $encode($header) . '.' . $encode($claims) . '.signature';
	}

	private function assertRejected(int $status, ?string $challenge, callable $call, string $label = ''): void {
		try {
			$call();
			$this->fail('Erwartet: ' . $status . ' ' . $label);
		} catch (McpAuthException $e) {
			$this->assertSame($status, $e->status, $label);
			if ($challenge !== null) {
				$this->assertSame($challenge, $e->challenge, $label);
			}
			if ($status === 401 && $challenge === null) {
				$this->assertNotNull($e->challenge, $label . ': 401 ohne Challenge');
			}
		}
	}
}
