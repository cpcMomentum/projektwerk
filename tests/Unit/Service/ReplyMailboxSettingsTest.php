<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\ReplyMailboxSettings;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Das Antwort-Postfach (#286) — Speichern, Lesen, Passwort-Umgang.
 *
 * Der Verbindungstest hängt an einem echten IMAP-Server und gehört in den
 * Rauchtest; prüfbar ohne Netz ist alles davor: dass die App-Config die
 * richtigen Werte bekommt, das Passwort **verschlüsselt** abgelegt und nie im
 * Klartext herausgegeben wird, und dass ein leeres Passwort das gespeicherte
 * nicht löscht. `IAppConfig` ist ein In-Memory-Double, `ICrypto` markiert den
 * Klartext sichtbar (`ENC(...)`), damit die Verschlüsselung im Test belegbar ist.
 *
 * **Das Double bildet NCs Typbindung nach** (#303): Ein Schlüssel merkt sich den
 * Typ, mit dem er geschrieben wurde, und ein Lesen mit falschem Typ wirft — wie
 * das echte AppConfig — eine {@see AppConfigTypeConflictException}. Ohne das
 * schlüpfte genau der Fehler durch, der `getPublicConfig()`/`getImapConfig()` mit
 * HTTP 500 riss: Port als String schreiben, als Int lesen. Ein Double, das jeden
 * Wert als String hält und beim Lesen frei castet, kann diese Klasse von Fehlern
 * strukturell nicht sehen.
 */
class ReplyMailboxSettingsTest extends TestCase {

	/** @var array<string, string|int|bool> */
	private array $store = [];

	/** @var array<string, 'string'|'int'|'bool'> Typ, mit dem der Key geschrieben wurde. */
	private array $types = [];

	private function assertType(string $key, string $want): void {
		if (isset($this->types[$key]) && $this->types[$key] !== $want) {
			throw new AppConfigTypeConflictException('conflict with value type from database');
		}
	}

	private function settings(): ReplyMailboxSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertType($key, 'string');

				return isset($this->store[$key]) ? (string)$this->store[$key] : $default;
			},
		);
		$config->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default = 0): int {
				$this->assertType($key, 'int');

				return isset($this->store[$key]) ? (int)$this->store[$key] : $default;
			},
		);
		$config->method('getValueBool')->willReturnCallback(
			function (string $app, string $key, bool $default = false): bool {
				$this->assertType($key, 'bool');

				return isset($this->store[$key]) ? (bool)$this->store[$key] : $default;
			},
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				$this->types[$key] = 'string';

				return true;
			},
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->store[$key] = $value;
				$this->types[$key] = 'int';

				return true;
			},
		);
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->store[$key] = $value;
				$this->types[$key] = 'bool';

				return true;
			},
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'ENC(' . $plain . ')');
		$crypto->method('decrypt')->willReturnCallback(
			static fn (string $cipher): string => preg_replace('/^ENC\((.*)\)$/', '$1', $cipher) ?? '',
		);

		return new ReplyMailboxSettings($config, $crypto, $this->createMock(LoggerInterface::class));
	}

	public function testDefaultsWhenNothingStored(): void {
		$config = $this->settings()->getPublicConfig();

		$this->assertFalse($config['replyEnabled']);
		$this->assertSame(993, $config['imapPort']);
		$this->assertSame('ssl', $config['imapSecurity']);
		$this->assertSame('INBOX', $config['imapFolder']);
		$this->assertFalse($config['imapPasswordSet']);
	}

	/**
	 * #303: save() legt den Port string-typisiert ab. Beide Lesepfade müssen ihn
	 * danach als int zurückgeben, ohne am Typkonflikt zu zerbrechen. Mit dem
	 * fehlerhaften getValueInt() würfe das typtreue Double hier — genau der
	 * HTTP-500-Fall aus der Produktivinstanz.
	 */
	public function testPortSurvivesSaveRoundTrip(): void {
		$settings = $this->settings();
		$settings->save(['imapHost' => 'imap.firma.de', 'imapPort' => 143]);

		$public = $settings->getPublicConfig();
		$this->assertSame(143, $public['imapPort']);

		$cfg = $settings->getImapConfig();
		$this->assertNotNull($cfg);
		$this->assertSame(143, $cfg['port']);
	}

	public function testSaveStoresFieldsAndEncryptsPassword(): void {
		$settings = $this->settings();
		$settings->save([
			'replyEnabled' => true,
			'replyAddress' => 'projekte@firma.de',
			'imapHost' => 'imap.firma.de',
			'imapPort' => 993,
			'imapSecurity' => 'ssl',
			'imapUser' => 'projekte@firma.de',
			'imapFolder' => 'INBOX',
			'imapPassword' => 'geheim',
		]);

		// Passwort liegt verschlüsselt, nicht im Klartext.
		$this->assertSame('ENC(geheim)', $this->store[ReplyMailboxSettings::KEY_PASSWORD]);

		$public = $settings->getPublicConfig();
		$this->assertTrue($public['replyEnabled']);
		$this->assertSame('imap.firma.de', $public['imapHost']);
		$this->assertTrue($public['imapPasswordSet']);
		$this->assertArrayNotHasKey('imapPassword', $public, 'Das Passwort darf die Oberfläche nie erreichen.');
	}

	public function testEmptyPasswordLeavesStoredPasswordUntouched(): void {
		$settings = $this->settings();
		$settings->save(['imapPassword' => 'geheim']);
		$this->assertSame('ENC(geheim)', $this->store[ReplyMailboxSettings::KEY_PASSWORD]);

		// Erneutes Speichern der Verbindungsdaten ohne Passwort darf es nicht löschen.
		$settings->save(['imapHost' => 'imap.firma.de', 'imapPassword' => '']);
		$this->assertSame('ENC(geheim)', $this->store[ReplyMailboxSettings::KEY_PASSWORD]);
	}

	public function testPasswordClearRemovesStoredPassword(): void {
		$settings = $this->settings();
		$settings->save(['imapPassword' => 'geheim']);

		$settings->save(['imapPasswordClear' => true]);
		$this->assertSame('', $this->store[ReplyMailboxSettings::KEY_PASSWORD]);
		$this->assertFalse($settings->getPublicConfig()['imapPasswordSet']);
	}

	public function testInvalidSecurityFallsBackToSsl(): void {
		$settings = $this->settings();
		$settings->save(['imapSecurity' => 'plaintext-nonsense']);

		$this->assertSame('ssl', $settings->getPublicConfig()['imapSecurity']);
	}

	public function testGetImapConfigNullWithoutHost(): void {
		$this->assertNull($this->settings()->getImapConfig());
	}

	public function testGetImapConfigDecryptsPassword(): void {
		$settings = $this->settings();
		$settings->save([
			'imapHost' => 'imap.firma.de',
			'imapPort' => 143,
			'imapSecurity' => 'starttls',
			'imapUser' => 'u',
			'imapPassword' => 'geheim',
			'imapFolder' => 'Antworten',
		]);

		$cfg = $settings->getImapConfig();
		$this->assertNotNull($cfg);
		$this->assertSame('imap.firma.de', $cfg['host']);
		$this->assertSame(143, $cfg['port']);
		$this->assertSame('starttls', $cfg['security']);
		$this->assertSame('geheim', $cfg['password'], 'Der Job braucht das entschlüsselte Passwort.');
		$this->assertSame('Antworten', $cfg['folder']);
	}

	public function testIsEnabledNeedsSwitchAndHost(): void {
		$settings = $this->settings();
		$this->assertFalse($settings->isEnabled());

		$settings->save(['replyEnabled' => true]);
		$this->assertFalse($settings->isEnabled(), 'Aktiviert ohne Host ist nicht brauchbar.');

		$settings->save(['imapHost' => 'imap.firma.de']);
		$this->assertTrue($settings->isEnabled());
	}
}
