<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Command;

use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Service\TicketLogService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Geloeschte Vorgaenge auflisten und wiederherstellen.
 *
 * **Das ist der Papierkorb dieser App**, und er liegt bewusst auf der
 * Kommandozeile. Eine Ansicht in der Oberflaeche waere ein zweiter Ort, an dem
 * Tickets leben — und damit ein zweiter Ort, an dem die Sichtbarkeitsregel
 * stimmen muesste, bei einem Produkt, das genau darauf beruht, dass es einen
 * gibt.
 *
 * Ein Serverzugang ist ausserdem die ehrlichere Grenze als ein Knopf: Das hier
 * kommt ein paarmal im Jahr vor und ist dann Chefsache.
 *
 * **Diese Klasse greift als einzige ausserhalb von `TicketMapper` und
 * `TicketScope` auf `pwerk_tickets` zu.** Sie muss es, weil sie genau das
 * finden soll, was die Sichtbarkeitsregel verbirgt. Der Architekturtest kennt
 * die Ausnahme namentlich — und sie ist auf zwei Anweisungen beschraenkt.
 */
class TicketRestore extends Command {

	public function __construct(
		private IDBConnection $db,
		private TicketLogService $log,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('projektwerk:ticket:restore')
			->setDescription('Geloeschte Vorgaenge auflisten und wiederherstellen')
			->addArgument(
				'ticketId',
				InputArgument::OPTIONAL,
				'Kennung des Vorgangs. Ohne Angabe werden die geloeschten nur aufgelistet.',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$ticketId = $input->getArgument('ticketId');

		if ($ticketId === null) {
			return $this->listDeleted($output);
		}

		return $this->restore((int)$ticketId, $output);
	}

	private function listDeleted(OutputInterface $output): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'board_id', 'number', 'title', 'deleted_at')
			->from('pwerk_tickets')
			->where($qb->expr()->isNotNull('deleted_at'))
			->orderBy('deleted_at', 'DESC');

		$rows = $qb->executeQuery()->fetchAll();

		if ($rows === []) {
			$output->writeln('Keine geloeschten Vorgaenge.');

			return 0;
		}

		$output->writeln('<info>Geloeschte Vorgaenge:</info>');
		foreach ($rows as $row) {
			$output->writeln(sprintf(
				'  #%s  Projekt %s  Nr. %s  %s  (geloescht %s)',
				$row['id'],
				$row['board_id'],
				$row['number'],
				$row['title'],
				$row['deleted_at'],
			));
		}

		return 0;
	}

	private function restore(int $ticketId, OutputInterface $output): int {
		$this->db->beginTransaction();
		try {
			$restored = $this->restoreWithLog($ticketId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		if (!$restored) {
			// Ein Vorgang, der nicht geloescht ist, ist kein Fehler — aber auch
			// keine Wiederherstellung. Der Unterschied gehoert gesagt.
			$output->writeln('<comment>Nichts wiederhergestellt: unbekannte Kennung oder nicht geloescht.</comment>');

			return 1;
		}

		$output->writeln('<info>Vorgang ' . $ticketId . ' wiederhergestellt.</info>');

		return 0;
	}

	/** Wiederherstellen und Verlaufseintrag gemeinsam, damit keiner ohne den anderen bleibt. */
	private function restoreWithLog(int $ticketId): bool {
		$read = $this->db->getQueryBuilder();
		$read->select('visibility')
			->from('pwerk_tickets')
			->where($read->expr()->eq('id', $read->createNamedParameter($ticketId)));
		$visibility = $read->executeQuery()->fetchOne();

		$qb = $this->db->getQueryBuilder();
		$qb->update('pwerk_tickets')
			->set('deleted_at', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ticketId)))
			->andWhere($qb->expr()->isNotNull('deleted_at'));

		if ($qb->executeStatement() === 0) {
			return false;
		}

		$this->log->recordFor(TicketLog::ACTOR_SYSTEM, $ticketId, (string)$visibility, TicketLog::RESTORED);

		return true;
	}
}
