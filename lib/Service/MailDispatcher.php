<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\MailOutboxMapper;
use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Der Versandweg: erst schreiben, dann senden, dann den Ausgang fortschreiben.
 *
 * **Die Reihenfolge ist die ganze Bauform** (§3.11, §5.24):
 *
 * 1. {@see queue()} schreibt die Zeile — **innerhalb** der Transaktion des
 *    Vorgangs, der sie auslöst. Rollt der Vorgang zurück, verschwindet auch die
 *    Ankündigung.
 * 2. {@see flush()} versucht den Versand — **nach** dem Commit. Deshalb kann ein
 *    toter Mailserver das Speichern eines Tickets nicht mitreißen; das ist
 *    wörtlich ein Akzeptanzkriterium aus #10.
 *
 * **Der Rückgabewert ist das Fehlersignal, nicht eine Ausnahme.** In S4 am
 * 2026-08-11 gemessen: `IMailer::send()` fängt `TransportExceptionInterface`
 * selbst, loggt, und gibt die fehlgeschlagenen Empfänger zurück. Ein
 * `try/catch` allein hielte jeden Fehlschlag für einen Erfolg — der teuerste
 * denkbare Irrtum an dieser Stelle, weil niemand ihn je bemerkt.
 *
 * **Was diese Klasse NICHT entscheidet:** wer etwas bekommt. Die Empfänger
 * stehen fest, bevor sie gerufen wird — sie kommen aus der gefilterten
 * Ticketmenge. Diese Klasse fragt nur noch „will diese Person Mails, und hat
 * sie eine Adresse". Eine Sichtbarkeitsprüfung hier wäre die zweite, und die
 * zweite ist die, die irgendwann nicht mehr stimmt.
 */
class MailDispatcher {

	/**
	 * Zeitgrenze für den synchronen Versuch, in Sekunden — als **Hinweis**, wo
	 * die Zahl herkommt.
	 *
	 * Gesetzt wird sie nicht hier, sondern als Instanz-Einstellung
	 * (`mail_smtptimeout`): Sie gehört dem Betreiber, nicht der App. Die Zahl
	 * stammt aus S4 — ein SMTP-Port, dessen Pakete verworfen werden, kostet ohne
	 * eigene Angabe **10 Sekunden**, und so lange darf das Anlegen eines Tickets
	 * nicht dauern. Mit 2 Sekunden bleibt es unter der Schwelle, ab der jemand
	 * nachdrückt; was nicht durchgeht, holt der Nachlauf.
	 */
	public const EMPFOHLENE_ZEITGRENZE_SEKUNDEN = 2;

	/**
	 * Wie lange nach einer Kommentar-Mail keine zweite zum selben Vorgang geht.
	 *
	 * **Eine Konstante, keine Einstellung** (#98). Eine Schraube, an der
	 * niemand dreht, ist eine Einstellung zu viel — und wer es wirklich leiser
	 * will, schaltet den Anlass fuer dieses Projekt ab. Genau dafuer gibt es
	 * die zwei Achsen.
	 *
	 * 30 Minuten faengt den lebhaften Abgleich; laenger verzoegerte die
	 * Nachricht ueber einen wirklich neuen Punkt entsprechend lange.
	 */
	private const FENSTER_MINUTEN = 30;

	/**
	 * Anlaesse, die im Fenster unterdrueckt werden.
	 *
	 * **Zuweisungen nie.** Sie sind die Nachrichten, die zaehlen, und sie
	 * kommen einzeln — sich davon abzumelden, dass einem Arbeit zugewiesen
	 * wird, waere kein Komfort, sondern ein Loch.
	 *
	 * @var string[]
	 */
	private const GEDROSSELT = [MailOutbox::EVENT_COMMENT_ADDED];

