<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\ReplyMailboxSettings;
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
 */
class ReplyMailboxSettingsTest extends TestCase {

	/** @var array<string, string> */
	private array $store = [];

	private function settings(): ReplyMailboxSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->store[$key] ?? $default,
		);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => isset($this->store[$key]) ? (int)$this->store[$key] : $default,
		);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => isset($this->store[$key]) ? $this->store[$key] === '1' : $default,
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;

				return true;
			},
		);
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->store[$key] = $value ? '1' : '0';

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
