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
use OCA\Projektwerk\Imap\ImapCommandRejectedException;
use OCA\Projektwerk\Imap\MimeMessage;
use OCA\Projektwerk\Service\CommentService;
use OCA\Projektwerk\Service\ReplyMailboxSettings;
use OCA\Projektwerk\Service\ReplyTextExtractor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
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
 * Mails werden verschoben, nie gelöscht; ohne Ordner-Unterstützung nur gelesen markiert.
 */
class ReplyIntakeJob extends TimedJob {

	/** Alle fünf Minuten (§Antwort-Postfach). */
	private const INTERVALL_SEKUNDEN = 300;

	/** Wie viele ungelesene Mails ein Lauf höchstens anfasst, je für Antworten und für den Rest. */
	private const BATCH = 30;

	/** Höchstzahl Kommentare per Mail je Konto und Stunde, über alle Läufe hinweg. */
	public const MAX_JE_STUNDE = 20;

	private const LIMIT_ID = 'projektwerk-mail-reply';

	public const ORDNER_ELTERN = 'ProjektWerk';
	public const ORDNER_VERARBEITET = 'Verarbeitet';
	public const ORDNER_NICHT_ZUGEORDNET = 'Nicht zugeordnet';

	private string $quellOrdner = 'INBOX';

	/** @var array<string, string|false> Zielordner je Art für diesen Lauf; false = nicht nutzbar */
	private array $ziele = [];

	/** @var list<string>|null */
	private ?array $vorhandeneOrdner = null;

	public const TOKEN_GUELTIG_SEKUNDEN = 7 * 86400;

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
		private ILimiter $limiter,
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
			$this->quellOrdner = $cfg['folder'];
			$this->ziele = [];
			$this->vorhandeneOrdner = null;

