<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Security;

use OCA\Projektwerk\Controller\GithubTokenController;
use OCA\Projektwerk\Controller\ReplyMailboxController;
use OCA\Projektwerk\Imap\ImapClient;
use OCA\Projektwerk\Mcp\BearerAuthenticator;
use OCA\Projektwerk\Mcp\OidcTokenValidator;
use OCA\Projektwerk\Mcp\TokenValidator;
use OCA\Projektwerk\Service\GithubService;
use OCA\Projektwerk\Service\ReplyMailboxSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Geheimnisse gehören nicht in Stacktraces.
 *
 * Nextcloud schreibt bei einer geloggten Ausnahme die Argumente jedes
 * Aufrufrahmens mit. Ein Token oder Passwort, das als Parameter durchgereicht
 * wird, landet so im Log — es sei denn, der Parameter trägt
 * `#[\SensitiveParameter]`. Diese Liste nennt die Stellen, an denen ein
 * Geheimnis hereinkommt; fällt das Attribut dort weg, fällt der Test.
 */
class SensitiveParameterTest extends TestCase {

	/**
	 * @return array<string, array{class-string, string, string}>
	 */
	public static function geheimeParameter(): array {
		return [
			'GitHub-Token speichern' => [GithubService::class, 'storeToken', 'token'],
			'GitHub-Token am Controller' => [GithubTokenController::class, 'update', 'token'],
			'IMAP-Anmeldung' => [ImapClient::class, 'login', 'password'],
			'IMAP-Befehl (trägt die Anmeldung)' => [ImapClient::class, 'command', 'command'],
			'IMAP-Anführung' => [ImapClient::class, 'quote', 'value'],
			'Postfach speichern (Controller)' => [ReplyMailboxController::class, 'save', 'imapPassword'],
			'Postfach testen (Controller)' => [ReplyMailboxController::class, 'test', 'imapPassword'],
			'Postfach speichern (Dienst)' => [ReplyMailboxSettings::class, 'save', 'data'],
			'Postfach testen (Dienst)' => [ReplyMailboxSettings::class, 'testConnection', 'data'],
			'MCP-Authorization-Kopfzeile' => [BearerAuthenticator::class, 'authenticate', 'authorizationHeader'],
			'MCP-Token (Schnittstelle)' => [TokenValidator::class, 'userIdFor', 'token'],
			'MCP-Token (OIDC)' => [OidcTokenValidator::class, 'userIdFor', 'token'],
		];
	}

	/**
	 * @param class-string $class
	 */
	#[DataProvider('geheimeParameter')]
	public function testSecretParametersAreMarkedSensitive(string $class, string $method, string $parameter): void {
		$gefunden = null;
		foreach ((new \ReflectionMethod($class, $method))->getParameters() as $p) {
			if ($p->getName() === $parameter) {
				$gefunden = $p;
			}
		}

		$this->assertNotNull($gefunden, "{$class}::{$method}() hat keinen Parameter \${$parameter}.");
		$this->assertNotSame(
			[],
			$gefunden->getAttributes(\SensitiveParameter::class),
			"{$class}::{$method}(\${$parameter}) trägt ein Geheimnis und braucht #[\\SensitiveParameter].",
		);
	}
}
