<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;
use OCA\Projektwerk\Db\StepMapper;
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
 * **Was NICHT auslöst:** Verschieben und das Abhaken einzelner Schritte. Das
 * ist eine Produktentscheidung und keine Auslassung — ein Board, das bei jeder
 * Bewegung mailt, wird nach zwei Tagen stummgeschaltet, und danach kommt auch
 * das an, was gezählt hätte.
 *
 * **Kommentare lösen seit #98 aus**, und das Schliessen ebenfalls. §21 hatte
 * beides ausgeschlossen; die Folge im Einsatz war, dass einen nach dem Rundruf
 * beim Anlegen nichts mehr erreichte, ausser man bekam etwas zugewiesen — die
 * Kundenseite schrieb, und niemand erfuhr es.
 */
class NotificationService {

	public function __construct(
		private MailDispatcher $mail,
		private NotifyPrefMapper $prefs,
		private IManager $notifications,
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
		// **Hinten angehaengt, nicht eingeschoben.** Die Reihenfolge der
		// Konstruktorargumente ist hier Teil der Schnittstelle: Tests bauen den
		// Dienst von Hand. Ein Einschub in der Mitte laesst sie mit einer
		// Typfehlermeldung auflaufen, die auf die falsche Stelle zeigt.
		private StepMapper $steps,
		private CommentMapper $comments,
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

		// **Wovon** — je Projekt, mit globaler Vorgabe. Der Anlass entscheidet
		// zuerst; wer diesen Anlass in diesem Projekt nicht will, bekommt auf
		// keinem Kanal etwas.
		if (!$this->prefs->isEnabled($recipientUid, $event, (int)$ticket->getBoardId())) {
			return [];
		}

		$this->bell($ticket, $recipientUid, $event);

		$zeile = $this->mail->queue($recipientUid, (int)$ticket->getId(), $event, (int)$ticket->getBoardId());

		return $zeile === null ? [] : [$zeile];
	}

	/**
	 * Einen Anlass an **alle Beteiligten** ankündigen.
	 *
	 * **Das Abo entsteht aus dem Handeln, nicht aus einer Liste** (#98).
	 * Beteiligt ist, wer den Vorgang angelegt hat, wer für ihn verantwortlich
	 * ist, wer einen Arbeitsschritt daran hat oder wer kommentiert hat. Vier
	 * Quellen, eine Menge — kein Schreibpfad, keine Tabelle, nichts, was
	 * veralten kann. Wer nachträglich einen Schritt bekommt, ist ab dann dabei;
	 * wer nie etwas tut, ist nie dabei.
	 *
	 * Eine Beteiligtenliste zum Pflegen wurde ausdrücklich verworfen: Sie
	 * funktioniert erst, wenn jemand sie gefüllt hat, und veraltet danach.
	 *
	 * **Bei den Arbeitsschritten zählt jeder je zugewiesene, auch der
	 * abgehakte.** Wer beim Erledigen aus dem Abo fiele, verpasste die Antwort
	 * auf den eigenen Kommentar — der falsche Moment zum Stummschalten.
	 *
	 * Die Kinder kommen über `findForTickets([...])` und damit über den einzigen
	 * Lesepfad, den {@see \OCA\Projektwerk\Db\TicketChildMapper} anbietet. Eine
	 * Methode „die Schritte zu Vorgang 42" gibt es dort bewusst nicht, und dieser
	 * Dienst ist kein Grund, sie einzuführen: Der Vorgang, dessen Kennung hier
	 * hineingeht, wurde vom Aufrufer bereits über den gefilterten Weg geladen.
	 *
	 * Jeder einzelne Empfänger läuft danach durch {@see announce()} — dort
	 * sitzen die Sichtbarkeitsregel, der Ausschluss der auslösenden Person und
	 * der Schalter je Projekt. Diese Methode entscheidet nichts davon neu.
	 *
	 * @param Ticket $ticket Der Vorgang, um den es geht.
	 * @param string $actorUid Wer die Handlung ausgelöst hat.
	 * @param string $event Einer der `EVENT_*`-Werte aus {@see MailOutbox}.
	 * @return MailOutbox[] Was nach dem Commit zu senden ist.
	 */
	public function announceToInvolved(Ticket $ticket, string $actorUid, string $event): array {
		$ticketId = (int)$ticket->getId();

		$beteiligte = [
			(string)$ticket->getCreatorUserId(),
			(string)($ticket->getResponsibleUserId() ?? ''),
		];

		foreach ($this->steps->findForTickets([$ticketId]) as $schritt) {
			$beteiligte[] = (string)($schritt->getAssignedUserId() ?? '');
		}

		foreach ($this->comments->findForTickets([$ticketId]) as $kommentar) {
			$beteiligte[] = (string)$kommentar->getAuthorUserId();
		}

		$vorgemerkt = [];
		foreach (array_unique(array_filter($beteiligte)) as $uid) {
			$vorgemerkt = [...$vorgemerkt, ...$this->announce($ticket, $uid, $actorUid, $event)];
		}

		return $vorgemerkt;
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
				MailOutbox::EVENT_COMMENT_ADDED => $l->t('Neuer Kommentar zu Vorgang #%1$s', [$nummer]),
				MailOutbox::EVENT_TICKET_CLOSED => $l->t('Vorgang #%1$s wurde geschlossen', [$nummer]),
				default => $l->t('Neuer Vorgang #%1$s', [$nummer]),
			};

			// **Ein ganzer Satz mit Kontext** (#189) statt „Betreff: Titel".
			// Der Titel steht in Anführungszeichen, damit klar ist, wo der
			// Vorgangstitel anfängt und aufhört.
			$titel = (string)$ticket->getTitle();
			$einleitung = match ((string)$zeile->getEvent()) {
				MailOutbox::EVENT_TICKET_ASSIGNED => $l->t('Ihnen wurde der Vorgang #%1$s „%2$s“ zugewiesen.', [$nummer, $titel]),
				MailOutbox::EVENT_STEP_ASSIGNED => $l->t('Ihnen wurde ein Arbeitsschritt im Vorgang #%1$s „%2$s“ zugewiesen.', [$nummer, $titel]),
				MailOutbox::EVENT_COMMENT_ADDED => $l->t('Es gibt einen neuen Kommentar zum Vorgang #%1$s „%2$s“.', [$nummer, $titel]),
				MailOutbox::EVENT_TICKET_CLOSED => $l->t('Der Vorgang #%1$s „%2$s“ wurde geschlossen.', [$nummer, $titel]),
				default => $l->t('Im Projekt ist der neue Vorgang #%1$s „%2$s“ entstanden.', [$nummer, $titel]),
			};

			$this->mail->flush($zeile, $betreff, $einleitung, $this->linkZu((int)$ticket->getId()));
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
			MailOutbox::EVENT_COMMENT_ADDED => Notifier::SUBJECT_COMMENT_ADDED,
			MailOutbox::EVENT_TICKET_CLOSED => Notifier::SUBJECT_TICKET_CLOSED,
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
