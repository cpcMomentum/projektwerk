<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\GithubService;
use OCA\Projektwerk\Service\GithubTransferException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Die GitHub-Anbindung (#12) — Token-Ablage und Issue-Anlage, ohne Netz.
 *
 * Zwei Zusagen prüft dieser Test besonders:
 *
 * 1. **Der Token verlässt den Dienst nie.** Nach außen ist allein erfragbar,
 *    *ob* einer hinterlegt ist — {@see testHasTokenNeverExposesTheToken}.
 * 2. **Kein Fehlausgang wird zum rohen HTTP-Status.** Jeder Statuscode und jede
 *    Verbindungsstörung wird zu einer {@see GithubTransferException} mit
 *    deutscher, handlungsleitender Meldung — der Vorgang bleibt fail-closed.
 */
class GithubServiceTest extends TestCase {

	private ICredentialsManager&MockObject $credentials;
	private IClientService&MockObject $clientService;
	private IClient&MockObject $client;
	private LoggerInterface&MockObject $logger;
	private GithubService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->credentials = $this->createMock(ICredentialsManager::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->clientService->method('newClient')->willReturn($this->client);

		$this->service = new GithubService($this->credentials, $this->clientService, $this->logger);
	}

	public function testStoreTokenRejectsAnEmptyValue(): void {
		$this->credentials->expects($this->never())->method('store');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->storeToken('anna', '   ');
	}

	public function testStoreTokenTrimsAndStoresUnderTheSharedIdentifier(): void {
		$this->credentials->expects($this->once())
			->method('store')
			->with('anna', GithubService::TOKEN_ID, 'ghp_secret');

		$this->service->storeToken('anna', '  ghp_secret  ');
	}

	public function testHasTokenNeverExposesTheToken(): void {
		$this->credentials->method('retrieve')
			->willReturnMap([
				['anna', GithubService::TOKEN_ID, 'ghp_secret'],
				['bert', GithubService::TOKEN_ID, null],
			]);

		$this->assertTrue($this->service->hasToken('anna'));
		// Bert hat nichts hinterlegt — und ein leerer Rückgabewert gilt als
		// „kein Token", nicht als Token mit leerem Inhalt.
		$this->assertFalse($this->service->hasToken('bert'));
	}

	public function testCreateIssueReturnsNumberAndUrlOnCreated(): void {
		$this->givenToken('anna', 'ghp_secret');

		$response = $this->response(201, (string)json_encode([
			'number' => 42,
			'html_url' => 'https://github.com/acme/app/issues/42',
			'title' => 'Egal',
		]));
		$this->client->expects($this->once())
			->method('post')
			->with(
				'https://api.github.com/repos/acme/app/issues',
				$this->callback(function (array $options): bool {
					$this->assertSame('Bearer ghp_secret', $options['headers']['Authorization']);
					$this->assertFalse($options['http_errors']);
					$decoded = json_decode((string)$options['body'], true);
					$this->assertSame('Titel', $decoded['title']);
					$this->assertSame('Rumpf', $decoded['body']);

					return true;
				}),
			)
			->willReturn($response);

		$result = $this->service->createIssue('anna', 'acme/app', 'Titel', 'Rumpf');

		$this->assertSame(42, $result['number']);
		$this->assertSame('https://github.com/acme/app/issues/42', $result['url']);
	}

	public function testCreateIssueWithoutATokenExplainsWhere(): void {
		$this->credentials->method('retrieve')->willReturn(null);
		$this->client->expects($this->never())->method('post');

		$this->expectException(GithubTransferException::class);
		$this->expectExceptionMessage('Meine Einstellungen');
		$this->service->createIssue('anna', 'acme/app', 'Titel', 'Rumpf');
	}

	public function testCreateIssueRejectsAMalformedRepo(): void {
		$this->givenToken('anna', 'ghp_secret');
		$this->client->expects($this->never())->method('post');

		$this->expectException(GithubTransferException::class);
		$this->expectExceptionMessage('owner/repo');
		$this->service->createIssue('anna', 'kein-slug', 'Titel', 'Rumpf');
	}

	public function testUnauthorizedBecomesARenewHint(): void {
		$this->givenToken('anna', 'ghp_secret');
		$this->client->method('post')->willReturn($this->response(401, '{}'));

		$this->expectException(GithubTransferException::class);
		$this->expectExceptionMessage('ungültig');
		$this->service->createIssue('anna', 'acme/app', 'Titel', 'Rumpf');
	}

	public function testNotFoundBecomesARepositoryHint(): void {
		$this->givenToken('anna', 'ghp_secret');
		$this->client->method('post')->willReturn($this->response(404, '{}'));

		$this->expectException(GithubTransferException::class);
		$this->expectExceptionMessage('Repository');
		$this->service->createIssue('anna', 'acme/app', 'Titel', 'Rumpf');
	}

	public function testAConnectionFailureBecomesATransferException(): void {
		$this->givenToken('anna', 'ghp_secret');
		$this->client->method('post')->willThrowException(new \RuntimeException('Netz weg'));
		$this->logger->expects($this->once())->method('warning');

		$this->expectException(GithubTransferException::class);
		$this->expectExceptionMessage('nicht erreichbar');
		$this->service->createIssue('anna', 'acme/app', 'Titel', 'Rumpf');
	}

	public function testACreatedResponseWithoutNumberIsRejected(): void {
		$this->givenToken('anna', 'ghp_secret');
		$this->client->method('post')->willReturn($this->response(201, '{"title":"ohne Nummer"}'));

		$this->expectException(GithubTransferException::class);
		$this->service->createIssue('anna', 'acme/app', 'Titel', 'Rumpf');
	}

	public function testSearchReposWithoutATokenExplainsWhere(): void {
		$this->credentials->method('retrieve')->willReturn(null);
		$this->client->expects($this->never())->method('get');

		$this->expectException(GithubTransferException::class);
		$this->expectExceptionMessage('Meine Einstellungen');
		$this->service->searchRepos('anna', '');
	}

	public function testSearchReposReturnsFullNamesFilteredByQuery(): void {
		$this->givenToken('anna', 'ghp_secret');
		$this->client->method('get')->willReturn($this->response(200, (string)json_encode([
			['full_name' => 'acme/app'],
			['full_name' => 'acme/website'],
			['full_name' => 'other/tool'],
		])));

		// Leerer Suchbegriff: alles (bis zum Deckel).
		$this->assertSame(['acme/app', 'acme/website', 'other/tool'], $this->service->searchRepos('anna', ''));

		// Mit Suchbegriff: Teilstring, Groß-/Kleinschreibung egal.
		$this->assertSame(['acme/app', 'acme/website'], $this->service->searchRepos('anna', 'ACME'));
		$this->assertSame(['acme/website'], $this->service->searchRepos('anna', 'web'));
	}

	public function testSearchReposMapsAnErrorStatus(): void {
		$this->givenToken('anna', 'ghp_secret');
		$this->client->method('get')->willReturn($this->response(401, '{}'));

		$this->expectException(GithubTransferException::class);
		$this->expectExceptionMessage('ungültig');
		$this->service->searchRepos('anna', '');
	}

	public function testSearchReposSurvivesEntriesWithoutAFullName(): void {
		$this->givenToken('anna', 'ghp_secret');
		$this->client->method('get')->willReturn($this->response(200, (string)json_encode([
			['full_name' => 'acme/app'],
			['id' => 7],
			'nonsense',
		])));

		$this->assertSame(['acme/app'], $this->service->searchRepos('anna', ''));
	}

	private function givenToken(string $userId, string $token): void {
		$this->credentials->method('retrieve')
			->with($userId, GithubService::TOKEN_ID)
			->willReturn($token);
	}

	private function response(int $status, string $body): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}
}
