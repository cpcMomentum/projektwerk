<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\License\LicenseService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * WerkPlus-Grenzen. Der freie Umfang ist fest, ein Flag im Lizenz-Token (entitlement-v1) hebt die jeweilige
 * Grenze auf; Zahlen kommen nie aus dem Token oder der Config.
 *
 * - **A · Kundenprojekte** (`pw_customer_projects`, frei 1): nicht archivierte Projekte mit ≥1 externem
 *   Mitglied, geprüft beim ersten externen Mitglied und beim Rollenwechsel auf extern.
 * - **B · Boards je Projekt** (`pw_multi_board`, frei 1), geprüft in {@see BoardService::createInProject()}.
 * - **C · Aktive Projekte** (`pw_projects`, frei 10), geprüft beim Anlegen und beim Zurückholen aus dem Archiv.
 * - Antworten per E-Mail (`pw_mail_reply`) und KI-Zugriff über MCP (`pw_mcp`) sind ganze Funktionen.
 *
 * Archiviertes zählt nicht, es ist nur lesbar. Bestand über einer Grenze bleibt unangetastet.
 * Die Zählungen sind instanzweit und betrachterlos, deshalb hier und nicht in einem Mapper.
 */
class EntitlementService {

	public const FREE_PROJECTS = 10;
	public const FREE_CUSTOMER_PROJECTS = 1;
	public const FREE_BOARDS_PER_PROJECT = 1;

	public function __construct(
		private LicenseService $license,
		private IDBConnection $db,
		private IL10N $l10n,
	) {
	}

	public function maxCustomerProjects(): int {
		return $this->license->hasFeature('pw_customer_projects') ? PHP_INT_MAX : self::FREE_CUSTOMER_PROJECTS;
	}

	public function maxActiveProjects(): int {
		return $this->license->hasFeature('pw_projects') ? PHP_INT_MAX : self::FREE_PROJECTS;
	}

	public function maxBoardsPerProject(): int {
		return $this->license->hasFeature('pw_multi_board') ? PHP_INT_MAX : self::FREE_BOARDS_PER_PROJECT;
	}

	public function mailReplyAllowed(): bool {
		return $this->license->hasFeature('pw_mail_reply');
	}

	public function mcpAllowed(): bool {
		return $this->license->hasFeature('pw_mcp');
	}

	/**
	 * Regel A, rein: Ein **neues** externes Mitglied wird geblockt, wenn es das
	 * Projekt zum Kundenprojekt machte (bisher kein externes Mitglied) **und**
	 * die Zahl der Kundenprojekte bereits am Limit steht.
	 *
	 * Hat das Projekt schon ein externes Mitglied, ist es bereits ein
	 * Kundenprojekt — weitere externe sind frei. Getrennt vom DB-Zugriff, damit
	 * die Entscheidung ohne Datenbank prüfbar ist.
	 *
	 * @param int $externalMembersInProject Zahl externer Mitglieder im Projekt.
	 * @param int $customerProjectCount Zahl der Kundenprojekte instanzweit.
	 */
	public function blocksNewExternalMember(int $externalMembersInProject, int $customerProjectCount): bool {
		return $externalMembersInProject === 0 && $customerProjectCount >= $this->maxCustomerProjects();
	}

	/**
	 * Regel B, rein: Ein weiteres Board wird geblockt, wenn das Projekt bereits
	 * so viele nicht archivierte Boards hat wie erlaubt.
	 *
	 * @param int $activeBoardsInProject Zahl nicht archivierter Boards im Projekt.
	 */
	public function blocksAdditionalBoard(int $activeBoardsInProject): bool {
		return $activeBoardsInProject >= $this->maxBoardsPerProject();
	}

	public function blocksAdditionalProject(int $activeProjects): bool {
		return $activeProjects >= $this->maxActiveProjects();
	}

	/**
	 * @throws WerkPlusLimitException Wenn bereits so viele aktive Projekte bestehen wie erlaubt.
	 */
	public function assertMayCreateProject(): void {
		if ($this->blocksAdditionalProject($this->countActiveProjects())) {
			throw new WerkPlusLimitException(
				$this->l10n->t('Im freien Umfang sind %s aktive Projekte enthalten. WerkPlus schaltet weitere frei.', [$this->maxActiveProjects()]),
			);
		}
	}

	/** Zurückholen zählt wie ein neues Projekt und, mit Externen, wie ein neues Kundenprojekt. */
	public function assertMayUnarchiveProject(int $projectId): void {
		$this->assertMayCreateProject();
		if ($this->countExternalMembersInProject($projectId) > 0 && $this->countCustomerProjects() >= $this->maxCustomerProjects()) {
			throw new WerkPlusLimitException(
				$this->l10n->t('Im freien Umfang ist ein Kundenprojekt enthalten — WerkPlus schaltet weitere frei.'),
			);
		}
	}

	/**
	 * Regel A durchsetzen: vor dem Hinzufügen eines externen Mitglieds.
	 *
	 * @param int $projectId Das Projekt, dem hinzugefügt wird.
	 * @throws WerkPlusLimitException Wenn ein weiteres Kundenprojekt entstünde.
	 */
	public function assertMayAddExternalMember(int $projectId): void {
		if ($this->blocksNewExternalMember($this->countExternalMembersInProject($projectId), $this->countCustomerProjects())) {
			throw new WerkPlusLimitException(
				$this->l10n->t('Im freien Umfang ist ein Kundenprojekt enthalten — WerkPlus schaltet weitere frei.'),
			);
		}
	}

	/**
	 * Regel B durchsetzen: vor dem Anlegen eines weiteren Boards im Projekt.
	 *
	 * @param int $projectId Das Zielprojekt.
	 * @throws WerkPlusLimitException Wenn das Projekt bereits am Board-Limit steht.
	 */
	public function assertMayCreateBoardInProject(int $projectId): void {
		if ($this->blocksAdditionalBoard($this->countActiveBoardsInProject($projectId))) {
			throw new WerkPlusLimitException(
				$this->l10n->t('Mehrere Boards je Projekt sind Teil von WerkPlus.'),
			);
		}
	}

	/** Archivierte zählen nicht: Sie sind nur noch lesbar. */
	private function countCustomerProjects(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT p.id)'))
			->from('pwerk_projects', 'p')
			->innerJoin('p', 'pwerk_members', 'm', $qb->expr()->eq('m.project_id', 'p.id'))
			->where($qb->expr()->eq('m.role', $qb->createNamedParameter(ViewerContext::ROLE_EXTERNAL)))
			->andWhere($qb->expr()->eq('p.archived', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->fetchCount($qb);
	}

	private function countActiveProjects(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('pwerk_projects')
			->where($qb->expr()->eq('archived', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->fetchCount($qb);
	}

	/** Externe Mitglieder in genau diesem Projekt. */
	private function countExternalMembersInProject(int $projectId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('pwerk_members')
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(ViewerContext::ROLE_EXTERNAL)));

		return $this->fetchCount($qb);
	}

	/** Nicht archivierte Boards in diesem Projekt. */
	private function countActiveBoardsInProject(int $projectId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('pwerk_boards')
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->fetchCount($qb);
	}

	/**
	 * @param IQueryBuilder $qb Eine Zähl-Abfrage mit genau einer Ergebnisspalte.
	 */
	private function fetchCount(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
