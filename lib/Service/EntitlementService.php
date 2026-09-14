<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * WerkPlus-Grenzen (#288) — ein **Stub**, kein Key-System.
 *
 * Zwei Grenzen, mit Axel am 2026-09-14 festgelegt (die Anweisung kannte nur
 * eine, von vor dem Mehr-Board-Modell #246):
 *
 * - **A · Kundenprojekte** = nicht archivierte Projekte mit ≥1 Mitglied der
 *   Rolle `external`: frei **1**, mehr = WerkPlus. Durchgesetzt beim Hinzufügen
 *   des **ersten** externen Mitglieds (nicht beim Anlegen des Boards): Wer sein
 *   zweites Projekt erst intern aufsetzt, darf das; die Grenze greift, wenn es
 *   zum zweiten *Kunden*projekt würde.
 * - **B · Boards pro Projekt**: frei genau **1** — für **alle** Projekte.
 *   Durchgesetzt in {@see \OCA\Projektwerk\Service\BoardService::createInProject()},
 *   dem einzigen Zweitboard-Pfad; deckt den Manager-Weg **und** den
 *   #281-Mitglieder-Weg ab.
 *
 * **Bestandsschutz:** Nur *Neues* wird verhindert; bestehende Projekte/Boards
 * über dem Limit bleiben unangetastet. `0`/leer fällt auf `1` zurück.
 *
 * **Viewerlos und instanzweit.** Die Zählungen fragen keinen Betrachter — es
 * geht um den Umfang der Instanz, nicht darum, was jemand sehen darf. Deshalb
 * liegen sie hier im Service über den QueryBuilder und nicht als
 * betrachterabhängiger Lesepfad in einem Mapper (der stünde sonst in der
 * Leak-Matrix mit einer Erwartung, die es hier nicht gibt).
 *
 * Der echte, app-übergreifende Entitlement-Client (JWT) kommt später; hier nur
 * Stub + Enforcement + verständliche Meldung.
 */
class EntitlementService {

	private const APP = Application::APP_ID;

	public function __construct(
		private IAppConfig $config,
		private IDBConnection $db,
		private IL10N $l10n,
	) {
	}

	/** Wie viele Kundenprojekte im freien Umfang enthalten sind (mind. 1). */
	public function maxCustomerProjects(): int {
		return max(1, $this->config->getValueInt(self::APP, 'plus_max_customer_projects', 1));
	}

	/** Wie viele Boards je Projekt im freien Umfang enthalten sind (mind. 1). */
	public function maxBoardsPerProject(): int {
		return max(1, $this->config->getValueInt(self::APP, 'plus_max_boards_per_project', 1));
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

	/**
	 * Kundenprojekte instanzweit: nicht archivierte Projekte mit ≥1 externem
	 * Mitglied.
	 */
	private function countCustomerProjects(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT p.id)'))
			->from('pwerk_projects', 'p')
			->innerJoin('p', 'pwerk_members', 'm', $qb->expr()->eq('m.project_id', 'p.id'))
			->where($qb->expr()->eq('p.archived', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.role', $qb->createNamedParameter(ViewerContext::ROLE_EXTERNAL)));

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
