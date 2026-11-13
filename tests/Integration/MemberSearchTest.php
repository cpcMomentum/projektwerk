<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Controller\MemberSearchController;
use OCA\Projektwerk\Db\MemberMapper;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;

class MemberSearchTest extends IntegrationTestCase {

	/** @var array<string, array{0: string, 1: bool}> */
	private const KONTEN = [
		LeakMatrixFixture::ANNA => ['Anna', true],
		'ms-kollege' => ['Max Kollege', true],
		'ms-fremdling' => ['Max Fremdling', true],
		'ms-fremd' => ['Max Fremd', true],
		'ms-weg' => ['Max Ausgeschieden', false],
	];

	private LeakMatrixFixture $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new LeakMatrixFixture();
	}

	public function testByDefaultPartialNamesFindEnabledAccountsOnly(): void {
		$this->assertSame(['ms-kollege', 'ms-fremdling', 'ms-fremd'], $this->found('Max', []));
	}

	public function testRestrictedToGroupsOnlyTheFullNameFindsAnAccount(): void {
		$config = ['shareapi_restrict_user_enumeration_to_group' => 'yes'];

		$this->assertSame([], $this->found('Max', $config));
		$this->assertSame(['ms-fremd'], $this->found('Max Fremd', $config));
	}

	public function testWithoutListingOnlyAFullMatchFindsAnAccount(): void {
		$config = ['shareapi_allow_share_dialog_user_enumeration' => 'no'];

		$this->assertSame([], $this->found('Max', $config));
		$this->assertSame(['ms-fremd'], $this->found('ms-fremd', $config));

		$ohneVoll = $config + ['shareapi_restrict_user_enumeration_full_match' => 'no'];
		$this->assertSame([], $this->found('ms-fremd', $ohneVoll));

		$ohneKennung = $config + ['shareapi_restrict_user_enumeration_full_match_userid' => 'no'];
		$this->assertSame([], $this->found('ms-fremd', $ohneKennung));
		$this->assertSame(['ms-fremd'], $this->found('Max Fremd', $ohneKennung));
	}

	public function testAnExactMatchComesFirst(): void {
		$this->assertSame(['ms-fremd', 'ms-fremdling'], $this->found('Max Fremd', []));
	}

	public function testThePhoneBookLimitAloneAllowsNoPartialMatches(): void {
		$this->assertSame([], $this->found('Max', ['shareapi_restrict_user_enumeration_to_phone' => 'yes']));
	}

	/** @param array<string, string> $config */
	private function found(string $search, array $config): array {
		$konten = [];
		foreach (self::KONTEN as $uid => [$name, $aktiv]) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($name);
			$user->method('getEMailAddress')->willReturn(null);
			$user->method('isEnabled')->willReturn($aktiv);
			$user->method('getBackendClassName')->willReturn('Database');
			$konten[$uid] = $user;
		}

		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $konten[$uid] ?? null);
		$users->method('getByEmail')->willReturn([]);
		$users->method('searchDisplayName')->willReturnCallback(static fn (string $term): array => array_values(array_filter(
			$konten,
			static fn (IUser $u): bool => $u->getUID() !== LeakMatrixFixture::ANNA && str_contains(mb_strtolower($u->getDisplayName()), mb_strtolower($term)),
		)));

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $config[$key] ?? $default,
		);

		$controller = new MemberSearchController(
			$this->createStub(IRequest::class),
			$users,
			$appConfig,
			Server::get(MemberMapper::class),
			Server::get(BoardAccess::class),
			LeakMatrixFixture::ANNA,
			Server::get(IFactory::class)->get('projektwerk'),
		);

		$response = $controller->search($this->fixture->boardId, $search);
		$this->assertSame(200, $response->getStatus());

		return array_column($response->getData()['users'], 'userId');
	}
}
