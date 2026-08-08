<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\AppInfo;

use OCA\Projektwerk\SetupCheck\GuestsWhitelistCheck;
use OCA\Projektwerk\SetupCheck\InstanceConfigCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'projektwerk';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Die Instanz meldet ihre eigenen Fehlkonfigurationen. Beide Checks
		// lesen und melden nur — GuestsWhitelistCheck schreibt ausdruecklich
		// nicht (Entscheidung E5), weil ein blindes Setzen der Freigabeliste
		// deren eingebaute Vorgabe ersetzen wuerde.
		$context->registerSetupCheck(InstanceConfigCheck::class);
		$context->registerSetupCheck(GuestsWhitelistCheck::class);

		// Hier kommt spaeter u.a. der Listener auf UserDeletedEvent hin:
		// Beim Loeschen eines Kontos muessen dessen private Tickets entfernt
		// und offene Zuweisungen aufgehoben werden — mangels Admin-Ausnahme
		// liessen sie sich sonst nie wieder aufraeumen.
	}

	public function boot(IBootContext $context): void {
	}
}
