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
 * Eine ausgehende Mail — als **Zeile**, nicht als Auftrag in einer Warteschlange.
 *
 * **Warum es diese Tabelle gibt.** §5.24 verlangt einen Fehlerstatus in der
 * Datenbank, und zwar aus einem gemessenen Grund: `IMailer::send()` wirft bei
 * einem Transportfehler **nichts**. Es fängt selbst, loggt, und gibt die
 * fehlgeschlagenen Empfänger zurück (S4, 2026-08-11). Wer nur sendet und nicht
 * schreibt, verliert den Fehlschlag in dem Moment, in dem die HTTP-Antwort
 * rausgeht.
 *
 * **Die Zeile entsteht in derselben Transaktion wie der Vorgang**, der sie
 * auslöst, mit `status = pending`. Der Sendeversuch kommt erst **nach** dem
 * Commit. Damit gilt: Ein toter Mailserver kann das Speichern eines Tickets
 * nicht mitreißen — die Zusage aus dem Akzeptanzkriterium von #10.
 *
 * **Die drei Zustände, die auseinandergehalten werden müssen** (§5.24):
 *
 * | Zustand | Bedeutung |
 * |---|---|
 * | keine Zeile | Der Kanal ist abgeschaltet — es sollte nichts raus |
 * | `skipped_no_address` | Es sollte raus, aber die Person hat keine Adresse |
 * | `failed` | Es sollte raus, es ging nicht, der `MailRetryJob` versucht es erneut |
 *
 * Die ersten beiden sehen im Postfach gleich aus (nichts kommt an) und sind
 * völlig verschiedene Probleme. Genau deshalb steht die Unterscheidung im
 * Datenmodell und nicht im Log.
 *
 * **Gespeichert wird die `ticketId`, nicht der Text.** Betreff und Inhalt
 * entstehen beim Senden aus dem aktuellen Stand — wie beim `Notifier`, der
 * ebenfalls erst zur Anzeigezeit auflöst (§3.11). Ein vorgefertigter Text in
 * der Datenbank wäre eine zweite Kopie des Vorgangs, die veraltet und die
 * niemand gegen die Sichtbarkeit prüft.
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

	/** Geschrieben, noch nicht versucht. */
	public const STATUS_PENDING = 'pending';

	/** Zugestellt — `sentAt` trägt den Zeitpunkt. */
	public const STATUS_SENT = 'sent';

	/** Versucht und misslungen. Der `MailRetryJob` nimmt sich das vor. */
	public const STATUS_FAILED = 'failed';

	/**
	 * Die Person hat keine E-Mail-Adresse.
	 *
	 * **Kein Fehler und kein Wiederholungsfall.** Ein erneuter Versuch änderte
	 * nichts; was fehlt, ist eine Adresse, und die trägt ein Mensch nach. Der
	 * Zustand steht hier, damit man ihn abfragen kann — „warum bekommt der
	 * Kunde nichts" ist sonst eine Suche im Log.
	 */
	public const STATUS_SKIPPED_NO_ADDRESS = 'skipped_no_address';

	/** Zuweisung eines Vorgangs. */
	public const EVENT_TICKET_ASSIGNED = 'ticket_assigned';

	/** Zuweisung eines Arbeitsschritts. */
	public const EVENT_STEP_ASSIGNED = 'step_assigned';

	/** Ein neuer Vorgang im Projekt. */
	public const EVENT_TICKET_CREATED = 'ticket_created';

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
		// `SMALLINT` in der Migration, hier `INTEGER`: Der Entity-Typ steuert
		// die PHP-Umwandlung, nicht die Spaltenbreite.
		$this->addType('attempts', Types::INTEGER);
		$this->addType('lastError', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('sentAt', Types::DATETIME);
	}
}
