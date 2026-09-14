<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Imap\ImapClient;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Das Antwort-Postfach (#286): Betreiber-eigenes IMAP, aus dem der
 * {@see \OCA\Projektwerk\BackgroundJob\ReplyIntakeJob} (Serie #287) eingehende
 * Antworten liest.
 *
 * **DIY, nichts verlässt die Instanz.** Der Betreiber trägt sein eigenes
 * Postfach ein; ein gehosteter Zustell-/Antwortdienst kommt später als
 * Plus-Komfort und ist hier nicht gebaut. Vorlage ist RechnungsWerk (gleicher
 * Autor, gleiche Lizenz): dortiger `SettingsService::getImapConfig()` + der
 * socket-basierte `ImapClient`.
 *
 * **Ablage in der App-Config**, nicht in einer eigenen Tabelle: Es ist genau
 * ein Satz Werte je Instanz, kein Datensatz je Nutzer. Das **Passwort** liegt
 * mit {@see ICrypto} verschlüsselt (wie in RechnungsWerk) — der Klartext steht
 * nie in der Datenbank, und `getPublicConfig()` gibt statt seiner nur ein
 * `imapPasswordSet`-Flag heraus.
 */
class ReplyMailboxSettings {

	private const APP = Application::APP_ID;

	public const KEY_ENABLED = 'reply_enabled';
	public const KEY_ADDRESS = 'reply_address';
	public const KEY_HOST = 'imap_host';
	public const KEY_PORT = 'imap_port';
	public const KEY_SECURITY = 'imap_security';
	public const KEY_USER = 'imap_user';
	public const KEY_PASSWORD = 'imap_password';
	public const KEY_FOLDER = 'imap_folder';

	/** Erlaubte Transport-Sicherheiten — wie in RechnungsWerk. */
	public const SECURITIES = ['ssl', 'starttls', 'tls'];

	private const DEFAULT_PORT = 993;
	private const DEFAULT_SECURITY = 'ssl';
	private const DEFAULT_FOLDER = 'INBOX';

	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Die Einstellungen für die Oberfläche — **ohne** Passwort.
	 *
	 * Statt des Passworts steht `imapPasswordSet` (bool) drin: Die UI zeigt
	 * damit „gespeichert, leer lassen" an, und das Geheimnis verlässt den Server
	 * nie.
	 *
	 * @return array{replyEnabled: bool, replyAddress: string, imapHost: string, imapPort: int, imapSecurity: string, imapUser: string, imapFolder: string, imapPasswordSet: bool}
	 */
	public function getPublicConfig(): array {
		return [
			'replyEnabled' => $this->config->getValueBool(self::APP, self::KEY_ENABLED, false),
			'replyAddress' => $this->config->getValueString(self::APP, self::KEY_ADDRESS),
			'imapHost' => $this->config->getValueString(self::APP, self::KEY_HOST),
			'imapPort' => $this->config->getValueInt(self::APP, self::KEY_PORT, self::DEFAULT_PORT),
			'imapSecurity' => $this->config->getValueString(self::APP, self::KEY_SECURITY, self::DEFAULT_SECURITY),
			'imapUser' => $this->config->getValueString(self::APP, self::KEY_USER),
			'imapFolder' => $this->config->getValueString(self::APP, self::KEY_FOLDER, self::DEFAULT_FOLDER),
			'imapPasswordSet' => $this->config->getValueString(self::APP, self::KEY_PASSWORD) !== '',
		];
	}

	/**
	 * Einstellungen speichern.
	 *
	 * Nur mitgeschickte Schlüssel werden angefasst (die UI sendet das Passwort
	 * nur bei Neueingabe). Ein **leeres** `imapPassword` lässt das gespeicherte
	 * unberührt — sonst löschte jedes Speichern der Verbindungsdaten das
	 * Passwort mit. Zum Löschen dient `imapPasswordClear`.
	 *
	 * @param array<string, mixed> $data Teilmenge der Felder aus {@see getPublicConfig()}
	 *                                    plus optional `imapPassword`/`imapPasswordClear`.
	 */
	public function save(array $data): void {
		if (array_key_exists('replyEnabled', $data)) {
			$this->config->setValueBool(self::APP, self::KEY_ENABLED, (bool)$data['replyEnabled']);
		}
		if (array_key_exists('replyAddress', $data)) {
			$this->config->setValueString(self::APP, self::KEY_ADDRESS, trim((string)$data['replyAddress']));
		}
		if (array_key_exists('imapHost', $data)) {
			$this->config->setValueString(self::APP, self::KEY_HOST, trim((string)$data['imapHost']));
		}
		if (array_key_exists('imapPort', $data)) {
			$port = (int)$data['imapPort'];
			$this->config->setValueString(self::APP, self::KEY_PORT, (string)($port > 0 ? $port : self::DEFAULT_PORT));
		}
		if (array_key_exists('imapSecurity', $data)) {
			$security = in_array($data['imapSecurity'], self::SECURITIES, true) ? (string)$data['imapSecurity'] : self::DEFAULT_SECURITY;
			$this->config->setValueString(self::APP, self::KEY_SECURITY, $security);
		}
		if (array_key_exists('imapUser', $data)) {
			$this->config->setValueString(self::APP, self::KEY_USER, trim((string)$data['imapUser']));
		}
		if (array_key_exists('imapFolder', $data)) {
			$folder = trim((string)$data['imapFolder']);
			$this->config->setValueString(self::APP, self::KEY_FOLDER, $folder === '' ? self::DEFAULT_FOLDER : $folder);
		}

		// Passwort: verschlüsselt ablegen; leer heißt „unverändert lassen".
		if (!empty($data['imapPasswordClear'])) {
			$this->config->setValueString(self::APP, self::KEY_PASSWORD, '');
		} elseif (isset($data['imapPassword']) && (string)$data['imapPassword'] !== '') {
			$this->config->setValueString(self::APP, self::KEY_PASSWORD, $this->crypto->encrypt((string)$data['imapPassword']));
		}
	}

	/**
	 * Der entschlüsselte IMAP-Zugang für den Einlese-Job und den Verbindungstest,
	 * oder `null`, wenn kein Host hinterlegt ist.
	 *
	 * @return array{host: string, port: int, security: string, user: string, password: string, folder: string}|null
	 */
	public function getImapConfig(): ?array {
		$host = trim($this->config->getValueString(self::APP, self::KEY_HOST));
		if ($host === '') {
			return null;
		}

		return [
			'host' => $host,
			'port' => $this->config->getValueInt(self::APP, self::KEY_PORT, self::DEFAULT_PORT),
			'security' => $this->config->getValueString(self::APP, self::KEY_SECURITY, self::DEFAULT_SECURITY),
			'user' => $this->config->getValueString(self::APP, self::KEY_USER),
			'password' => $this->storedPassword(),
			'folder' => $this->config->getValueString(self::APP, self::KEY_FOLDER, self::DEFAULT_FOLDER) ?: self::DEFAULT_FOLDER,
		];
	}

	/**
	 * Ist das Antwort-Postfach aktiv **und** brauchbar konfiguriert?
	 *
	 * Der Schalter allein genügt nicht: Ohne Host gibt es nichts zu lesen. Der
	 * Einlese-Job (#287) und der Reply-To-Kopf (#287) fragen hierüber.
	 */
	public function isEnabled(): bool {
		return $this->config->getValueBool(self::APP, self::KEY_ENABLED, false)
			&& trim($this->config->getValueString(self::APP, self::KEY_HOST)) !== '';
	}

	/**
	 * Die Adresse, die als `Reply-To` gesetzt wird (dem Kunden gehörend), oder
	 * leer. Nur relevant, wenn {@see isEnabled()}.
	 */
	public function getReplyAddress(): string {
		return trim($this->config->getValueString(self::APP, self::KEY_ADDRESS));
	}

	/**
	 * Eine IMAP-Verbindung mit den übergebenen (oder gespeicherten) Daten
	 * aufbauen, anmelden, den Ordner wählen — und wieder abmelden. Wirft
	 * {@see \OCA\Projektwerk\Imap\ImapException} bei jedem Fehlschlag; der
	 * Controller macht daraus eine verständliche Meldung.
	 *
	 * Kein Passwort im Aufruf → das gespeicherte wird genommen (die UI schickt
	 * es beim Test nur mit, wenn es gerade neu eingegeben wurde).
	 *
	 * @param array<string, mixed> $data Verbindungsfelder; `imapPassword` optional.
	 */
	public function testConnection(array $data): void {
		$host = trim((string)($data['imapHost'] ?? ''));
		$port = (int)($data['imapPort'] ?? self::DEFAULT_PORT);
		$security = in_array($data['imapSecurity'] ?? '', self::SECURITIES, true) ? (string)$data['imapSecurity'] : self::DEFAULT_SECURITY;
		$user = trim((string)($data['imapUser'] ?? ''));
		$folder = trim((string)($data['imapFolder'] ?? '')) ?: self::DEFAULT_FOLDER;
		$password = isset($data['imapPassword']) && (string)$data['imapPassword'] !== ''
			? (string)$data['imapPassword']
			: $this->storedPassword();

		$client = new ImapClient($host, $port > 0 ? $port : self::DEFAULT_PORT, $security);
		try {
			$client->connect();
			$client->login($user, $password);
			$client->select($folder);
		} finally {
			$client->logout();
		}
	}

	/**
	 * Das gespeicherte Passwort, entschlüsselt — oder leer, wenn keines
	 * abgelegt ist bzw. die Entschlüsselung scheitert (wie in RechnungsWerk:
	 * lieber leer als eine Ausnahme, die den Job reißt).
	 */
	private function storedPassword(): string {
		$stored = $this->config->getValueString(self::APP, self::KEY_PASSWORD);
		if ($stored === '') {
			return '';
		}

		try {
			return $this->crypto->decrypt($stored);
		} catch (\Throwable $e) {
			$this->logger->warning('ProjektWerk: IMAP-Passwort ließ sich nicht entschlüsseln', ['exception' => $e]);

			return '';
		}
	}
}
