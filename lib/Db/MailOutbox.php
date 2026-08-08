<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Ausgangsfach-Eintrag fuer den Mailversand.
 *
 * Der Grund, warum es diese Tabelle ueberhaupt gibt, steht in S4 (07.08.2026):
 * `IMailer::send()` wirft eine `TransportException`, und wer nicht faengt,
 * merkt nichts — `occ user:welcome` liefert bei totem SMTP Exitcode 0 ohne
 * Ausgabe und ohne Mail. Ohne einen Ort fuer Status und Fehlertext ist die
 * Zusage „ein haengender Mailserver kippt das Speichern nie" nicht einloesbar.
 *
 * `STATUS_SKIPPED_NO_ADDRESS` ist kein Fehler, sondern der Wert, der §5.24
 * woertlich nimmt: „fehlt eine Adresse, wird der Kanal protokolliert
 * uebersprungen — **unterscheidbar von ‚abgeschaltet'**".
 *
 * Absichtlich **ohne** JsonSerializable: Der Fehlertext eines Mailservers geht
 * an kein Frontend.
 *
 * Der Mapper folgt in Phase 6 zusammen mit `MailRetryJob`.
 *
 * @method string getRecipientUid()
 * @method void setRecipientUid(string $recipientUid)
 * @method ?int getTicketId()
 * @method void setTicketId(?int $ticketId)
 * @method string getEvent()
 * @method void setEvent(string $event)
 * @method ?string getLang()
 * @method void setLang(?string $lang)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method ?string getLastError()
 * @method void setLastError(?string $lastError)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method ?DateTime getSentAt()
 * @method void setSentAt(?DateTime $sentAt)
 */
class MailOutbox extends Entity {

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENT = 'sent';
	public const STATUS_FAILED = 'failed';
	public const STATUS_SKIPPED_NO_ADDRESS = 'skipped_no_address';

	protected ?string $recipientUid = null;
	protected ?int $ticketId = null;
	protected ?string $event = null;
	protected ?string $lang = null;
	protected ?string $status = null;
	protected ?int $attempts = null;
	protected ?string $lastError = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $sentAt = null;

	public function __construct() {
		$this->addType('recipientUid', Types::STRING);
		$this->addType('ticketId', Types::INTEGER);
		$this->addType('event', Types::STRING);
		$this->addType('lang', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('attempts', Types::SMALLINT);
		$this->addType('lastError', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('sentAt', Types::DATETIME);
	}
}
