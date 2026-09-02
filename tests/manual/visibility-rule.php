<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Handpruefung der Sichtbarkeitsregel mit echten Zeilen.
 *
 * KEIN Ersatz fuer die Leak-Matrix (4 Nutzer x 9 Tickets x alle Endpunkte).
 * Prueft nur die Symmetrie von 'internal' und die Abgrenzung von 'private'
 * gegen die echte Datenbank — also das, was der containerfreien Suite
 * grundsaetzlich fehlt.
 *
 * Legt seine Daten selbst an und raeumt sie am Ende vollstaendig wieder ab.
 * Siehe README.md in diesem Verzeichnis.
 */

require_once '/var/www/html/lib/base.php';

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Comment;
use OCA\Projektwerk\Db\CommentMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Db\TaskFilter;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\IDBConnection;
use OCP\Server;

$db = Server::get(IDBConnection::class);
$projects = Server::get(ProjectMapper::class);
$boards = Server::get(BoardMapper::class);
$members = Server::get(MemberMapper::class);
$tickets = Server::get(TicketMapper::class);
$comments = Server::get(CommentMapper::class);

$now = new DateTime();
$failures = [];

// --- Aufbau ---------------------------------------------------------------
// #246 PR 2: Der Sichtbarkeitsverbund haengt jetzt an project_id, nicht mehr
// an board_id — Board, Mitglieder und Tickets brauchen also ein Projekt-Dach.
$project = new Project();
$project->setTitle('SMOKE-Rule');
$project->setOwnerUserId('smoke-anna');
$project->setArchived(0);
$project->setTicketCounter(0);
$project->setCreatedAt($now);
$project->setUpdatedAt($now);
$project = $projects->insert($project);
$projectId = (int)$project->getId();

$board = new Board();
$board->setTitle('SMOKE-Rule');
$board->setOwnerUserId('smoke-anna');
$board->setProjectId($projectId);
$board->setCreatedAt($now);
$board->setUpdatedAt($now);
$board = $boards->insert($board);
$boardId = (int)$board->getId();

foreach ([['smoke-anna', 'internal', 1], ['smoke-bert', 'internal', 0], ['smoke-kunde', 'external', 0]] as [$uid, $role, $mgr]) {
	$member = new Member();
	$member->setBoardId($boardId);
	$member->setProjectId($projectId);
	$member->setUserId($uid);
	$member->setRole($role);
	$member->setIsManager($mgr);
	$member->setAddedBy('smoke-anna');
	$member->setAddedAt($now);
	$members->insert($member);
}

$made = [];
$n = 0;
foreach ([
	'public/anna' => ['public', 'smoke-anna', 'internal'],
	'internal/anna' => ['internal', 'smoke-anna', 'internal'],
	'internal/kunde' => ['internal', 'smoke-kunde', 'external'],
	'private/anna' => ['private', 'smoke-anna', 'internal'],
	'private/kunde' => ['private', 'smoke-kunde', 'external'],
] as $label => [$visibility, $creator, $creatorRole]) {
	$ticket = new Ticket();
	$ticket->setBoardId($boardId);
	$ticket->setProjectId($projectId);
	$ticket->setColumnId(1);
	$ticket->setNumber(++$n);
	$ticket->setTitle($label);
	$ticket->setVisibility($visibility);
	$ticket->setCreatorUserId($creator);
	$ticket->setCreatorRole($creatorRole);
	$ticket->setResponsibleUserId($creator);
	$ticket->setPosition($n * 65536);
	$ticket->setVersion(1);
	$ticket->setCreatedAt($now);
	$ticket->setUpdatedAt($now);
	$ticket = $tickets->insert($ticket);
	$made[$label] = (int)$ticket->getId();

	$comment = new Comment();
	$comment->setTicketId((int)$ticket->getId());
	$comment->setAuthorUserId($creator);
	$comment->setBody('Kommentar zu ' . $label);
	$comment->setCreatedAt($now);
	$comment->setUpdatedAt($now);
	$comments->insert($comment);
}

