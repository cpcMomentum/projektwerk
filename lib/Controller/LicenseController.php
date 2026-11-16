<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\License\LicenseService;
use OCA\Projektwerk\License\WerkPlusApiException;
use OCA\Projektwerk\License\WerkPlusClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/** Admin-only, weil keine Methode #[NoAdminRequired] trägt. */
class LicenseController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly LicenseService $license,
		private readonly IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function show(): DataResponse {
		return new DataResponse($this->status());
	}

	public function activate(#[\SensitiveParameter] string $key = ''): DataResponse {
		try {
			$this->license->activate($key);
		} catch (\InvalidArgumentException $e) {
			return $this->invalidInput($e->getMessage());
		} catch (WerkPlusApiException $e) {
			return $this->apiError($e);
		}
		return new DataResponse($this->status());
	}

	// Der Schluessel steht nur in dieser Antwort; spaeter liefert der Status nur noch den Hinweis.
	public function trial(): DataResponse {
		try {
			$key = $this->license->startTrial();
		} catch (\InvalidArgumentException $e) {
			return $this->invalidInput($e->getMessage());
		} catch (WerkPlusApiException $e) {
			return $this->apiError($e);
		}
		return new DataResponse(['key' => $key, 'status' => $this->status()]);
	}

	public function refresh(): DataResponse {
		try {
			$this->license->refresh();
		} catch (\InvalidArgumentException $e) {
			return $this->invalidInput($e->getMessage());
		} catch (WerkPlusApiException $e) {
			return $this->apiError($e);
		}
		return new DataResponse($this->status());
	}

	public function destroy(): DataResponse {
		try {
			$this->license->removeKey();
		} catch (\InvalidArgumentException $e) {
			return $this->invalidInput($e->getMessage());
		}
		return new DataResponse($this->status());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$status = $this->license->getStatus();
		$lastError = $status['lastError'];
		$status['lastError'] = $lastError === null ? null : [
			'code' => $lastError['code'],
			'message' => $this->messageFor($lastError['code'], $lastError['serverMessage']),
			'at' => $lastError['at'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $lastError['at']) : null,
		];
		return $status;
	}

	private function apiError(WerkPlusApiException $e): DataResponse {
		return new DataResponse([
			'error' => $this->messageFor($e->getErrorCode(), $e->getServerMessage()),
			'code' => $e->getErrorCode(),
			'status' => $this->status(),
		], Http::STATUS_BAD_REQUEST);
	}

	private function invalidInput(string $field): DataResponse {
		$message = match ($field) {
			'keySet' => $this->l10n->t('Es ist bereits ein WerkPlus-Schlüssel hinterlegt. Ein Testzeitraum ist nur ohne Schlüssel möglich.'),
			'storeOutdated' => $this->l10n->t('Eine neuere Werk-App hat die Lizenzablage umgestellt. Bitte aktualisieren Sie ProjektWerk, bevor Sie die Lizenz ändern.'),
			default => $this->l10n->t('Bitte geben Sie einen gültigen WerkPlus-Schlüssel ein.'),
		};
		return new DataResponse(['error' => $message, 'code' => 'INPUT_INVALID'], Http::STATUS_BAD_REQUEST);
	}

	// Verzweigt wird ueber den Code; die Servermeldung erscheint nur, wenn der Code unbekannt ist.
	private function messageFor(string $code, string $serverMessage): string {
		return match ($code) {
			'REQUEST_INVALID' => $this->l10n->t('Die Anfrage an WerkPlus war fehlerhaft. Bitte melden Sie den Fehler dem Anbieter.'),
			'KEY_UNKNOWN' => $this->l10n->t('Dieser Schlüssel ist nicht bekannt. Bitte prüfen Sie die Schreibweise.'),
			'KEY_REVOKED' => $this->l10n->t('Dieser Schlüssel wurde gesperrt. ProjektWerk arbeitet im freien Umfang weiter.'),
			'KEY_EXPIRED' => $this->l10n->t('Die Lizenz ist abgelaufen. ProjektWerk arbeitet im freien Umfang weiter.'),
			'INSTANCE_LIMIT_REACHED' => $this->l10n->t('Diese Lizenz ist einer anderen Nextcloud zugeordnet. Umzug im Kundenportal.'),
			'TRIAL_ALREADY_USED' => $this->l10n->t('Für diese Nextcloud wurde bereits ein Testzeitraum genutzt.'),
			'TRIAL_UNAVAILABLE' => $this->l10n->t('Der Testzeitraum lässt sich gerade nicht in der App starten. Unter werkwolke.de/testen steht, wie Sie ihn trotzdem bekommen.'),
			'RATE_LIMITED' => $this->l10n->t('Zu viele Versuche. Bitte versuchen Sie es später erneut.'),
			WerkPlusClient::UNREACHABLE => $this->l10n->t('WerkPlus ist gerade nicht erreichbar. Bitte versuchen Sie es später erneut.'),
			LicenseService::TOKEN_REJECTED => $this->l10n->t('Die Antwort von WerkPlus ließ sich nicht prüfen. Die Lizenz wurde nicht übernommen.'),
			'SERVER_ERROR', WerkPlusClient::INVALID_RESPONSE => $this->l10n->t('WerkPlus hat einen Fehler gemeldet. Bitte versuchen Sie es später erneut.'),
			default => $serverMessage !== '' ? $serverMessage : $this->l10n->t('WerkPlus hat einen Fehler gemeldet. Bitte versuchen Sie es später erneut.'),
		};
	}
}
