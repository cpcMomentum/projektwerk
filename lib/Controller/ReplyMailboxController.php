<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Imap\ImapException;
use OCA\Projektwerk\Service\ReplyMailboxSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Das Antwort-Postfach (#286) — Admin-Einstellungen.
 *
 * **Admin-only ohne eigene Prüfung.** Keine Methode trägt `#[NoAdminRequired]`;
 * damit verlangt Nextclouds Middleware für jede von ihnen ein Administrator-Konto.
 * Das ist die Gegenprobe zum board-scoped {@see SettingsController}, der überall
 * `#[NoAdminRequired]` trägt: Ein Instanz-Postfach gehört dem Betreiber, nicht
 * einem Projektmitglied.
 *
 * Der Klartext des Passworts verlässt den Server nie: {@see config()} gibt nur
 * `imapPasswordSet` heraus, gespeichert wird verschlüsselt.
 */
class ReplyMailboxController extends Controller {

	public function __construct(
		IRequest $request,
		private ReplyMailboxSettings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Die aktuellen Einstellungen — ohne Passwort (nur `imapPasswordSet`).
	 */
	public function config(): JSONResponse {
		return new JSONResponse($this->settings->getPublicConfig());
	}

	/**
	 * Einstellungen speichern und die neue (passwortlose) Fassung zurückgeben.
	 *
	 * Leeres `imapPassword` lässt das gespeicherte unberührt; `imapPasswordClear`
	 * löscht es.
	 */
	public function save(
		bool $replyEnabled = false,
		string $replyAddress = '',
		string $imapHost = '',
		int $imapPort = 993,
		string $imapSecurity = 'ssl',
		string $imapUser = '',
		string $imapFolder = 'INBOX',
		string $imapPassword = '',
		bool $imapPasswordClear = false,
	): JSONResponse {
		$this->settings->save([
			'replyEnabled' => $replyEnabled,
			'replyAddress' => $replyAddress,
			'imapHost' => $imapHost,
			'imapPort' => $imapPort,
			'imapSecurity' => $imapSecurity,
			'imapUser' => $imapUser,
			'imapFolder' => $imapFolder,
			'imapPassword' => $imapPassword,
			'imapPasswordClear' => $imapPasswordClear,
		]);

		return new JSONResponse($this->settings->getPublicConfig());
	}

	/**
	 * Die Verbindung testen (analog RechnungsWerks SMTP-Test): verbinden,
	 * anmelden, Ordner wählen. Erfolg → `{ok: true}`; jeder Fehlschlag → 400 mit
	 * der Fehlermeldung, die die UI direkt anzeigt.
	 *
	 * Leeres `imapPassword` → das gespeicherte wird verwendet.
	 */
	public function test(
		string $imapHost = '',
		int $imapPort = 993,
		string $imapSecurity = 'ssl',
		string $imapUser = '',
		string $imapFolder = 'INBOX',
		string $imapPassword = '',
	): JSONResponse {
		if (trim($imapHost) === '') {
			return new JSONResponse(['error' => 'Kein IMAP-Server angegeben.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->settings->testConnection([
				'imapHost' => $imapHost,
				'imapPort' => $imapPort,
				'imapSecurity' => $imapSecurity,
				'imapUser' => $imapUser,
				'imapFolder' => $imapFolder,
				'imapPassword' => $imapPassword,
			]);

			return new JSONResponse(['ok' => true]);
		} catch (ImapException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			// Alles andere (z. B. eine kaputte Konfiguration) ebenso als Meldung,
			// nicht als 500 — der Admin soll den Grund lesen, nicht raten.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
