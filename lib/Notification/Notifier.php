<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Notification;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Die Glocke — **aufgelöst erst beim Anzeigen, nie beim Auslösen.**
 *
 * Das ist die tragende Entscheidung aus §3.11, und sie ist keine Optimierung:
 * Gespeichert wird ausschließlich die `ticketId`. Titel, Kontext und
 * Sichtbarkeit werden hier nachgeschlagen, in dem Moment, in dem jemand die
 * Glocke aufklappt.
 *
 * **Was das leistet:** Ist der Vorgang für die empfangende Person inzwischen
 * nicht mehr sichtbar — heruntergestuft, geschlossen, Mitgliedschaft beendet —,
 * wirft `prepare()` eine {@see AlreadyProcessedException}, und Nextcloud räumt
 * den Eintrag ab. Damit wird das aktive Zurückziehen aus §5.23 **kosmetisch
 * statt tragend**: Selbst wenn das Aufräumen beim Herunterstufen fehlschlüge,
 * könnte über die Glocke nichts lecken.
 *
 * Der umgekehrte Weg — Titel beim Auslösen in die Benachrichtigung schreiben —
 * wäre eine Kopie des Vorgangs an einem Ort, den die Sichtbarkeitsregel nicht
 * erreicht. Genau die Sorte zweiter Ort, gegen die die ganze Bauform gerichtet
 * ist.
 */
class Notifier implements INotifier {

	/** Ein Vorgang wurde dieser Person zugewiesen. */
	public const SUBJECT_TICKET_ASSIGNED = 'ticket_assigned';

	/** Ein Arbeitsschritt wurde dieser Person zugewiesen. */
	public const SUBJECT_STEP_ASSIGNED = 'step_assigned';

	/** Ein neuer Vorgang im Projekt. */
	public const SUBJECT_TICKET_CREATED = 'ticket_created';

	/** Ein Kommentar an einem Vorgang, an dem der Empfaenger beteiligt ist. */
	public const SUBJECT_COMMENT_ADDED = 'comment_added';

	/** Ein Vorgang wurde geschlossen. */
	public const SUBJECT_TICKET_CLOSED = 'ticket_closed';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
		private TicketMapper $tickets,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		// Ohne Anmeldekontext aufgerufen — deshalb die allgemeine Sprache der
		// Instanz und nicht die einer Person.
		return $this->l10nFactory->get(Application::APP_ID)->t('ProjektWerk');
	}

	/**
	 * @throws UnknownNotificationException fremde App
	 * @throws AlreadyProcessedException    der Vorgang ist für diese Person nicht (mehr) sichtbar
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			// **Nicht `InvalidArgumentException`.** Seit NC 30 ist das der
			// vorgesehene Weg zu sagen „nicht meine Benachrichtigung"; die alte
			// Ausnahme wird ab NC 39 als Fehler protokolliert.
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$ticket = $this->sichtbaresTicket($notification);

		$nummer = str_pad((string)$ticket->getNumber(), 4, '0', STR_PAD_LEFT);
		$titel = (string)$ticket->getTitle();

		$satz = match ($notification->getSubject()) {
			self::SUBJECT_TICKET_ASSIGNED => $l->t('Ihnen wurde ein Vorgang zugewiesen: #%1$s %2$s', [$nummer, $titel]),
			self::SUBJECT_STEP_ASSIGNED => $l->t('Ihnen wurde ein Arbeitsschritt zugewiesen: #%1$s %2$s', [$nummer, $titel]),
			self::SUBJECT_TICKET_CREATED => $l->t('Neuer Vorgang im Projekt: #%1$s %2$s', [$nummer, $titel]),
			self::SUBJECT_COMMENT_ADDED => $l->t('Neuer Kommentar: #%1$s %2$s', [$nummer, $titel]),
			self::SUBJECT_TICKET_CLOSED => $l->t('Vorgang geschlossen: #%1$s %2$s', [$nummer, $titel]),
			// Ein Betreff, den diese Fassung nicht kennt — etwa nach einem
			// Downgrade. Ihn stehen zu lassen waere eine leere Zeile in der
			// Glocke; abzuraeumen ist ehrlicher.
			default => throw new AlreadyProcessedException(),
		};

		// **Der geparste Betreff wird IMMER gesetzt**, sonst wirft der Manager
		// `IncompleteParsedNotificationException`.
		$notification->setParsedSubject($satz);
		$notification->setLink($this->linkZu((int)$ticket->getId()));
		$notification->setIcon($this->urls->getAbsoluteURL(
			$this->urls->imagePath(Application::APP_ID, 'app-dark.svg'),
		));

		return $notification;
	}

	/**
	 * Den Vorgang laden — **durch dieselbe Sichtbarkeitsregel wie jede andere
	 * Abfrage**.
	 *
	 * `findVisibleAnywhere()` ist derselbe Lesepfad, den auch der Deep-Link
	 * nimmt — hier wird keine zweite Regel formuliert, sondern die eine
	 * benutzt. Was dort nicht auftaucht, gibt es für diese Person nicht, und
	 * eine Benachrichtigung darüber hat keinen Bestand.
	 *
	 * @throws AlreadyProcessedException
	 */
	private function sichtbaresTicket(INotification $notification): Ticket {
		$ticketId = (int)$notification->getObjectId();

		try {
			return $this->tickets->findVisibleAnywhere($notification->getUser(), $ticketId);
		} catch (DoesNotExistException) {
			// Nicht mehr sichtbar: heruntergestuft, geschlossen, Mitgliedschaft
			// beendet. Nextcloud raeumt den Eintrag daraufhin ab.
			throw new AlreadyProcessedException();
		}
	}

	/**
	 * Der fragmentfreie Deep-Link — mit Pruefung.
	 *
	 * `Router::generate()` faengt einen unbekannten Routennamen, loggt auf
	 * `info` und gibt **einen leeren String** zurueck (S4, 2026-08-11). Aus
	 * `linkToRouteAbsolute()` wird dann die blanke Basisadresse. Ein Link, der
	 * auf der Startseite landet, ist schlechter als keiner — deshalb hier die
	 * Gegenprobe statt blinden Vertrauens.
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
