<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\BackgroundJob;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Db\MailOutboxMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Imap\ImapClient;
use OCA\Projektwerk\Imap\MimeMessage;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\ReplyMailboxSettings;
use OCA\Projektwerk\Service\ReplyTextExtractor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Antworten per E-Mail einlesen und als Kommentar am Vorgang eintragen (#287).
 *
 * Der Deep-Link „Zum Vorgang" bleibt der Primärweg; die Mail-Antwort ist der
 * Bequemweg. Läuft nur, wenn ein Antwort-Postfach eingerichtet ist
 * ({@see ReplyMailboxSettings::isEnabled()}) — sonst gibt es nichts zu lesen.
 *
 * **Die Reihenfolge der Prüfungen ist die Sicherheit** und steht bewusst so:
 *
 * 1. **Automaten-/Schleifenschutz zuerst.** Abwesenheitsnotizen, Listen-Mails,
 *    die eigene Adresse — verworfen, bevor irgendetwas zugeordnet wird. Nie
 *    automatisch antworten (Bounce-Schleifen).
 * 2. **Anker** über den Betreff-Token `[PW-{token}]` auf die Ausgangszeile.
 *    (Der Message-ID-Weg über `sent_message_id` ist Vorrat und derzeit unbesetzt
 *    — siehe {@see \OCA\Projektwerk\Service\MailDispatcher::flush()}.)
 * 3. **Absender-Gegenprüfung, zweifach:** Die From-Adresse muss genau der
 *    hinterlegten Adresse des in der Zeile genannten Kontos entsprechen **und**
 *    das Konto muss den Vorgang zum Verarbeitungszeitpunkt noch sehen (Mitglied
 *    des Boards). Der Token ist die Fähigkeit, die Adresse die Gegenkontrolle —
 *    From allein ist fälschbar, Token allein reist bei Weiterleitung mit.
 * 4. **Text** ohne Zitat und Signatur, gedeckelt.
 * 5. **Kommentar** über {@see CommentService::create()} mit dem `ViewerContext`
 *    des Kontos aus {@see BoardAccess::contextFor()} — kein Bypass; die
 *    Benachrichtigung an die Gegenseite läuft dadurch den normalen Weg, samt
 *    30-Minuten-Drossel.
 *
 * **Verworfene und unzuordenbare Mails werden gelesen markiert, nicht
 * gelöscht** — sie bleiben zur Nachschau im Postfach, der Grund steht im Log.
 * Ein Verschieben in Unterordner (`ProjektWerk/unmatched`) setzte deren Anlage
 * voraus und machte den Job an einer Nebensache zerbrechlich; das ist eine
 * spätere Ausbaustufe.
 */
class ReplyIntakeJob extends TimedJob {

	/** Alle fünf Minuten (§Antwort-Postfach). */
	private const INTERVALL_SEKUNDEN = 300;

	/** Wie viele ungelesene Mails ein Lauf höchstens anfasst. */
	private const BATCH = 30;

	/**
	 * Höchstzahl Kommentare je Absender **und Lauf** — der einfache Zähler gegen
	 * Amok-Postfächer aus der Anweisung. Bewusst pro Lauf statt pro Stunde: ein
	 * persistenter Stundenzähler wäre ein zweiter Zustand; der Lauf-Deckel
	 * bremst den Ausbruch mit einem Bruchteil des Aufwands.
	 */
	private const MAX_JE_ABSENDER = 20;

	public function __construct(
		ITimeFactory $time,
		private ReplyMailboxSettings $settings,
		private MailOutboxMapper $outbox,
		private TicketMapper $tickets,
		private BoardAccess $access,
		private CommentService $comments,
		private IUserManager $users,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVALL_SEKUNDEN);
	}

	/**
	 * @param mixed $argument Wird nicht verwendet.
	 */
	protected function run($argument): void {
		if (!$this->settings->isEnabled()) {
			return;
		}

		$cfg = $this->settings->getImapConfig();
		if ($cfg === null) {
			return;
		}

		$client = new ImapClient($cfg['host'], $cfg['port'], $cfg['security']);
		try {
			$client->connect();
			$client->login($cfg['user'], $cfg['password']);
			$client->select($cfg['folder']);

			$uids = $client->uidSearch('UNSEEN');
			$jeAbsender = [];
			$angefasst = 0;
			foreach ($uids as $uid) {
				if ($angefasst >= self::BATCH) {
					break;
				}
				$angefasst++;

				try {
					$this->verarbeiten($client, $uid, $jeAbsender);
				} catch (\Throwable $e) {
					// Eine Mail darf den Lauf nicht beenden — und ein
					// „Giftbrief" nicht jeden Lauf erneut kosten: gelesen
					// markieren, damit der nächste Lauf ihn übergeht.
					$this->logger->warning('ProjektWerk: Antwort-Einlesen an UID ' . $uid . ' gescheitert', ['exception' => $e]);
					$this->stillMarkieren($client, $uid);
				}
			}
		} catch (\Throwable $e) {
			// Postfach nicht erreichbar, Anmeldung abgelehnt, Ordner fehlt: ein
			// Zustand, kein Programmfehler — im nächsten Lauf erneut versuchen.
			$this->logger->warning('ProjektWerk: Antwort-Postfach nicht erreichbar', ['exception' => $e]);
		} finally {
			$client->logout();
		}
	}

	/**
	 * Eine einzelne ungelesene Mail verarbeiten.
	 *
	 * @param ImapClient $client Die offene Verbindung.
	 * @param int $uid Die UID der Mail im gewählten Ordner.
	 * @param array<string, int> $jeAbsender Zähler je Absenderadresse für diesen Lauf.
	 */
	private function verarbeiten(ImapClient $client, int $uid, array &$jeAbsender): void {
		$raw = $client->uidFetchRaw($uid);
		if (trim($raw) === '') {
			$this->abschliessen($client, $uid, 'leere Mail');

			return;
		}

		$mail = MimeMessage::parse($raw);

		// 1. Automaten-/Schleifenschutz — zuerst, vor jeder Zuordnung.
		if ($mail['autoSubmitted'] !== '' && $mail['autoSubmitted'] !== 'no') {
			$this->abschliessen($client, $uid, 'Auto-Submitted: ' . $mail['autoSubmitted']);

			return;
		}
		if (in_array($mail['precedence'], ['bulk', 'list', 'junk'], true)) {
			$this->abschliessen($client, $uid, 'Precedence: ' . $mail['precedence']);

			return;
		}
		$from = $mail['from'];
		if ($from === '') {
			$this->abschliessen($client, $uid, 'ohne Absender');

			return;
		}
		$antwortAdresse = strtolower(trim($this->settings->getReplyAddress()));
		if ($antwortAdresse !== '' && $from === $antwortAdresse) {
			// Die eigene Adresse — eine Schleife. Nie beantworten.
			$this->abschliessen($client, $uid, 'eigene Absenderadresse (Schleife)');

			return;
		}

		// 2. Anker über den Betreff-Token.
		$token = $this->tokenAus($mail['subject']);
		$zeile = $token !== null ? $this->outbox->findByReplyToken($token) : null;
		if ($zeile === null) {
			$this->unmatched($client, $uid, 'kein Token oder kein Treffer');

			return;
		}

		$recipientUid = (string)$zeile->getRecipientUid();
		$ticketId = $zeile->getTicketId();
		if ($ticketId === null) {
			$this->unmatched($client, $uid, 'Ausgangszeile ohne Vorgang');

			return;
		}

		// 3a. Absender == hinterlegte Adresse genau dieses Kontos.
		$empfaenger = $this->users->get($recipientUid);
		$kontoAdresse = strtolower(trim((string)($empfaenger?->getEMailAddress() ?? '')));
		if ($kontoAdresse === '' || $from !== $kontoAdresse) {
			$this->unmatched($client, $uid, 'Absender entspricht nicht der Adresse des Kontos');

			return;
		}

		// 3b. Konto sieht den Vorgang zum Verarbeitungszeitpunkt noch (Mitglied).
		try {
			$ticket = $this->tickets->findVisibleAnywhere($recipientUid, $ticketId);
		} catch (\Throwable) {
			$this->unmatched($client, $uid, 'Vorgang für das Konto nicht (mehr) sichtbar');

			return;
		}
		$boardId = (int)$ticket->getBoardId();

		// Amok-Bremse: höchstens MAX_JE_ABSENDER je Absender und Lauf.
		$jeAbsender[$from] = ($jeAbsender[$from] ?? 0) + 1;
		if ($jeAbsender[$from] > self::MAX_JE_ABSENDER) {
			$this->logger->warning('ProjektWerk: Absender ' . $from . ' über der Lauf-Grenze — Mail zurückgestellt (gelesen markiert)');
			$this->stillMarkieren($client, $uid);

			return;
		}

		// 4. Text ohne Zitat/Signatur.
		$body = ReplyTextExtractor::extract($mail['text']);
		if ($body === '') {
			$this->unmatched($client, $uid, 'leerer Text nach Zitat-/Signatur-Schnitt');

			return;
		}

		// 4b. Anhänge v1: nicht übernehmen, nur vermerken — in der Sprache des Kontos.
		if ($mail['hasAttachments']) {
			$l = $this->l10nFactory->get(
				Application::APP_ID,
				$empfaenger !== null ? $this->l10nFactory->getUserLanguage($empfaenger) : null,
			);
			$body .= "\n\n" . $l->t('[Anhang aus E-Mail nicht übernommen – bitte im Vorgang hochladen]');
		}

		// 5. Kommentar über den regulären Weg — ViewerContext NUR über BoardAccess.
		$viewer = $this->access->contextFor($recipientUid, $boardId);
		$this->comments->create($viewer, $ticketId, $body);

		$client->markSeen($uid);
		$this->logger->info('ProjektWerk: Antwort per E-Mail als Kommentar am Vorgang ' . $ticketId . ' übernommen');
	}

	/**
	 * Den Antwort-Token aus dem Betreff ziehen (`[PW-{32 Hex}]`), oder null.
	 *
	 * @param string $betreff Der (dekodierte) Betreff der eingehenden Mail.
	 */
	private function tokenAus(string $betreff): ?string {
		return preg_match('/\[PW-([0-9a-f]{32})\]/', $betreff, $m) === 1 ? $m[1] : null;
	}

	/**
	 * Verworfen (Automat/Schleife/leer): gelesen markieren, auf Info-Ebene loggen.
	 */
	private function abschliessen(ImapClient $client, int $uid, string $grund): void {
		$this->logger->info('ProjektWerk: Antwort-Mail übergangen (' . $grund . ')');
		$this->stillMarkieren($client, $uid);
	}

	/**
	 * Unzuordenbar oder abgelehnt: gelesen markieren (nicht löschen), auf
	 * Warn-Ebene loggen, damit der Betreiber den Fall findet.
	 */
	private function unmatched(ImapClient $client, int $uid, string $grund): void {
		$this->logger->warning('ProjektWerk: Antwort-Mail unzuordenbar (' . $grund . ') — bleibt im Postfach, gelesen markiert');
		$this->stillMarkieren($client, $uid);
	}

	/**
	 * Gelesen markieren, ohne den Lauf an einem IMAP-Fehler scheitern zu lassen.
	 */
	private function stillMarkieren(ImapClient $client, int $uid): void {
		try {
			$client->markSeen($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('ProjektWerk: UID ' . $uid . ' ließ sich nicht als gelesen markieren', ['exception' => $e]);
		}
	}
}