			$this->abarbeiten($client);
		} catch (\Throwable $e) {
			// Postfach nicht erreichbar, Anmeldung abgelehnt, Ordner fehlt: ein
			// Zustand, kein Programmfehler — im nächsten Lauf erneut versuchen.
			$this->logger->warning('ProjektWerk: Antwort-Postfach nicht erreichbar', ['exception' => $e]);
		} finally {
			$client->logout();
		}
	}

	/** Antworten zuerst und mit eigenem Kontingent, damit fremde Mails im Postfach sie nicht verdrängen. */
	private function abarbeiten(ImapClient $client): void {
		try {
			$antworten = $client->uidSearch('UNSEEN SUBJECT "[PW-"');
		} catch (ImapCommandRejectedException) {
			$this->logger->info('ProjektWerk: Postfach kann nicht im Betreff suchen, Antworten ohne Vorrang');
			$antworten = [];
		}
		$this->stapel($client, $antworten);

		// Erst danach: Die Liste aller ungelesenen Mails kann bei einem vollen Postfach scheitern.
		$this->stapel($client, array_values(array_diff($client->uidSearch('UNSEEN'), $antworten)));
	}

	/** @param int[] $uids */
	private function stapel(ImapClient $client, array $uids): void {
		foreach (array_slice($uids, 0, self::BATCH) as $uid) {
			try {
				$this->verarbeiten($client, $uid);
			} catch (\Throwable $e) {
				// Ein „Giftbrief" darf weder den Lauf beenden noch jeden Lauf erneut kosten.
				$this->logger->warning('ProjektWerk: Antwort-Einlesen an UID ' . $uid . ' gescheitert', ['exception' => $e]);
				$this->ablegen($client, $uid, self::ORDNER_NICHT_ZUGEORDNET);
			}
		}
	}

	/**
	 * Eine einzelne ungelesene Mail verarbeiten.
	 *
	 * @param ImapClient $client Die offene Verbindung.
	 * @param int $uid Die UID der Mail im gewählten Ordner.
	 */
	private function verarbeiten(ImapClient $client, int $uid): void {
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

		$versandt = $zeile->getSentAt() ?? $zeile->getCreatedAt();
		if ($versandt === null || $versandt->getTimestamp() < $this->time->getTime() - self::TOKEN_GUELTIG_SEKUNDEN) {
			$this->unmatched($client, $uid, 'Antwort-Code abgelaufen');

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
		if ($empfaenger === null || $kontoAdresse === '' || $from !== $kontoAdresse) {
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
				$this->l10nFactory->getUserLanguage($empfaenger),
			);
			$body .= "\n\n" . $l->t('[Anhang aus E-Mail nicht übernommen – bitte im Vorgang hochladen]');
		}

		// 5. Kommentar über den regulären Weg — ViewerContext NUR über BoardAccess.
		$viewer = $this->access->contextFor($recipientUid, $boardId)->viaMail();
		if ($viewer->readOnly) {
			$this->unmatched($client, $uid, 'Board archiviert');

			return;
		}
		try {
			$this->limiter->registerUserRequest(self::LIMIT_ID, self::MAX_JE_STUNDE, 3600, $empfaenger);
		} catch (IRateLimitExceededException) {
			$this->logger->warning('ProjektWerk: Konto ' . $recipientUid . ' über der Stunden-Grenze, Mail nach „' . self::ORDNER_NICHT_ZUGEORDNET . '“');
			$this->ablegen($client, $uid, self::ORDNER_NICHT_ZUGEORDNET);

			return;
		}
		$this->comments->create($viewer, $ticketId, $body);

		// Erst der Kommentar, dann das Ablegen: ein doppelter Kommentar ist sichtbar, eine verlorene Antwort nicht.
		$this->ablegen($client, $uid, self::ORDNER_VERARBEITET);
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

	/** Verworfen (Automat/Schleife/leer): ablegen, auf Info-Ebene loggen. */
	private function abschliessen(ImapClient $client, int $uid, string $grund): void {
		$this->logger->info('ProjektWerk: Antwort-Mail übergangen (' . $grund . ')');
		$this->ablegen($client, $uid, self::ORDNER_NICHT_ZUGEORDNET);
	}

	/** Unzuordenbar oder abgelehnt: ablegen, auf Warn-Ebene loggen, damit der Betreiber den Fall findet. */
	private function unmatched(ImapClient $client, int $uid, string $grund): void {
		$this->logger->warning('ProjektWerk: Antwort-Mail unzuordenbar (' . $grund . '), nach „' . self::ORDNER_NICHT_ZUGEORDNET . '“');
		$this->ablegen($client, $uid, self::ORDNER_NICHT_ZUGEORDNET);
	}

	/** In den Unterordner verschieben; geht das nicht, nur gelesen markieren. Ungelesen in „Nicht zugeordnet“ zeigt, wo jemand nachsehen sollte. */
	private function ablegen(ImapClient $client, int $uid, string $art): void {
		$gelesen = $art === self::ORDNER_VERARBEITET && $this->stillMarkieren($client, $uid);
		$ziel = $this->zielordner($client, $art);
		if ($ziel !== null) {
			try {
				$client->uidMove($uid, $ziel);

				return;
			} catch (\Throwable $e) {
				$this->logger->warning('ProjektWerk: UID ' . $uid . ' ließ sich nicht nach „' . $ziel . '“ verschieben, nur gelesen markiert', ['exception' => $e]);
			}
		}
		if (!$gelesen) {
			$this->stillMarkieren($client, $uid);
		}
	}

	/** Der Pfad des Unterordners, beim ersten Bedarf im Lauf angelegt; null, wenn das nicht geht. */
	private function zielordner(ImapClient $client, string $art): ?string {
		if (!array_key_exists($art, $this->ziele)) {
			$this->ziele[$art] = $this->ordnerBereitstellen($client, $art) ?? false;
		}

		return $this->ziele[$art] === false ? null : $this->ziele[$art];
	}

	private function ordnerBereitstellen(ImapClient $client, string $art): ?string {
		try {
			$trenner = $client->hierarchyDelimiter($this->quellOrdner);
			if ($trenner === null || $trenner === '') {
				return null;
			}
			$pfad = $this->quellOrdner . $trenner . self::ORDNER_ELTERN . $trenner . $art;
			$this->vorhandeneOrdner ??= $client->listFolders();
			$vorhanden = self::finden($this->vorhandeneOrdner, $pfad);
			if ($vorhanden !== null) {
				return $vorhanden;
			}
			try {
				$client->createFolder($pfad);
			} catch (ImapCommandRejectedException $e) {
				// Der Server schreibt INBOX evtl. anders, oder jemand legte den Ordner gerade an.
				$vorhanden = self::finden($client->listFolders(), $pfad);
				if ($vorhanden !== null) {
					return $vorhanden;
				}
				if (!str_contains($e->getMessage(), '[ALREADYEXISTS]')) {
					throw $e;
				}

				return $pfad;
			}
			$this->vorhandeneOrdner[] = $pfad;
			try {
				$client->subscribe($pfad);
			} catch (\Throwable) {
				// Ohne Abo bleibt der Ordner nutzbar, nur manche Mailprogramme blenden ihn aus.
			}

			return $pfad;
		} catch (\Throwable $e) {
			$this->logger->warning('ProjektWerk: Ordner „' . $art . '“ im Antwort-Postfach nicht verfügbar, Mails werden nur gelesen markiert', ['exception' => $e]);

			return null;
		}
	}

	/**
	 * Der Ordner in der Schreibweise des Servers, oder null.
	 *
	 * @param list<string> $ordner
	 */
	private static function finden(array $ordner, string $pfad): ?string {
		foreach ($ordner as $name) {
			if (strcasecmp($name, $pfad) === 0) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Gelesen markieren, ohne den Lauf an einem IMAP-Fehler scheitern zu lassen.
	 */
	private function stillMarkieren(ImapClient $client, int $uid): bool {
		try {
			$client->markSeen($uid);

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('ProjektWerk: UID ' . $uid . ' ließ sich nicht als gelesen markieren', ['exception' => $e]);

			return false;
		}
	}
}
