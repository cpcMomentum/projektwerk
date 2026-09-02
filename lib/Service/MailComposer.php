<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\Ticket;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Der Text einer Benachrichtigungs-Mail — **an einer Stelle** (#248, Teil 2).
 *
 * Erstversand ({@see NotificationService::deliver()}) und Nachversand
 * ({@see \OCA\Projektwerk\BackgroundJob\MailRetryJob}) bauten den Text bisher
 * getrennt: der erste reich, der zweite nur „Vorgang #N: Titel". Eine
 * nachgereichte Mail sah dadurch schlechter aus als die beim ersten Versuch.
 * Jetzt bauen beide über diese Klasse denselben Text.
 *
 * **Woher die Bausteine kommen:**
 * - **Auslöser** aus `actor_uid` auf der Zeile; der Name wird hier aufgelöst,
 *   damit er den aktuellen Anzeigenamen trägt.
 * - **Schritt-Titel** aus `step_title` auf der Zeile — eingefroren beim
 *   Vormerken, damit der Nachversand keinen Schritt nachladen muss (Schritte
 *   werden nie eigenständig abgefragt).
 * - **Projektname** über {@see BoardMapper::findAllForUser()} aus der Sicht des
 *   **Empfängers**: die schon vorhandene, über die Mitgliedschaft gesperrte
 *   Lesemethode, kein neuer Lesepfad. Wer die Mail bekommt, ist Mitglied des
 *   Boards, also steht es in seiner Liste.
 *
 * Fehlt einer der Bausteine (alte Zeile, Anlass ohne Schritt, Konto weg), fällt
 * der Satz auf seine Form ohne diesen Baustein zurück.
 */
class MailComposer {

	/**
	 * Zwischenspeicher innerhalb einer Zustellung.
	 *
	 * `deliver()` ruft {@see compose()} in einer Schleife über alle Empfänger.
	 * Auslöser und Projekt sind dabei für alle gleich — ohne Memo liefe je
	 * Empfänger dieselbe Board-Abfrage und derselbe Konto-Lookup erneut (das
	 * N+1 aus dem Review). Der Composer wird pro Request frisch aus dem Container
	 * gebaut, der Speicher lebt also nur so lange wie die Anfrage; ein zwischen
	 * zwei Requests umbenanntes Projekt trägt beim nächsten Mail schon den neuen
	 * Namen.
	 *
	 * @var array<int, string> Projektname je Board-Id — nur **gefundene** Namen,
	 *                         damit ein Empfänger ohne Mitgliedschaft den Cache
	 *                         nicht für die übrigen vergiftet.
	 */
	private array $projektCache = [];

	/** @var array<string, string|null> Anzeigename je Auslöser-Kennung. */
	private array $aktorCache = [];

	public function __construct(
		private IUserManager $users,
		private BoardMapper $boards,
	) {
	}

	/**
	 * Betreff, Einleitungssatz und Metazeile — in der Sprache der Zeile.
	 *
	 * @param MailOutbox $zeile Die vorgemerkte Mail (trägt Anlass, Auslöser, Schritt).
	 * @param Ticket $ticket Der aktuelle Stand des Vorgangs (Nummer, Titel, Board).
	 * @param IL10N $l Die Sprache des Empfängers.
	 * @return array{betreff: string, einleitung: string, meta: string}
	 */
	public function compose(MailOutbox $zeile, Ticket $ticket, IL10N $l): array {
		$event = (string)$zeile->getEvent();
		$nummer = str_pad((string)$ticket->getNumber(), 4, '0', STR_PAD_LEFT);
		$titel = (string)$ticket->getTitle();
		$actor = $this->actorName($zeile);
		$schritt = $this->leer($zeile->getStepTitle());
		$projekt = $this->projectName((string)$zeile->getRecipientUid(), (int)$ticket->getBoardId());

		return [
			'betreff' => $this->betreff($l, $event, $nummer),
			'einleitung' => $this->einleitung($l, $event, $nummer, $titel, $actor, $schritt),
			'meta' => $this->meta($l, $nummer, $projekt),
		];
	}

	/**
	 * @param IL10N $l Sprache des Empfängers.
	 * @param string $event Einer der `EVENT_*`-Werte.
	 * @param string $nummer Die Vorgangsnummer, vierstellig.
	 */
	private function betreff(IL10N $l, string $event, string $nummer): string {
		return match ($event) {
			MailOutbox::EVENT_TICKET_ASSIGNED => $l->t('Vorgang #%1$s wurde Ihnen zugewiesen', [$nummer]),
			MailOutbox::EVENT_STEP_ASSIGNED => $l->t('Arbeitsschritt in Vorgang #%1$s wurde Ihnen zugewiesen', [$nummer]),
			MailOutbox::EVENT_COMMENT_ADDED => $l->t('Neuer Kommentar zu Vorgang #%1$s', [$nummer]),
			MailOutbox::EVENT_COMMENT_MENTION => $l->t('Sie wurden in Vorgang #%1$s erwähnt', [$nummer]),
			MailOutbox::EVENT_TICKET_CLOSED => $l->t('Vorgang #%1$s wurde geschlossen', [$nummer]),
			default => $l->t('Neuer Vorgang #%1$s', [$nummer]),
		};
	}

	/**
	 * Der Einleitungssatz — mit Auslöser, wo einer bekannt ist, und mit dem
	 * Schritt-Titel bei einer Schritt-Zuweisung.
	 *
	 * @param IL10N $l Sprache des Empfängers.
	 * @param string $event Einer der `EVENT_*`-Werte.
	 * @param string $nummer Vorgangsnummer, vierstellig.
	 * @param string $titel Titel des Vorgangs.
	 * @param string|null $actor Name der auslösenden Person, oder null.
	 * @param string|null $schritt Titel des zugewiesenen Schritts, oder null.
	 */
	private function einleitung(IL10N $l, string $event, string $nummer, string $titel, ?string $actor, ?string $schritt): string {
		switch ($event) {
			case MailOutbox::EVENT_TICKET_ASSIGNED:
				return $actor !== null
					? $l->t('%1$s hat Ihnen den Vorgang #%2$s „%3$s“ zugewiesen.', [$actor, $nummer, $titel])
					: $l->t('Ihnen wurde der Vorgang #%1$s „%2$s“ zugewiesen.', [$nummer, $titel]);

			case MailOutbox::EVENT_STEP_ASSIGNED:
				if ($actor !== null && $schritt !== null) {
					return $l->t('%1$s hat Ihnen den Arbeitsschritt „%2$s“ im Vorgang #%3$s „%4$s“ zugewiesen.', [$actor, $schritt, $nummer, $titel]);
				}
				if ($actor !== null) {
					return $l->t('%1$s hat Ihnen einen Arbeitsschritt im Vorgang #%2$s „%3$s“ zugewiesen.', [$actor, $nummer, $titel]);
				}
				if ($schritt !== null) {
					return $l->t('Ihnen wurde der Arbeitsschritt „%1$s“ im Vorgang #%2$s „%3$s“ zugewiesen.', [$schritt, $nummer, $titel]);
				}
				return $l->t('Ihnen wurde ein Arbeitsschritt im Vorgang #%1$s „%2$s“ zugewiesen.', [$nummer, $titel]);

			case MailOutbox::EVENT_COMMENT_ADDED:
				return $actor !== null
					? $l->t('%1$s hat einen Kommentar zum Vorgang #%2$s „%3$s“ geschrieben.', [$actor, $nummer, $titel])
					: $l->t('Es gibt einen neuen Kommentar zum Vorgang #%1$s „%2$s“.', [$nummer, $titel]);

			case MailOutbox::EVENT_COMMENT_MENTION:
				return $actor !== null
					? $l->t('%1$s hat Sie in einem Kommentar zum Vorgang #%2$s „%3$s“ erwähnt.', [$actor, $nummer, $titel])
					: $l->t('Sie wurden in einem Kommentar zum Vorgang #%1$s „%2$s“ erwähnt.', [$nummer, $titel]);

			case MailOutbox::EVENT_TICKET_CLOSED:
				return $actor !== null
					? $l->t('%1$s hat den Vorgang #%2$s „%3$s“ geschlossen.', [$actor, $nummer, $titel])
					: $l->t('Der Vorgang #%1$s „%2$s“ wurde geschlossen.', [$nummer, $titel]);

			default:
				return $actor !== null
					? $l->t('%1$s hat den neuen Vorgang #%2$s „%3$s“ angelegt.', [$actor, $nummer, $titel])
					: $l->t('Im Projekt ist der neue Vorgang #%1$s „%2$s“ entstanden.', [$nummer, $titel]);
		}
	}

	/**
	 * Die Metazeile über dem Text: Projekt und Vorgangsnummer.
	 *
	 * Ohne bekannten Projektnamen entfällt sie — eine Zeile, die nur die
	 * Vorgangsnummer wiederholt, die schon im Satz steht, ist keine.
	 *
	 * @param IL10N $l Sprache des Empfängers.
	 * @param string $nummer Vorgangsnummer, vierstellig.
	 * @param string|null $projekt Projektname, oder null.
	 */
	private function meta(IL10N $l, string $nummer, ?string $projekt): string {
		return $projekt !== null
			? $l->t('Projekt %1$s · Vorgang #%2$s', [$projekt, $nummer])
			: '';
	}

	/**
	 * Der Anzeigename der auslösenden Person, oder null.
	 *
	 * Dieselbe Vorsicht wie in {@see MailDispatcher}: Ein Hash (Gastkennung) ist
	 * kein Name, ein leerer Name auch nicht — beide fallen auf null zurück, und
	 * der Satz kommt dann ohne Auslöser aus.
	 *
	 * @param MailOutbox $zeile Die Mail-Zeile.
	 */
	private function actorName(MailOutbox $zeile): ?string {
		$uid = $this->leer($zeile->getActorUid());
		if ($uid === null) {
			return null;
		}

		// Der Auslöser ist über alle Empfänger einer Zustellung derselbe — den
		// Namen (auch das Ergebnis „keiner") je Kennung nur einmal auflösen.
		if (!array_key_exists($uid, $this->aktorCache)) {
			$this->aktorCache[$uid] = $this->aufloesenAktor($uid);
		}

		return $this->aktorCache[$uid];
	}

	/**
	 * @param string $uid Kennung der auslösenden Person.
	 */
	private function aufloesenAktor(string $uid): ?string {
		$name = $this->users->get($uid)?->getDisplayName();
		if ($name === null) {
			return null;
		}

		$name = trim($name);

		return ($name === '' || $name === $uid) ? null : $name;
	}

	/**
	 * Der Name des Projekts, aus Sicht des Empfängers.
	 *
	 * Über {@see BoardMapper::findAllForUser()} — die über die Mitgliedschaft
	 * gesperrte Lesemethode, kein neuer Lesepfad. Der Empfänger ist Mitglied des
	 * Boards (sonst bekäme er die Mail nicht), es steht also in seiner Liste;
	 * `includeArchived`, weil auch ein geschlossener Vorgang in einem
	 * archivierten Projekt eine Mail ausgelöst haben kann.
	 *
	 * @param string $recipientUid Kennung des Empfängers.
	 * @param int $boardId Kennung des Projekts.
	 */
	private function projectName(string $recipientUid, int $boardId): ?string {
		// Nur gefundene Namen liegen im Cache: Ein Empfänger, der das Board
		// (nicht mehr) sieht, findet nichts und lässt den nächsten es erneut
		// versuchen, statt „kein Name" für alle festzuschreiben.
		if (isset($this->projektCache[$boardId])) {
			return $this->projektCache[$boardId];
		}

		foreach ($this->boards->findAllForUser($recipientUid, true) as $board) {
			if ((int)$board->getId() === $boardId) {
				$titel = trim((string)$board->getTitle());
				if ($titel === '') {
					return null;
				}

				return $this->projektCache[$boardId] = $titel;
			}
		}

		return null;
	}

	/**
	 * Getrimmter Wert, oder null bei leer/null.
	 *
	 * @param string|null $wert Der rohe Wert.
	 */
	private function leer(?string $wert): ?string {
		if ($wert === null) {
			return null;
		}

		$getrimmt = trim($wert);

		return $getrimmt === '' ? null : $getrimmt;
	}
}
