<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Service\AccountType;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

class PageController extends Controller {

	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private AccountType $accountType,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		// Ob die Person Projekte anlegen darf (#280): Gäste dürfen nicht. Das
		// Signal blendet den „Neues Projekt"-Knopf aus; die eigentliche Sperre
		// sitzt serverseitig in BoardService::create(). Als Initial-State, damit
		// der Knopf ohne zweite Runde entscheidet.
		$this->initialState->provideInitialState('canCreateProject', !$this->accountType->isGuest($this->userId));

		// Gäste/Kunden bekommen keine Produktmeldungen (#329): blendet den
		// „Neuerungen"-Menüeintrag aus. Der Riegel selbst sitzt im WhatsNewService
		// (der `all`-Endpunkt liefert Gästen ohnehin nichts).
		$this->initialState->provideInitialState('isGuest', $this->accountType->isGuest($this->userId));

		return new TemplateResponse(Application::APP_ID, 'index');
	}
}
