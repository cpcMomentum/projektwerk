<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\AppInfo;

use OCA\Projektwerk\Listener\UserDeletedListener;
use OCA\Projektwerk\Notification\Notifier;
use OCA\Projektwerk\SetupCheck\GuestsWhitelistCheck;
use OCA\Projektwerk\SetupCheck\InstanceConfigCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

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

		// Die Glocke. Der Notifier loest **erst beim Anzeigen** auf — gespeichert
		// wird nur die Ticketkennung. Ist der Vorgang fuer die empfangende
		// Person inzwischen nicht mehr sichtbar, raeumt Nextcloud den Eintrag
		// daraufhin ab (§5.23).
		$context->registerNotifierService(Notifier::class);

		// **Der Nachlauf steht in der `info.xml`**, nicht hier.
		//
		// Hier stand bis zum 2026-08-12 ein `registerBackgroundJob()` — eine
		// Methode, die `IRegistrationContext` gar nicht kennt. Das war kein
		// stiller Ausfall des Auftrags: Nextcloud faengt den Fehler in
		// `Coordinator::register()` ab und bricht damit die **gesamte**
		// Registrierung dieser App ab. Jeder Aufruf endete danach in 503, bei
		// jeder Ansicht, fuer jede Person.
		//
		// Warum es trotzdem monatelang unentdeckt bleiben konnte: Der Fehler
		// steht nur im `nextcloud.log`, die Oberflaeche zeigt eine leere Seite.

		// **Beim Loeschen eines Kontos raeumt die App hinterher** (§29). Ohne
		// das blieben unsichtbare private Vorgaenge und ein ewiges „wartet auf
		// Kunde" stehen — mangels Admin-Ausnahme fuer immer.
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
