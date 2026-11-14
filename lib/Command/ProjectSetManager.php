<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Command;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\ProjectLog;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Service\MemberLifecycleService;
use OCA\Projektwerk\Service\ProjectLogService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Für Projekte ohne Verwalter nach einer Kontolöschung; die App selbst kennt keine Admin-Ausnahme. */
class ProjectSetManager extends Command {

	public function __construct(
		private IDBConnection $db,
		private IUserManager $users,
		private MemberLifecycleService $lifecycle,
		private ProjectLogService $projectLog,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('projektwerk:project:set-manager')
			->setDescription('Ein internes Mitglied zum Verwalter machen; hat das Projekt keinen Eigentuemer, wird es Eigentuemer')
			->addArgument('projectId', InputArgument::REQUIRED, 'Kennung des Projekts')
			->addArgument('userId', InputArgument::REQUIRED, 'Kennung des Mitglieds (Dienstleisterseite)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$roh = (string)$input->getArgument('projectId');
		$userId = (string)$input->getArgument('userId');
		if (!ctype_digit($roh)) {
			$output->writeln('<error>Die Projekt-Kennung ist eine Zahl.</error>');

			return 1;
		}
		$projectId = (int)$roh;
		if (!$this->users->userExists($userId)) {
			$output->writeln('<error>Kein Konto ' . $userId . '.</error>');

			return 1;
		}

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('is_manager')
				->from('pwerk_members')
				->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(ViewerContext::ROLE_INTERNAL)));
			$bisher = $qb->executeQuery()->fetchOne();
			if ($bisher === false) {
				$this->db->rollBack();
				$output->writeln('<error>Kein internes Mitglied ' . $userId . ' in Projekt ' . $projectId . '.</error>');

				return 1;
			}

			$neuVerwalter = (int)$bisher === 0;
			if ($neuVerwalter) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('pwerk_members')
					->set('is_manager', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
					->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(ViewerContext::ROLE_INTERNAL)));
				$qb->executeStatement();
			}

			$eigentuemer = $this->lifecycle->setzeEigentuemer($projectId, '', $userId);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		if ($neuVerwalter) {
			$this->projectLog->recordFor($projectId, TicketLog::ACTOR_SYSTEM, ProjectLog::VIA_CONSOLE, ProjectLog::MANAGER_GRANTED, $userId);
		}
		if ($eigentuemer) {
			$this->projectLog->recordFor($projectId, TicketLog::ACTOR_SYSTEM, ProjectLog::VIA_CONSOLE, ProjectLog::OWNER_CHANGED, null, null, null, $userId);
		}

		$output->writeln('<info>' . $userId . ' verwaltet jetzt Projekt ' . $projectId . ($eigentuemer ? ' und ist Eigentuemer.' : '.') . '</info>');

		return 0;
	}
}
