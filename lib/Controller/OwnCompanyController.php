<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Service\OwnCompanySettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Die eigene Firma der Instanz speichern (#352).
 *
 * **Admin-only ohne eigene Prüfung**, wie `ReplyMailboxController`: Keine Methode
 * trägt `#[NoAdminRequired]`, also sperrt Nextcloud alle anderen aus. Gelesen wird
 * der Wert nicht hier, sondern als Initial-State im `PageController` — den braucht
 * jede Person, die ein Projekt anlegt.
 */
class OwnCompanyController extends Controller {

	public function __construct(
		IRequest $request,
		private OwnCompanySettings $settings,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function save(?string $ownCompany = null): JSONResponse {
		try {
			return new JSONResponse(['ownCompany' => $this->settings->set($ownCompany)]);
		} catch (\InvalidArgumentException) {
			return new JSONResponse(
				['error' => $this->l10n->t('Der Firmenname darf höchstens %s Zeichen lang sein.', [OwnCompanySettings::MAX_LENGTH])],
				Http::STATUS_BAD_REQUEST,
			);
		}
	}
}
