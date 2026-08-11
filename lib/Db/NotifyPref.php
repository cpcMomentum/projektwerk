<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Der Kanalschalter einer Person — Glocke oder Mail, an oder aus.
 *
 * **Persönlich, nicht pro Board** (§3.11): Der Schalter steht in den
 * persönlichen App-Einstellungen. Wer keine Mails will, will sie in keinem
 * Projekt — eine Einstellung je Board wäre eine Liste, die mit jedem neuen
 * Projekt länger wird und die niemand pflegt.
 *
 * **Keine Zeile heisst „an".** Die Vorgabe steht in
 * {@see NotifyPrefMapper::isEnabled()} und nicht in der Datenbank: Ein neues
 * Mitglied bekommt Benachrichtigungen, ohne dass jemand eine Zeile für es
 * anlegen müsste. Erst wer abschaltet, erzeugt einen Eintrag.
 *
 * Das ist zugleich der Unterschied zu `skipped_no_address` im
 * {@see MailOutbox}: Eine fehlende Zeile hier bedeutet „eingeschaltet", eine
 * fehlende Outbox-Zeile bedeutet „abgeschaltet, es wurde nichts geschrieben".
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method int getEnabled()
 * @method void setEnabled(int $enabled)
 */
class NotifyPref extends Entity {

	/** Nextclouds Glocke. */
	public const CHANNEL_BELL = 'bell';

	/** E-Mail. Für Gäste der einzige Kanal, der ankommt. */
	public const CHANNEL_MAIL = 'mail';

	protected ?string $userId = null;
	protected ?string $channel = null;
	protected ?int $enabled = null;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('channel', Types::STRING);
		// `SMALLINT` mit 0/1, nie `BOOLEAN` — `PARAM_BOOL` schriebe auf
		// PostgreSQL `'f'` statt `0` (siehe `nextcloud-fallstricke.md`).
		$this->addType('enabled', Types::INTEGER);
	}
}