	public function __construct(
		private MailOutboxMapper $outbox,
		private NotifyPrefMapper $prefs,
		private IMailer $mailer,
		private IUserManager $users,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Eine Ankündigung vormerken — **innerhalb** der laufenden Transaktion.
	 *
	 * Gibt `null` zurück, wenn der Kanal abgeschaltet ist. Das ist kein Fehler,
	 * sondern der dritte Zustand aus §5.24: **keine Zeile** heißt „es sollte
	 * nichts raus" und ist von `skipped_no_address` zu unterscheiden, das
	 * heißt „es sollte, ging aber nicht".
	 *
	 * @param string $recipientUid Wer benachrichtigt wird.
	 * @param int $ticketId Der Vorgang — aufgelöst wird er erst beim Senden.
	 * @param string $event Einer der `EVENT_*`-Werte aus {@see MailOutbox}.
	 * @param int $boardId Das Projekt — der Schalter kann projektweise stehen.
	 */
	public function queue(string $recipientUid, int $ticketId, string $event, int $boardId): ?MailOutbox {
		// **Ohne Projekt.** Der Kanal ist global — „wie werde ich benachrichtigt"
		// beantwortet niemand je Projekt anders. Ob dieser Anlass in diesem
		// Projekt ueberhaupt zaehlt, hat der NotificationService schon
		// entschieden, bevor er hierher kommt.
		if (!$this->prefs->isEnabled($recipientUid, NotifyPref::CHANNEL_MAIL)) {
			return null;
		}

		// **Bündeln durch Unterdruecken, nicht durch Aufschieben.** Die erste
		// Mail geht sofort raus, mit Inhalt und Direktlink; jede weitere zum
		// selben Vorgang an dieselbe Person bleibt im Fenster aus. Die Glocke
		// laeuft weiter, die Person hat ihren Link, und im Vorgang steht
		// ohnehin alles.
		//
		// Die Zeile wird **nicht** weggelassen, sondern als `suppressed`
		// festgehalten: „unterdrueckt" ist etwas anderes als „abgeschaltet"
		// (keine Zeile) und als „keine Adresse" — und der Unterschied gehoert
		// im Ausgangskorb ablesbar, sonst sucht man ihn spaeter im Log.
		if (in_array($event, self::GEDROSSELT, true) && $this->imFenster($recipientUid, $ticketId, $event)) {
			$unterdrueckt = new MailOutbox();
			$unterdrueckt->setRecipientUid($recipientUid);
			$unterdrueckt->setTicketId($ticketId);
			$unterdrueckt->setEvent($event);
			$unterdrueckt->setLang($this->spracheVon($recipientUid));
			$unterdrueckt->setStatus(MailOutbox::STATUS_SUPPRESSED);
			$unterdrueckt->setAttempts(0);
			$unterdrueckt->setCreatedAt(new \DateTime());
			$this->outbox->insert($unterdrueckt);

			return null;
		}

		$zeile = new MailOutbox();
		$zeile->setRecipientUid($recipientUid);
		$zeile->setTicketId($ticketId);
		$zeile->setEvent($event);
		// **Die Sprache des Empfängers, nicht die des Auslösers** (§3.11). Wer
		// ein Ticket auf Deutsch anlegt, schickt einem englischsprachigen Kunden
		// trotzdem Englisch. Festgehalten wird sie hier, weil der Nachlauf
		// später keinen Anmeldekontext mehr hat.
		$zeile->setLang($this->spracheVon($recipientUid));
		$zeile->setStatus(MailOutbox::STATUS_PENDING);
		$zeile->setAttempts(0);
		$zeile->setCreatedAt(new \DateTime());

		return $this->outbox->insert($zeile);
	}

	/**
	 * Einen vorgemerkten Versand versuchen — **nach** dem Commit.
	 *
	 * Schreibt das Ergebnis in dieselbe Zeile zurück und gibt sie aktualisiert
	 * heraus. Wirft nichts: Ein gescheiterter Versand ist ein Zustand, kein
	 * Programmfehler, und er darf den Aufrufer nicht mitreißen — der hat seinen
	 * Vorgang längst gespeichert.
	 *
	 * @param MailOutbox $zeile Was {@see queue()} vorgemerkt hat.
	 * @param string $betreff Fertiger Betreff in der Sprache der Zeile.
	 * @param string $einleitung Fertiger Einleitungssatz für den Rumpf.
	 * @param string $link Deep-Link zum Vorgang; leer heißt: kein „Zum Vorgang"-Knopf.
	 */
	public function flush(MailOutbox $zeile, string $betreff, string $einleitung, string $link = ''): MailOutbox {
		$adresse = $this->adresseVon((string)$zeile->getRecipientUid());

		if ($adresse === null) {
			// **Kein Fehler und kein Wiederholungsfall.** Ein erneuter Versuch
			// änderte nichts; was fehlt, ist eine Adresse, und die trägt ein
			// Mensch nach. Der Zustand steht in der Datenbank, damit „warum
			// bekommt der Kunde nichts" eine Abfrage ist und keine Logsuche.
			$zeile->setStatus(MailOutbox::STATUS_SKIPPED_NO_ADDRESS);

			return $this->outbox->update($zeile);
		}

		$zeile->setAttempts((int)$zeile->getAttempts() + 1);

		// **NC-gestyltes HTML statt nacktem Text** (#189): dieselbe Optik wie
		// jede andere Nextcloud-Mail, mit Überschrift, Satz und — sofern ein
		// Link vorliegt — einem „Zum Vorgang"-Knopf. Das Template rendert Text
		// **und** HTML; ein Client ohne HTML bekommt weiter eine lesbare Mail.
		$template = $this->mailer->createEMailTemplate('projektwerk.notification');
		$template->setSubject($betreff);
		$template->addHeading($betreff);
		$template->addBodyText($einleitung);
		if ($link !== '') {
			$l = $this->l10nFactory->get(Application::APP_ID, (string)$zeile->getLang());
			$template->addBodyButton($l->t('Zum Vorgang'), $link);
		}

		$nachricht = $this->mailer->createMessage();
		// **Der Anzeigename ist der Name der Person, nicht ihre Kennung** (#189).
		// Gastkonten tragen als Kennung einen Hash; stünde der als Anzeigename in
		// der An-Zeile, läse die Mail sich für den Empfänger wie Spam.
		$nachricht->setTo($this->empfaenger($adresse, (string)$zeile->getRecipientUid()));
		$nachricht->setSubject($betreff);
		$nachricht->useTemplate($template);

		try {
			// **Hier steht die Auswertung, um die es geht.** `send()` wirft bei
			// einem Transportfehler nichts — es gibt die fehlgeschlagenen
			// Empfänger zurück (S4). Leer heißt zugestellt.
			$gescheitert = $this->mailer->send($nachricht);
		} catch (\Throwable $e) {
			// Bleibt trotzdem stehen: Ein ungültiger Absender oder eine kaputte
			// Konfiguration wirft sehr wohl, nur eben nicht der Transport.
			$gescheitert = [(string)$zeile->getRecipientUid()];
			$this->logger->warning('ProjektWerk: Mailversand mit Ausnahme abgebrochen', ['exception' => $e]);
		}

		if ($gescheitert === []) {
			$zeile->setStatus(MailOutbox::STATUS_SENT);
			$zeile->setSentAt(new \DateTime());
			$zeile->setLastError(null);
		} else {
			$zeile->setStatus(MailOutbox::STATUS_FAILED);
			$zeile->setLastError('Zustellung fehlgeschlagen an: ' . implode(', ', $gescheitert));
		}

		return $this->outbox->update($zeile);
	}

	/**
	 * Die E-Mail-Adresse einer Person, oder `null`.
	 *
	 * Ein leerer String zählt als „keine" — Nextcloud liefert bei einem Konto
	 * ohne gepflegte Adresse je nach Backend das eine oder das andere, und
	 * beide bedeuten dasselbe.
	 *
	 * @param string $userId Kennung der Person.
	 */
	private function adresseVon(string $userId): ?string {
		$adresse = $this->users->get($userId)?->getEMailAddress();

		return $adresse === null || trim($adresse) === '' ? null : $adresse;
	}

	/**
	 * Der `setTo`-Wert: Adresse mit Anzeigenamen, oder — wenn es keinen
	 * brauchbaren gibt — nur die Adresse.
	 *
	 * @param string $adresse Die E-Mail-Adresse.
	 * @param string $userId Kennung der Person.
	 *
	 * @return array<string, string>|string[]
	 */
	private function empfaenger(string $adresse, string $userId): array {
		$name = $this->nameVon($userId);

		return $name === null ? [$adresse] : [$adresse => $name];
	}

	/**
	 * Der Anzeigename einer Person, oder `null`.
	 *
	 * **Ein Hash ist kein Name** (#189): Gastkonten tragen als Kennung eine
	 * lange Zeichenkette, und manche Backends geben genau die als Anzeigenamen
	 * zurück. Ist der Name leer oder identisch mit der Kennung, gilt „keiner" —
	 * dann steht in der An-Zeile nur die Adresse statt eines kryptischen Hashes.
	 *
	 * @param string $userId Kennung der Person.
	 */
	private function nameVon(string $userId): ?string {
		$name = $this->users->get($userId)?->getDisplayName();
		if ($name === null) {
			return null;
		}

		$name = trim($name);

		return ($name === '' || $name === $userId) ? null : $name;
	}

	/**
	 * Die Sprache, in der diese Person angeschrieben wird.
	 *
	 * Gibt es das Konto nicht (mehr), fällt die Wahl auf die allgemeine Sprache
	 * der Instanz — `findGenericLanguage()` liefert notfalls `en`. Der Fall ist
	 * selten und harmlos: Er kann nur eintreten, wenn zwischen Vormerken und
	 * Senden ein Konto verschwindet, und dann ist die Sprache das kleinste
	 * Problem.
	 *
	 * @param string $userId Kennung der Person.
	 */
	private function spracheVon(string $userId): string {
		$user = $this->users->get($userId);

		return $user === null
			? $this->l10nFactory->findGenericLanguage(Application::APP_ID)
			: $this->l10nFactory->getUserLanguage($user);
	}

	/**
	 * Ging in den letzten {@see FENSTER_MINUTEN} Minuten schon eine solche Mail raus?
	 *
	 * @param string $recipientUid Wer benachrichtigt wuerde.
	 * @param int $ticketId Der Vorgang.
	 * @param string $event Einer der `EVENT_*`-Werte.
	 */
	private function imFenster(string $recipientUid, int $ticketId, string $event): bool {
		$seit = (new \DateTime())->modify('-' . self::FENSTER_MINUTEN . ' minutes');

		return $this->outbox->existsSince($recipientUid, $ticketId, $event, $seit);
	}
}
