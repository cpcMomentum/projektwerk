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
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getEnabled()
 * @method void setEnabled(int $enabled)
 */
class NotifyPref extends Entity {

	/** Nextclouds Glocke. */
	public const CHANNEL_BELL = 'bell';

	/** E-Mail. Für Gäste der einzige Kanal, der ankommt. */
	public const CHANNEL_MAIL = 'mail';

	/** Mir wurde ein Vorgang zugewiesen. */
	public const EVENT_TICKET_ASSIGNED = 'ticket_assigned';

	/** Mir wurde ein Arbeitsschritt zugewiesen. */
	public const EVENT_STEP_ASSIGNED = 'step_assigned';

	/** Ein neuer Vorgang im Projekt — der Rundruf. */
	public const EVENT_TICKET_CREATED = 'ticket_created';

	/**
	 * Die beiden Kanäle: **nur global**.
	 *
	 * „Wie werde ich benachrichtigt" ist keine Frage, die man je Projekt anders
	 * beantwortet (Entscheidung mit Axel, 2026-08-11). Wer keine Mails will,
	 * will sie nirgends.
	 *
	 * @var string[]
	 */
	public const CHANNELS = [self::CHANNEL_MAIL, self::CHANNEL_BELL];

	/**
	 * Die drei Anlässe: **je Projekt**, mit globaler Vorgabe.
	 *
	 * „Wovon werde ich benachrichtigt" ist sehr wohl je Projekt verschieden —
	 * und der dritte ist der, der bei vielen Projekten laut wird: Ein Rundruf an
	 * alle, die den Vorgang sehen dürfen.
	 *
	 * @var string[]
	 */
	public const EVENTS = [
		self::EVENT_TICKET_ASSIGNED,
		self::EVENT_STEP_ASSIGNED,
		self::EVENT_TICKET_CREATED,
	];

	protected ?string $userId = null;
	protected ?string $channel = null;
	protected ?int $boardId = null;
	protected ?int $enabled = null;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('channel', Types::STRING);
		$this->addType('boardId', Types::INTEGER);
		// `SMALLINT` mit 0/1, nie `BOOLEAN` — `PARAM_BOOL` schriebe auf
		// PostgreSQL `'f'` statt `0` (siehe `nextcloud-fallstricke.md`).
		$this->addType('enabled', Types::INTEGER);
	}
}
