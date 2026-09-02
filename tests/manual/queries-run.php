<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Handpruefung: Bauen und laufen alle Lese-Abfragen gegen ein echtes Schema?
 *
 * Prueft SQL-Gueltigkeit — Aliasse, Verbunde, Parameterbindung, Autowiring —
 * und erwartet dafuer bewusst **keine** Daten: Eine Abfrage, die nichts findet,
 * hat trotzdem bewiesen, dass sie uebersetzt und ausgefuehrt werden kann. Was
 * sie *findet*, prueft `visibility-rule.php`.
 *
 * Siehe README.md in diesem Verzeichnis.
 */

require_once '/var/www/html/lib/base.php';

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TaskFilter;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Db\TicketUserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

$viewer = ViewerContext::forMember('smoke-user', 999999, 999999, ViewerContext::ROLE_INTERNAL, false);

$checks = [];

$run = static function (string $label, callable $fn) use (&$checks): void {
	try {
		$value = $fn();
		$checks[] = sprintf('  OK   %-42s => %s', $label, is_array($value) ? count($value) . ' Zeilen' : var_export($value, true));
	} catch (DoesNotExistException) {
		$checks[] = sprintf('  OK   %-42s => DoesNotExistException (erwartet)', $label);
	} catch (\Throwable $e) {
		$checks[] = sprintf('  FAIL %-42s => %s: %s', $label, $e::class, $e->getMessage());
	}
};

/** @var TicketMapper $tickets */
$tickets = Server::get(TicketMapper::class);
$run('TicketMapper::findVisibleInBoard', static fn () => $tickets->findVisibleInBoard($viewer));
$run('TicketMapper::findVisibleInBoard(+Spalte)', static fn () => $tickets->findVisibleInBoard($viewer, 12345));
$run('TicketMapper::findVisible', static fn () => $tickets->findVisible($viewer, 12345));
$run('TicketMapper::countVisibleInBoard', static fn () => $tickets->countVisibleInBoard($viewer));
$run('TicketMapper::findVisibleAcrossBoards', static fn () => $tickets->findVisibleAcrossBoards('smoke-user', TaskFilter::openOnly()));
$run('TicketMapper::findVisibleAcrossBoards(+zu)', static fn () => $tickets->findVisibleAcrossBoards('smoke-user', TaskFilter::withClosed()));
// Der einzige Pfad mit einer **Unterabfrage**. Genau deshalb gehoert er
// hierher: Die CI faehrt nur SQLite, und ob `IN (SELECT …)` mit den am
// aeusseren Builder erzeugten Parametern auch auf MySQL und PostgreSQL baut,
// beantwortet nur ein echter Treiber.
$run('TicketMapper::findVisibleWithMyOpenSteps', static fn () => $tickets->findVisibleWithMyOpenSteps('smoke-user', TaskFilter::openOnly()));
$run('TicketMapper::findVisibleWithMyOpenSteps(+zu)', static fn () => $tickets->findVisibleWithMyOpenSteps('smoke-user', TaskFilter::withClosed()));

$run('BoardMapper::findForViewer', static fn () => Server::get(BoardMapper::class)->findForViewer($viewer));
$run('BoardMapper::findAllForUser', static fn () => Server::get(BoardMapper::class)->findAllForUser('smoke-user'));
$run('BoardMapper::findAllForUser(+archiviert)', static fn () => Server::get(BoardMapper::class)->findAllForUser('smoke-user', true));
$run('MemberMapper::findForBoard', static fn () => Server::get(MemberMapper::class)->findForBoard($viewer));
$run('ColumnMapper::findForBoard', static fn () => Server::get(ColumnMapper::class)->findForBoard($viewer));

foreach ([CommentMapper::class, StepMapper::class, AttachmentMapper::class, TicketUserMapper::class] as $class) {
	$mapper = Server::get($class);
	$short = substr((string)strrchr($class, '\\'), 1);
	$run($short . '::findForTickets', static fn () => $mapper->findForTickets([1, 2, 3]));
	$run($short . '::countForTickets', static fn () => $mapper->countForTickets([1, 2, 3]));
	$run($short . '::findForTickets([])', static fn () => $mapper->findForTickets([]));
}

echo implode("\n", $checks), "\n";
echo str_contains(implode('', $checks), 'FAIL') ? "\nERGEBNIS: FEHLGESCHLAGEN\n" : "\nERGEBNIS: alle Abfragen laufen\n";