// --- Pruefung -------------------------------------------------------------
$expect = static function (string $label, array $expected, array $actual) use (&$failures): void {
	sort($expected);
	sort($actual);
	if ($expected === $actual) {
		printf("  OK   %-46s => %s\n", $label, implode(', ', $actual) ?: '(leer)');
		return;
	}
	$failures[] = $label;
	printf("  FAIL %-46s => erwartet [%s], bekommen [%s]\n", $label, implode(', ', $expected), implode(', ', $actual));
};

$titles = static fn (array $rows): array => array_map(static fn ($t) => $t->getTitle(), $rows);

$anna = ViewerContext::forMember('smoke-anna', $boardId, $projectId, 'internal', true);
$bert = ViewerContext::forMember('smoke-bert', $boardId, $projectId, 'internal', false);
$kunde = ViewerContext::forMember('smoke-kunde', $boardId, $projectId, 'external', false);

$expect('anna (intern) sieht', ['public/anna', 'internal/anna', 'private/anna'], $titles($tickets->findVisibleInBoard($anna)));
$expect('bert (intern, nicht Erzeuger) sieht', ['public/anna', 'internal/anna'], $titles($tickets->findVisibleInBoard($bert)));
$expect('kunde (extern) sieht', ['public/anna', 'internal/kunde', 'private/kunde'], $titles($tickets->findVisibleInBoard($kunde)));

$expect('Zaehler anna', ['3'], [(string)$tickets->countVisibleInBoard($anna)]);
$expect('Zaehler bert', ['2'], [(string)$tickets->countVisibleInBoard($bert)]);
$expect('Zaehler kunde', ['3'], [(string)$tickets->countVisibleInBoard($kunde)]);

// Einzelabruf: bert darf annas privates Ticket nicht laden.
try {
	$tickets->findVisible($bert, $made['private/anna']);
	$failures[] = 'bert laedt privates Ticket';
	echo "  FAIL bert laedt annas privates Ticket\n";
} catch (\OCP\AppFramework\Db\DoesNotExistException) {
	echo "  OK   bert bekommt DoesNotExistException auf private/anna\n";
}
// ... und kunde nicht das interne der internen Seite.
try {
	$tickets->findVisible($kunde, $made['internal/anna']);
	$failures[] = 'kunde laedt internes Ticket';
	echo "  FAIL kunde laedt internal/anna\n";
} catch (\OCP\AppFramework\Db\DoesNotExistException) {
	echo "  OK   kunde bekommt DoesNotExistException auf internal/anna\n";
}

// Ein Nichtmitglied faellt aus dem INNER JOIN, auch mit selbst gebautem Kontext.
$fremd = ViewerContext::forMember('smoke-fremd', $boardId, $projectId, 'internal', true);
$expect('Nichtmitglied sieht (trotz Kontext)', [], $titles($tickets->findVisibleInBoard($fremd)));

// Kinder folgen der gefilterten Menge.
$kundenIds = array_map(static fn ($t) => (int)$t->getId(), $tickets->findVisibleInBoard($kunde));
$expect(
	'Kommentare des Kunden',
	['Kommentar zu public/anna', 'Kommentar zu internal/kunde', 'Kommentar zu private/kunde'],
	array_map(static fn ($c) => $c->getBody(), $comments->findForTickets($kundenIds)),
);

// Meine Aufgaben: verantwortlich oder mitarbeitend, boarduebergreifend.
$expect('Meine Aufgaben (kunde)', ['internal/kunde', 'private/kunde'], $titles($tickets->findVisibleAcrossBoards('smoke-kunde', TaskFilter::openOnly())));

// --- Abbau ----------------------------------------------------------------
foreach (['pwerk_comments' => 'ticket_id', 'pwerk_tickets' => 'board_id', 'pwerk_members' => 'board_id', 'pwerk_boards' => 'id'] as $table => $col) {
	$qb = $db->getQueryBuilder();
	$ids = $table === 'pwerk_comments' ? array_values($made) : [$boardId];
	$qb->delete($table)->where($qb->expr()->in($col, $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
	$qb->executeStatement();
}
$qb = $db->getQueryBuilder();
$qb->delete('pwerk_projects')->where($qb->expr()->eq('id', $qb->createNamedParameter($projectId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
$qb->executeStatement();

echo $failures === [] ? "\nERGEBNIS: Regel haelt in allen geprueften Faellen\n" : "\nERGEBNIS: FEHLGESCHLAGEN (" . implode('; ', $failures) . ")\n";
