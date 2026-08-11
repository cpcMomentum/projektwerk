<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Notification\Notifier;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Wer wovon erfährt — und wer ausdrücklich nicht.
 *
 * Diese Klasse ist die **einzige** Stelle, an der eine Benachrichtigung
 * entsteht. Sie führt Glocke und Mail zusammen, hält beide auseinander (jede
 * hat ihren eigenen Schalter) und trägt die drei Regeln, die §5.21 aufstellt:
 *
 * 1. **Zu `private`-Vorgängen wird nichts versendet.** Nicht „an niemanden
 *    ausser dem Ersteller", sondern gar nichts — es gibt niemanden, der sie
 *    sehen darf ausser ihm, und er löst sie selbst aus.
 * 2. **Niemand wird über die eigene Handlung benachrichtigt.** Wer sich selbst
 *    einen Vorgang zuweist, braucht keine Mail darüber.
 * 3. **Jede Benachrichtigung durchläuft dieselbe Sichtbarkeitsprüfung wie eine
 *    Ticket-Abfrage.** Bei der Glocke geschieht das erst beim Anzeigen
 *    ({@see Notifier}); bei der Mail hier, beim Auslösen.
 *
 * **Was NICHT auslöst** (§ Anlässe in #10): Kommentare, Verschieben und
 * Erledigen. Das ist eine Produktentscheidung und keine Auslassung — ein Board,
 * das bei jeder Bewegung mailt, wird nach zwei Tagen stummgeschaltet, und
 * danach kommt auch das an, was gezählt hätte.
 */
class NotificationService {

	public function __construct(
		private MailDispatcher $mail,
		private NotifyPrefMapper $prefs,
		private IManager $notifications,
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Eine Zuweisung ankündigen — Glocke sofort, Mail vorgemerkt.
	 *
	 * **Gibt die vorgemerkten Zeilen zurück, statt selbst zu senden.** Der
	 * Aufrufer steckt in einer Transaktion; gesendet wird erst danach, über
	 * {@see deliver()}. Diese Trennung ist der Grund, warum ein toter
	 * Mailserver das Speichern nicht mitreisst.
	 *
	 * @param Ticket $ticket Der Vorgang, um den es geht.
	 * @param string $recipientUid Wer benachrichtigt wird.
	 * @param string $actorUid Wer die Handlung ausgelöst hat.
	 * @param string $event Einer der `EVENT_*`-Werte aus {@see MailOutbox}.
	 * @return MailOutbox[] Was nach dem Commit zu senden ist.
	 */
	public function announce(Ticket $ticket, string $recipientUid, string $actorUid, string $event): array {
		if (!$this->darfBenachrichtigtWerden($ticket, $recipientUid, $actorUid)) {
			return [];
		}

		$this->bell($ticket, $recipientUid, $event);

		$zeile = $this->mail->queue($recipientUid, (int)$ticket->getId(), $event);

		return $zeile === null ? [] : [$zeile];
	}

	/**
	 * Die vorgemerkten Mails senden — **nach** dem Commit.
	 *
	 * @param MailOutbox[] $zeilen Was {@see announce()} zurückgegeben hat.
	 * @param Ticket $ticket Für Betreff und Text.
	 */
	public function deliver(array $zeilen, Ticket $ticket): void {
		foreach ($zeilen as $zeile) {
			$l = $this->l10nFactory->get(Application::APP_ID, (string)$zeile->getLang());
			$nummer = str_pad((string)$ticket->getNumber(), 4, '0', STR_PAD_LEFT);

			$betreff = match ((string)$zeile->getEvent()) {
				MailOutbox::EVENT_TICKET_ASSIGNED => $l->t('Vorgang #%1$s wurde Ihnen zugewiesen', [$nummer]),
				MailOutbox::EVENT_STEP_ASSIGNED => $l->t('Arbeitsschritt in Vorgang #%1$s wurde Ihnen zugewiesen', [$nummer]),
				default => $l->t('Neuer Vorgang #%1$s', [$nummer]),
			};

			$text = implode("\n\n", [
				$betreff . ': ' . (string)$ticket->getTitle(),
				$l->t('Zum Vorgang: %s', [$this->linkZu((int)$ticket->getId())]),
			]);

			$this->mail->flush($zeile, $betreff, $text);
		}
	}

	/**
	 * Die drei Regeln aus §5.21, an einer Stelle.
	 *
	 * @param Ticket $ticket Der Vorgang.
	 * @param string $recipientUid Wer benachrichtigt würde.
	 * @param string $actorUid Wer ausgelöst hat.
	 */
	private function darfBenachrichtigtWerden(Ticket $ticket, string $recipientUid, string $actorUid): bool {
		// (1) **Zu einem privaten Vorgang geht nichts raus.** Er ist für genau
		// eine Person sichtbar, und die ist die auslösende. Eine
		// Benachrichtigung hätte keinen gültigen Empfänger — und eine an den
		// Ersteller wäre eine über seine eigene Handlung.
		if ((string)$ticket->getVisibility() === TicketScope::VISIBILITY_PRIVATE) {
			return false;
		}

		// (2) Nicht über die eigene Handlung.
		if ($recipientUid === $actorUid) {
			return false;
		}

		return $recipientUid !== '';
	}

	/**
	 * Der Glockeneintrag — **nur die Kennung, kein Text**.
	 *
	 * Aufgelöst wird erst beim Anzeigen (siehe {@see Notifier}). Ein
	 * fehlgeschlagener Eintrag darf den Vorgang nicht mitreissen: Die Glocke ist
	 * die Zugabe, die Mail der Weg, auf den sich ein Gast verlässt.
	 *
	 * @param Ticket $ticket Der Vorgang.
	 * @param string $recipientUid Wer den Eintrag bekommt.
	 * @param string $event Einer der `EVENT_*`-Werte.
	 */
	private function bell(Ticket $ticket, string $recipientUid, string $event): void {
		if (!$this->prefs->isEnabled($recipientUid, NotifyPref::CHANNEL_BELL)) {
			return;
		}

		try {
			$eintrag = $this->notifications->createNotification();
			$eintrag->setApp(Application::APP_ID)
				->setUser($recipientUid)
				->setDateTime(new \DateTime())
				->setObject('ticket', (string)$ticket->getId())
				->setSubject($this->betreffFuer($event));

			$this->notifications->notify($eintrag);
		} catch (\Throwable $e) {
			$this->logger->warning('ProjektWerk: Glockeneintrag fehlgeschlagen', ['exception' => $e]);
		}
	}

	/**
	 * @param string $event Einer der `EVENT_*`-Werte aus {@see MailOutbox}.
	 */
	private function betreffFuer(string $event): string {
		return match ($event) {
			MailOutbox::EVENT_TICKET_ASSIGNED => Notifier::SUBJECT_TICKET_ASSIGNED,
			MailOutbox::EVENT_STEP_ASSIGNED => Notifier::SUBJECT_STEP_ASSIGNED,
			default => Notifier::SUBJECT_TICKET_CREATED,
		};
	}

	/**
	 * Fragmentfreier Deep-Link, mit derselben Gegenprobe wie im {@see Notifier}.
	 *
	 * @param int $ticketId Kennung des Vorgangs.
	 */
	private function linkZu(int $ticketId): string {
		$link = $this->urls->linkToRouteAbsolute(
			Application::APP_ID . '.deepLink.ticket',
			['ticketId' => $ticketId],
		);

		return str_contains($link, '/t/' . $ticketId)
			? $link
			: $this->urls->getAbsoluteURL('/index.php/apps/' . Application::APP_ID . '/t/' . $ticketId);
	}
}
