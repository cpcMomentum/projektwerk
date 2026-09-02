<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\BackgroundJob;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\MailOutboxMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\MailComposer;
use OCA\Projektwerk\Service\MailDispatcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Der Nachlauf — **genau ein** zeitkritischer Job, und der hier.
 *
 * **Warum es ihn gibt und warum er nicht der Hauptweg ist.** Im Cron-Modus
 * `ajax` führt Nextcloud pro Aufruf genau einen Job aus; bei zwanzig Jobs einer
 * Instanz läuft ein 15-Minuten-Job dann alle paar Stunden. Ein als Job gebauter
 * Mailversand wäre bei einem SMTP-Fehler faktisch verloren. Deshalb sendet die
 * App synchron nach dem Commit — und dieser Job holt nur nach, was dabei
 * misslungen ist.
 *
 * Er nimmt sich `pending` und `failed` vor, und beides nur, solange
 * {@see MailOutboxMapper::MAX_ATTEMPTS} nicht erreicht ist. `skipped_no_address`
 * fasst er nicht an: Ein erneuter Versuch änderte nichts an einer fehlenden
 * Adresse.
 *
 * **Der Vorgang wird beim Senden neu gelesen, nicht mitgeschleppt.** Titel und
 * Nummer stammen aus dem aktuellen Stand — dieselbe Entscheidung wie beim
 * Notifier. Existiert der Vorgang nicht mehr, wird die Zeile still abgeräumt:
 * Eine Mail über einen gelöschten Vorgang zu verschicken wäre schlimmer als
 * keine.
 */
class MailRetryJob extends TimedJob {

	/**
	 * Alle 900 Sekunden — die Zahl aus dem Plan (§3.11).
	 *
	 * Kurz genug, dass eine Störung von Minuten nicht zu Stunden wird, und lang
	 * genug, dass ein dauerhaft toter Mailserver nicht jeden Cron-Durchlauf
	 * belegt.
	 */
	private const INTERVALL_SEKUNDEN = 900;

	public function __construct(
		ITimeFactory $time,
		private MailOutboxMapper $outbox,
		private TicketMapper $tickets,
		private MailDispatcher $mail,
		private LoggerInterface $logger,
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
		private MailComposer $composer,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVALL_SEKUNDEN);
		// Auch bei knapper Systemlast ausfuehren: Eine liegengebliebene
		// Kundenmail ist kein Luxus.
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	/**
	 * @param mixed $argument Wird nicht verwendet.
	 */
	protected function run($argument): void {
		foreach ($this->outbox->findRetryable() as $zeile) {
			try {
				$this->nachreichen($zeile);
			} catch (\Throwable $e) {
				// Eine Zeile darf den Lauf nicht beenden — sonst blockiert ein
				// einzelner kaputter Datensatz alle uebrigen, und zwar dauerhaft.
				$this->logger->warning(
					'ProjektWerk: Nachlauf an Zeile ' . (int)$zeile->getId() . ' gescheitert',
					['exception' => $e],
				);
			}
		}
	}

	/**
	 * @param MailOutbox $zeile Die vorgemerkte Mail.
	 */
	private function nachreichen(MailOutbox $zeile): void {
		$ticketId = $zeile->getTicketId();

		if ($ticketId === null) {
			$this->outbox->delete($zeile);

			return;
		}

		// **Gelesen wird durch die Sichtbarkeitsregel — aus Sicht des
		// EMPFAENGERS.** Der Job hat keinen eigenen Betrachter, aber die Zeile
		// nennt einen: die Person, die die Mail bekommen soll.
		//
		// Das ist mehr als Formsache. Zwischen Vormerken und Nachlauf koennen
		// Minuten liegen, in denen der Vorgang heruntergestuft wurde oder die
		// Mitgliedschaft endete. Faellt er dabei aus der sichtbaren Menge, wird
		// **nicht** nachgereicht — eine Mail laesst sich nicht zurueckholen.
		//
		// Der bequeme Weg waere eine ungefilterte Lesemethode am TicketMapper
		// gewesen. Genau die verbietet `testEveryTicketReadStartsWithAContext`,
		// und genau so eine waere der zweite Lesepfad, gegen den die ganze
		// Bauform gerichtet ist.
		try {
			$ticket = $this->tickets->findVisibleAnywhere((string)$zeile->getRecipientUid(), $ticketId);
		} catch (\Throwable) {
			$this->outbox->delete($zeile);

			return;
		}

		// **Derselbe Text wie beim Erstversand** (#248, Teil 2): über den
		// gemeinsamen Composer, samt Metazeile und „Zum Vorgang"-Knopf. Bisher
		// ging die nachgereichte Mail ohne Knopf und mit dem dürftigen
		// „Vorgang #N: Titel" raus — schlechter als die beim ersten Versuch.
		$l = $this->l10nFactory->get(Application::APP_ID, (string)$zeile->getLang());
		$text = $this->composer->compose($zeile, $ticket, $l);

		$this->mail->flush(
			$zeile,
			$text['betreff'],
			$text['einleitung'],
			$this->linkZu((int)$ticket->getId()),
			$text['meta'],
		);
	}

	/**
	 * Fragmentfreier Deep-Link zum Vorgang — dieselbe Gegenprobe wie im
	 * {@see \OCA\Projektwerk\Service\NotificationService}: bleibt ein Fragment im
	 * Link, überlebt es den Login-Umweg nicht.
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
