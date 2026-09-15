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
use OCP\Util;
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
		private ReplyMailboxSettings $replyMailbox,
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
	 * @param string|null $actorUid Wer den Anlass ausgelöst hat (für „von wem").
	 * @param string|null $stepTitle Titel des zugewiesenen Schritts, eingefroren.
	 */
	public function queue(string $recipientUid, int $ticketId, string $event, int $boardId, ?string $actorUid = null, ?string $stepTitle = null): ?MailOutbox {
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
			$unterdrueckt->setActorUid($actorUid);
			$unterdrueckt->setStepTitle($stepTitle);
			// Jede neue Zeile trägt einen Token (#285) — auch die unterdrückte,
			// damit „jede Outbox-Zeile hat einen Token" ohne Ausnahme gilt.
			$unterdrueckt->setReplyToken(self::neuerReplyToken());
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
		$zeile->setActorUid($actorUid);
		$zeile->setStepTitle($stepTitle);
		// **Der Antwort-Token entsteht hier, beim Vormerken** (#285) — nicht beim
		// Senden. Der Nachversand ({@see \OCA\Projektwerk\BackgroundJob\MailRetryJob})
		// fasst dieselbe Zeile wieder an und darf keinen zweiten Token vergeben,
		// sonst zeigte eine nachgereichte Mail einen anderen Anker als die erste.
		$zeile->setReplyToken(self::neuerReplyToken());

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
	 * @param string $meta Kontextzeile über dem Text (Projekt · Vorgang); leer heißt: keine.
	 * @param string|null $projekt Projektname für Absendername und Betreff-Präfix; null heißt: keiner auflösbar.
	 */
	public function flush(MailOutbox $zeile, string $betreff, string $einleitung, string $link = '', string $meta = '', ?string $projekt = null): MailOutbox {
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

		// **Der Betreff bekommt das Projekt vorangestellt** (#284): `[{Projekt}]`
		// macht den Posteingang scannbar — welches Projekt, bevor man die Mail
		// öffnet. Angesetzt wird der Präfix hier, nicht in der `betreff()`-Matrix
		// des Composers: so bleiben die l10n-Strings unangetastet, und ohne
		// auflösbaren Projektnamen fällt der Präfix ersatzlos weg. Die H1 im
		// Rumpf bleibt ohne Präfix — die Metazeile darunter nennt das Projekt
		// ohnehin (Betreff = Scannen, Meta = Lesen).
		$betreffMitProjekt = self::betreffMitProjekt($betreff, $projekt);

		// **Antworten per E-Mail** (#287): Ist ein Antwort-Postfach eingerichtet,
		// reist der Token dieser Zeile im Betreff mit — `[PW-{token}]`. Eine
		// Antwort des Kunden trägt ihn (die meisten Clients zitieren den Betreff)
		// und der Einlese-Job findet darüber den Vorgang zurück. Ohne
		// eingerichtetes Postfach bleibt alles wie bisher.
		$antwortAktiv = $this->replyMailbox->isEnabled();
		if ($antwortAktiv) {
			$betreffMitProjekt = self::betreffMitToken($betreffMitProjekt, $zeile->getReplyToken());
		}

		// **NC-gestyltes HTML statt nacktem Text** (#189): dieselbe Optik wie
		// jede andere Nextcloud-Mail, mit Überschrift, Satz und — sofern ein
		// Link vorliegt — einem „Zum Vorgang"-Knopf. Das Template rendert Text
		// **und** HTML; ein Client ohne HTML bekommt weiter eine lesbare Mail.
		$template = $this->mailer->createEMailTemplate('projektwerk.notification');
		$template->setSubject($betreffMitProjekt);
		$template->addHeading($betreff);
		// Die Kontextzeile (Projekt · Vorgang) steht über dem Satz — wo einer da
		// ist. Sie ordnet die Mail ein, bevor man den Satz liest.
		if ($meta !== '') {
			$template->addBodyText($meta);
		}
		$template->addBodyText($einleitung);
		if ($link !== '') {
			$l = $this->l10nFactory->get(Application::APP_ID, (string)$zeile->getLang());
			$template->addBodyButton($l->t('Zum Vorgang'), $link);
		}

		$nachricht = $this->mailer->createMessage();
		// **Gleiche Adresse, besserer Name** (#284). Ohne eigenes `setFrom` käme
		// die Mail als nackte Instanz-Adresse mit dem Instanznamen an. Die
		// **Adresse** bleibt exakt die, die der Mailer ohnehin als Absender
		// nutzt — `Util::getDefaultEmailAddress('no-reply')` ist genau der Wert,
		// den Nextclouds Mailer beim Versand einsetzt, wenn kein Absender gesetzt
		// ist (aus `mail_from_address`+`mail_domain`, verifiziert gegen NC 34).
		// Eine andere Adresse bräche SPF/DKIM. Verändert wird nur der
		// **Anzeigename**: „ProjektWerk – {Projekt}", ohne auflösbaren
		// Projektnamen nur „ProjektWerk".
		$nachricht->setFrom([Util::getDefaultEmailAddress('no-reply') => self::absenderName($projekt)]);
		// **Reply-To nur mit Antwort-Postfach** (#287) — kein `noreply@`-Theater,
		// wenn ohnehin niemand die Antworten liest. Die Adresse gehört dem
		// Betreiber (z. B. projekte@firma.de) und ist die, die der Einlese-Job
		// abfragt.
		if ($antwortAktiv) {
			$antwortAdresse = $this->replyMailbox->getReplyAddress();
			if ($antwortAdresse !== '') {
				$nachricht->setReplyTo([$antwortAdresse]);
			}
		}
		// **Der Anzeigename ist der Name der Person, nicht ihre Kennung** (#189).
		// Gastkonten tragen als Kennung einen Hash; stünde der als Anzeigename in
		// der An-Zeile, läse die Mail sich für den Empfänger wie Spam.
		$nachricht->setTo($this->empfaenger($adresse, (string)$zeile->getRecipientUid()));
		$nachricht->setSubject($betreffMitProjekt);
		$nachricht->useTemplate($template);

		// **Keine eigene Message-ID, `sent_message_id` bleibt leer** (#285,
		// verifiziert gegen NC 34). Die Idee war, `<pw-{reply_token}@domain>` als
		// Message-ID zu setzen, damit eine Antwort über `In-Reply-To` zugeordnet
		// werden kann. Das ginge nur über die darunterliegende Symfony-Mail —
		// und die öffentliche `OCP\Mail\IMessage` gibt darauf keinen Zugriff
		// (kein `getSymfonyEmail()` im Interface). In die konkrete Implementierung
		// zu greifen wäre ein Bruch der OCP-only-Regel dieser Flotte. Also der in
		// der Anweisung vorgesehene Fallback: Das Matching läuft allein über den
		// Betreff-Token `[PW-{reply_token}]` (Serie #287); der Token steckt schon
		// in der Zeile. `sent_message_id` bleibt Vorrat für eine NC-Version, die
		// den Zugriff über OCP freigibt.

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

	/**
	 * Der Absender-Anzeigename (#284): „ProjektWerk – {Projekt}", oder — ohne
	 * auflösbaren Projektnamen — nur „ProjektWerk".
	 *
	 * **Rein und statisch**, damit die eine Entscheidung, um die es geht (mit
	 * oder ohne Projekt), ohne Mailer und ohne Server prüfbar ist. Die Adresse
	 * bleibt außen vor — sie ist die des Mailers und darf sich nicht ändern.
	 *
	 * Ein Projektname, der ausschließlich aus Steuerzeichen besteht, bleibt nach
	 * {@see einzeilig()} leer — Board-Titel werden nur mit `trim()` auf
	 * Nicht-Leerheit geprüft, und das erfasst nicht jedes Steuerzeichen. Ein
	 * solcher Rest zählt wie „kein Projekt", statt „ProjektWerk – " mit leerem
	 * Namensteil zu erzeugen.
	 *
	 * @param string|null $projekt Projektname, oder null.
	 */
	private static function absenderName(?string $projekt): string {
		$sauber = $projekt !== null ? self::einzeilig($projekt) : '';

		return $sauber !== '' ? 'ProjektWerk – ' . $sauber : 'ProjektWerk';
	}

	/**
	 * Der Betreff mit vorangestelltem `[{Projekt}]` (#284), oder unverändert,
	 * wenn kein Projektname vorliegt.
	 *
	 * Ebenfalls rein und statisch: der Präfix ist eine Textentscheidung, keine
	 * Transportsache, und wird hier — eine Ebene über der `betreff()`-Matrix des
	 * Composers — angesetzt, ohne einen einzigen l10n-String anzufassen.
	 *
	 * @param string $betreff Der fertige Betreff aus dem Composer.
	 * @param string|null $projekt Projektname, oder null.
	 */
	private static function betreffMitProjekt(string $betreff, ?string $projekt): string {
		$sauber = $projekt !== null ? self::einzeilig($projekt) : '';

		return $sauber !== '' ? '[' . $sauber . '] ' . $betreff : $betreff;
	}

	/**
	 * Der Projektname, tauglich für eine Kopfzeile (#284, Review-Hinweis PR #291).
	 *
	 * Projektname und Betreff-Präfix landen im Absender-Anzeigenamen und im
	 * Betreff — beides sind Mail-Kopfzeilen. Ein Zeilenumbruch darin wäre eine
	 * Header-Injection; Nextclouds Mailer würde eine solche Kopfzeile zwar
	 * abweisen (und der Versand fiele über {@see flush()} sauber auf `failed`),
	 * aber das ist Verlass auf eine fremde Schutzschicht. Billiger und
	 * eindeutiger: Steuerzeichen (CR, LF, Tab, NUL …) hier zu einem Leerzeichen
	 * glätten und Randleerraum kappen. Board-Titel werden sonst nur auf
	 * Nicht-Leerheit geprüft, nicht auf Kontrollzeichen.
	 *
	 * @param string $projekt Der rohe Projektname.
	 */
	private static function einzeilig(string $projekt): string {
		return trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $projekt));
	}

	/**
	 * Der Betreff mit angehängtem Antwort-Token `[PW-{token}]` (#287), oder
	 * unverändert, wenn kein Token vorliegt.
	 *
	 * **Am Ende**, nicht am Anfang: Das `[{Projekt}]` vorn ist zum Scannen da,
	 * der Token ist Maschinerie und gehört ans hintere Ende, wo er beim Lesen
	 * nicht stört. Rein und statisch, damit das Format (`[PW-…]`, an dem der
	 * Einlese-Job matcht) eine Maschine hütet.
	 *
	 * @param string $betreff Der bereits mit Projekt versehene Betreff.
	 * @param string|null $token Der Antwort-Token der Zeile, oder null.
	 */
	private static function betreffMitToken(string $betreff, ?string $token): string {
		return ($token !== null && $token !== '') ? $betreff . ' [PW-' . $token . ']' : $betreff;
	}

	/**
	 * Ein neuer Antwort-Token (#285): 16 Zufallsbytes, hex — 32 Zeichen.
	 *
	 * `random_bytes()` ist kryptografisch, der Token ist eine Fähigkeit (wer ihn
	 * kennt, kann eine Antwort einem Vorgang zuordnen) — CSPRNG, nicht `uniqid`.
	 */
	private static function neuerReplyToken(): string {
		return bin2hex(random_bytes(16));
	}
}
