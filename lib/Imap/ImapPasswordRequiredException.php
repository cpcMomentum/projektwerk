<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Imap;

/**
 * Das gespeicherte Passwort gehört zu **einem** Zugang: Server, Port,
 * Verschlüsselung und Konto. Soll es an einen anderen Zugang gehen, braucht es
 * eine neue Eingabe — sonst ließe es sich an einen beliebigen Server schicken.
 */
class ImapPasswordRequiredException extends ImapException {
}
