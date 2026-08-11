<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spike S4 — Mail und Deep-Link. **Wegwerfcode, gehoert nicht nach lib/.**
 *
 * Zwei Fragen aus §11 und dem Plan §3.11:
 *
 * 1. **Wie verhaelt sich `IMailer` bei totem SMTP-Port?** Der MVP verschickt
 *    synchron nach dem Commit. Wenn `send()` dabei minutenlang haengt, haengt die
 *    HTTP-Antwort mit. Gesucht ist eine **Zahl**: das Zeitbudget, ab dem
 *    abgebrochen wird.
 *
 * 2. **Wirkt `overwrite.cli.url` auf den Link aus einem Hintergrundjob?**
 *
 * **Je Messung ein eigener Prozess.** Nextclouds DI-Container gibt denselben
 * `IMailer` zurueck, und der baut seinen Transport genau einmal. Wer im selben
 * Prozess die Konfiguration aendert und erneut sendet, misst den ersten Versuch
 * ein zweites Mal — genau das ist beim ersten Anlauf passiert (alle vier
 * Messungen 0,04 s „kein Fehler", weil alle an MailHog gingen).
 *
 * Aufruf ueber `spike/S4-lauf.sh` — der stellt die Mailkonfiguration hinterher
 * wieder her, auch wenn eine Messung abbricht.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once '/var/www/html/lib/base.php';

use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Server;

/**
 * @param string $zeile Ein Befund in einem Satz.
 */
function befund(string $zeile): void {
	echo '  BEFUND: ' . $zeile . PHP_EOL;
}

$modus = $argv[1] ?? 'links';

if ($modus === 'senden') {
	$was = $argv[2] ?? '(unbenannt)';

	$mailer = Server::get(IMailer::class);
	$nachricht = $mailer->createMessage();
	$nachricht->setSubject('S4-Probe');
	$nachricht->setTo(['probe@example.invalid' => 'Probe']);
	$nachricht->setFrom(['projektwerk@example.org' => 'ProjektWerk']);
	$nachricht->setPlainBody('Nur eine Messung.');

	$start = microtime(true);
	$fehler = null;
	$gescheitert = [];

	try {
		// **Der Rueckgabewert ist das Fehlersignal, nicht eine Ausnahme.**
		// `Mailer::send()` faengt `TransportExceptionInterface` selbst, loggt und
		// gibt die fehlgeschlagenen Empfaenger zurueck; leer heisst Erfolg. Wer
		// nur `try/catch` schreibt, haelt jeden Fehlschlag fuer einen Erfolg.
		$gescheitert = $mailer->send($nachricht);
	} catch (\Throwable $e) {
		$fehler = (new ReflectionClass($e))->getShortName() . ': ' . substr(trim($e->getMessage()), 0, 100);
	}

	befund(sprintf(
		'%s: %s s — Ausnahme: %s · gescheiterte Empfaenger: %s',
		$was,
		round(microtime(true) - $start, 2),
		$fehler ?? 'keine',
		$gescheitert === [] ? 'keine (= Erfolg)' : implode(', ', $gescheitert),
	));

	exit(0);
}

// ----------------------------------------------------------- Teil 2: Adressen
$config = Server::get(IConfig::class);
$urls = Server::get(IURLGenerator::class);

befund('overwrite.cli.url = ' . var_export($config->getSystemValue('overwrite.cli.url', null), true));
befund('trusted_domains = ' . implode(', ', (array)$config->getSystemValue('trusted_domains', [])));
befund('getBaseUrl() = ' . $urls->getBaseUrl());
befund('getAbsoluteURL("/index.php/apps/projektwerk/t/42") = ' . $urls->getAbsoluteURL('/index.php/apps/projektwerk/t/42'));

// Der Deep-Link, wie ihn die Mail traegt. Im CLI-Kontext gibt es keine Anfrage —
// Nextcloud muss die Adresse also aus der Konfiguration nehmen.
// Welche Namen kennt der Router ueberhaupt? `generate()` verschluckt einen
// unbekannten Namen und gibt '' zurueck — ohne diese Liste raet man.
// **Die App muss geladen sein, bevor ihre Routen existieren.** In einem nackten
// CLI-Skript ist sie das nicht — ein TimedJob laeuft dagegen im vollen
// App-Kontext. Genau diese Unterscheidung ist die Frage.
$apps = Server::get(\OCP\App\IAppManager::class);
befund('App vor loadApp registriert? ' . ($apps->isAppLoaded('projektwerk') ? 'ja' : 'nein'));
$apps->loadApp('projektwerk');
befund('App nach loadApp registriert? ' . ($apps->isAppLoaded('projektwerk') ? 'ja' : 'nein'));

$router = Server::get(\OCP\Route\IRouter::class);
$router->loadRoutes('projektwerk');
$namen = array_keys($router->getRouteCollection()->all());
befund('Geladene projektwerk-Routen: ' . count($namen));
$unsere = array_values(array_filter($namen, static fn (string $n): bool => str_starts_with($n, 'projektwerk')));
befund('Davon unsere: ' . count($unsere));
befund('Erste zehn: ' . implode(' · ', array_slice($unsere, 0, 10)));
befund('Mit „t" oder „deep": ' . implode(' · ', array_values(array_filter(
	$unsere,
	static fn (string $n): bool => str_contains($n, 'deep') || str_ends_with($n, '.ticket'),
))));

try {
	$relativ = $urls->linkToRoute('projektwerk.deepLink.ticket', ['ticketId' => 42]);
	befund('linkToRoute (relativ) = ' . $relativ);
	$link = $urls->linkToRouteAbsolute('projektwerk.deepLink.ticket', ['ticketId' => 42]);
	befund('linkToRouteAbsolute = ' . $link);
	befund('Enthaelt „localhost"? ' . (str_contains($link, 'localhost') ? 'JA — so waere die Mail wertlos' : 'nein'));
	befund('Enthaelt ein @? ' . (str_contains($link, '@') ? 'JA — Nextcloud verwirft solche Rücksprungziele' : 'nein'));
} catch (\Throwable $e) {
	befund('Routenaufloesung scheitert: ' . (new ReflectionClass($e))->getShortName() . ': ' . substr($e->getMessage(), 0, 160));
}
