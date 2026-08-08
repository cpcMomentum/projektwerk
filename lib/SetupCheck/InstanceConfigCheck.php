<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\SetupCheck;

use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Drei Instanzeinstellungen, an denen ProjektWerk still scheitert.
 *
 * „Still" ist das gemeinsame Merkmal und der Grund, warum es diesen Check gibt.
 * Jeder der drei Werte laesst die App im Alltag funktionieren und bricht genau
 * das, was niemand sofort bemerkt — Benachrichtigungen an Kunden, die diese App
 * selten oeffnen. Ein Fehler, der beim Kunden auftritt und beim Betreiber nicht,
 * ist der teuerste; ein Feld in der Administrationsuebersicht ist die billigste
 * Gegenmassnahme.
 *
 * Alle drei sind auf `nextcloud-dev` am 2026-08-07/08 gemessen und standen dort
 * auf dem Problemwert. Die Quellen stehen je Pruefung darunter.
 */
class InstanceConfigCheck implements ISetupCheck {

	/**
	 * Obergrenze fuer `mail_smtptimeout` in Sekunden.
	 *
	 * Der Wert ist an eine Messung gebunden, nicht geschaetzt: S4 hat den Fall
	 * „Gegenstelle verschluckt Pakete" mit **10,3 s** gemessen (das ist zugleich
	 * Nextclouds Vorgabe, wenn nichts gesetzt ist) und mit `mail_smtptimeout=3`
	 * auf **3,2 s**. Die zehn Sekunden haengen im Schreibvorgang der Person, die
	 * gerade ein Ticket anlegt.
	 *
	 * Drei ist damit der einzige belegte funktionierende Wert. Wer hoeher will,
	 * verschiebt bewusst — deshalb steht die Zahl hier und nicht im Code.
	 */
	private const MAX_SMTP_TIMEOUT = 3;

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('ProjektWerk: Instanzkonfiguration');
	}

	public function run(): SetupResult {
		$problems = array_values(array_filter([
			$this->checkBackgroundJobs(),
			$this->checkCliUrl(),
			$this->checkMailTimeout(),
		]));

		if ($problems === []) {
			return SetupResult::success(
				$this->l10n->t('Hintergrundaufträge, Basis-URL und SMTP-Zeitgrenze sind für ProjektWerk geeignet.'),
			);
		}

		// Eine Warnung, nicht ein Fehler: Die App laeuft, nur die
		// Benachrichtigungen tun es nicht zuverlaessig. Ein Fehler waere hier
		// die Uebertreibung, die dazu fuehrt, dass Warnungen generell ignoriert
		// werden.
		return SetupResult::warning(implode("\n\n", $problems));
	}

	/**
	 * Der Cron-Modus. Ungesetzt heisst bei Nextcloud `ajax`.
	 *
	 * `ajax` fuehrt Hintergrundauftraege nur aus, wenn jemand eine Seite laedt.
	 * Der Versand aus Phase 6 laeuft in einem `TimedJob` — und die Empfaenger
	 * sind ueberwiegend Kunden, die diese Instanz gerade **nicht** offen haben.
	 * Die Mail geht dann irgendwann raus, wenn ein Mitarbeiter zufaellig
	 * arbeitet, oder gar nicht.
	 */
	private function checkBackgroundJobs(): ?string {
		$mode = $this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax');

		if ($mode === 'cron') {
			return null;
		}

		return $this->l10n->t(
			'Hintergrundaufträge laufen im Modus „%s" statt „cron". '
			. 'ProjektWerk verschickt Benachrichtigungen aus einem zeitgesteuerten Auftrag. '
			. 'Im Modus „ajax" läuft dieser nur, wenn jemand eine Seite dieser Instanz öffnet — '
			. 'die Empfänger sind aber überwiegend Kunden, die genau das selten tun. '
			. 'Umstellen mit: occ background:cron',
			[$mode],
		);
	}

	/**
	 * `overwrite.cli.url` — die Basis jedes Links aus Mail und Glocke.
	 *
	 * S4 hat belegt, dass der Wert bis in die zugestellte Mail durchschlaegt:
	 * Der Versand lief aus einem CLI-Kontext, in der Mail stand
	 * `http://localhost/`. Versand und Zustellung melden Erfolg — der Link ist
	 * fuer den Kunden trotzdem tot, und es faellt niemandem auf, der die Mail
	 * nicht selbst liest.
	 *
	 * Deshalb reicht „gesetzt" als Pruefung nicht. Geprueft wird auf „von aussen
	 * erreichbar", so gut das ohne Netzzugriff geht: nicht leer und kein
	 * Rechnername, der nur lokal gilt.
	 */
	private function checkCliUrl(): ?string {
		$url = trim($this->config->getSystemValueString('overwrite.cli.url', ''));

		if ($url === '') {
			return $this->l10n->t(
				'„overwrite.cli.url" ist nicht gesetzt. '
				. 'Links in E-Mails an Kunden entstehen aus diesem Wert und wären unbrauchbar. '
				. 'In config.php auf die von außen erreichbare Adresse setzen.',
			);
		}

		$host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? $url));

		// Bewusst eine kurze, benannte Liste statt einer Namensaufloesung: Ein
		// Setup-Check darf nicht ins Netz greifen. Er soll den offensichtlichen
		// Fehlstand melden, nicht Erreichbarkeit beweisen.
		$localHosts = ['localhost', '127.0.0.1', '::1', 'localhost.localdomain'];

		if (!in_array($host, $localHosts, true)) {
			return null;
		}

		return $this->l10n->t(
			'„overwrite.cli.url" zeigt auf „%s". '
			. 'Links in E-Mails an Kunden entstehen aus diesem Wert und führen damit ins Leere — '
			. 'Versand und Zustellung melden trotzdem Erfolg. '
			. 'In config.php auf die von außen erreichbare Adresse setzen.',
			[$url],
		);
	}

	/**
	 * `mail_smtptimeout` — wie lange ein Schreibvorgang haengen darf.
	 *
	 * Der wahrscheinliche Stoerfall ist nicht der geschlossene Port (der
	 * antwortet in 0,19 s), sondern die Gegenstelle, die Pakete verschluckt:
	 * Firewall, falscher Rechnername, abgelaufenes Relay. S4 hat dafuer 10,3 s
	 * gemessen — in der Anfrage der Person, die gerade ein Ticket anlegt.
	 */
	private function checkMailTimeout(): ?string {
		$timeout = $this->config->getSystemValueInt('mail_smtptimeout', 10);

		if ($timeout <= self::MAX_SMTP_TIMEOUT) {
			return null;
		}

		return $this->l10n->t(
			'„mail_smtptimeout" steht auf %1$s Sekunden (ohne Eintrag gilt Nextclouds Vorgabe von 10). '
			. 'Antwortet der Mailserver nicht, hängt diese Zeit im Schreibvorgang der Person, '
			. 'die gerade ein Ticket anlegt. Gemessen funktionsfähig sind %2$s Sekunden.',
			[(string)$timeout, (string)self::MAX_SMTP_TIMEOUT],
		);
	}
}
