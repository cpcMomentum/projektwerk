<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCP\IUserManager;

/**
 * Unterscheidet **Gast-Konten** von vollwertigen Konten — auf Ebene des
 * Nextcloud-User-Backends, nicht der projektbezogenen Rolle (#280).
 *
 * **Warum das Backend und nicht die App-Rolle.** „intern/extern" der App steht
 * pro Projekt an der Mitgliedschaft und sagt nichts über ein Konto ohne Projekt.
 * Ob jemand ein Gast ist, ist dagegen eine globale Konto-Eigenschaft: Die
 * Guests-App stellt ihre Gäste über ein eigenes User-Backend bereit, dessen
 * Name `getBackendClassName()` als {@see self::GUEST_BACKEND} zurückgibt. Ein
 * vollwertiges Konto trägt `Database`, `LDAP`, o. ä.
 *
 * **Keine App-Abhängigkeit, keine Gruppen-API.** Gelesen wird allein über
 * Core-OCP (`IUserManager`); der Guests-App-Bezug ist eine weiche Kopplung über
 * den Backend-Namen. (Der Name der Gruppen-Verwaltungs-Schnittstelle steht hier
 * bewusst nicht ausgeschrieben — der Architekturtest sucht ihn als Text.) Ist
 * die Guests-App nicht installiert, gibt es kein solches
 * Backend — dann ist niemand Gast und {@see isGuest()} ist stets `false` (der
 * Aufrufer verhält sich damit unverändert).
 */
class AccountType {

	/**
	 * Der Backend-Name der Guests-App.
	 *
	 * Festes Literal aus `guests/lib/UserBackend.php::getBackendName()`
	 * (`return 'Guests';`) — **nicht** lokalisiert (das `t('Guests')` der
	 * Guests-App betrifft nur das Label ihrer Einstellungs-Sektion). Ein
	 * Integrations-/Live-Check nagelt diesen Wert fest; änderte ein künftiges
	 * Guests-Major ihn, fiele die Erkennung sichtbar aus statt still.
	 */
	public const GUEST_BACKEND = 'Guests';

	public function __construct(
		private IUserManager $users,
	) {
	}

	/**
	 * Ob das Konto ein Nextcloud-Gast ist (aus dem Guests-Backend).
	 *
	 * @param string|null $userId Die Kennung, oder `null` (keine Sitzung) — dann
	 *                            `false`: ohne Sitzung fällt der Aufrufer ohnehin
	 *                            schon am eigenen Null-Check durch.
	 */
	public function isGuest(?string $userId): bool {
		if ($userId === null) {
			return false;
		}

		$user = $this->users->get($userId);

		return $user !== null && $user->getBackendClassName() === self::GUEST_BACKEND;
	}
}
