<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\Projektwerk\Db\TicketLog;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Was zurückbleibt, wenn eine Person geht — und warum es weg muss.
 *
 * §29 der Produktbeschreibung, wörtlich: Beim Löschen eines Kontos werden
 * dessen `private`-Tickets gelöscht und offene Zuweisungen aufgehoben. „Sonst
 * blieben unsichtbare Tickets und ein ewiges ‚wartet auf Kunde' stehen, die
 * sich **mangels Admin-Ausnahme nicht aufräumen ließen**."
 *
 * Dieser letzte Halbsatz ist der ganze Grund. In jeder anderen App könnte ein
 * Administrator hinterherräumen. Hier nicht: Es gibt keine Admin-Ausnahme, das
 * ist die Zusage, auf der das Produkt beruht — und die Kehrseite davon ist,
 * dass ein privates Ticket ohne seinen Ersteller für **niemanden** mehr
 * erreichbar ist. Es läge für immer in der Datenbank, unsichtbar und
 * unlöschbar.
 *
 * **Deshalb rohes SQL statt der Mapper.** Jeder Lesepfad dieser App beginnt mit
 * einem Betrachter; hier gibt es keinen — die Person ist weg. Ein Mapper mit
 * betrachterfreier Löschmethode wäre genau der zweite Zugang, den der
 * Bauform-Test verbietet, und er stünde danach jedem offen. Der Aufräumweg
 * bleibt deshalb hier, an einer Stelle, die nichts liest und nur auf ein
 * Systemereignis reagiert.
 */
class MemberLifecycleService {

	public const KEY_PENDING = 'forget_pending';

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
		private TicketLogService $log,
		private IAppConfig $config,
	) {
	}

	/** @return list<string> */
	public function pending(): array {
		return array_values(array_filter($this->config->getValueArray(Application::APP_ID, self::KEY_PENDING, [], true), 'is_string'));
	}

	/**
	 * Alle Spuren einer Person entfernen, deren Konto gelöscht wurde.
	 *
	 * Die Reihenfolge ist nicht beliebig: Erst die Zuweisungen lösen, dann die
	 * privaten Tickets löschen, dann die Mitgliedschaften. Andersherum fehlte
	 * beim Löschen der Tickets die Zuordnung, über die sie zu finden sind.
	 *
	 * **Läuft in einer Transaktion.** Ein halb aufgeräumtes Konto wäre
	 * schlimmer als ein gar nicht aufgeräumtes: Es sähe sauber aus.
	 *
	 * @param string $userId Kennung der gelöschten Person.
	 */
	public function forget(string $userId): void {
		$this->db->beginTransaction();

		try {
			$geloest = $this->loeseZuweisungen($userId);
			$geloescht = $this->loeschePrivateTickets($userId);
			$uebergeben = $this->uebergebeEigentum($userId);
			$this->entferneMitgliedschaften($userId);
			$this->entferneEinstellungen($userId);

			$this->db->commit();

			$this->logger->info(sprintf(
				'ProjektWerk: Konto %s aufgeraeumt — %d Zuweisungen geloest, %d private Vorgaenge entfernt, %d Projekte uebergeben.',
				$userId,
				$geloest,
				$geloescht,
				$uebergeben,
			));
		} catch (\Throwable $e) {
			$this->db->rollBack();

			// **Nicht weiterwerfen.** Das Ereignis kommt von Nextcloud beim
			// Loeschen eines Kontos; eine Ausnahme hier braeche den
			// Loeschvorgang ab und liesse ein halbes Konto zurueck. Was hier
			// misslingt, gehoert ins Log und in eine spaetere Bereinigung.
			$this->logger->error('ProjektWerk: Aufraeumen nach Kontoloeschung gescheitert, wird wiederholt', [
				'exception' => $e,
				'userId' => $userId,
			]);
			$this->merke($userId, true);

			return;
		}

		$this->merke($userId, false);
	}

	/**
	 * @return bool ob sich etwas geändert hat
	 */
	public function setzeEigentuemer(int $projectId, string $von, string $nach): bool {
		$geaendert = false;
		foreach (['pwerk_projects' => 'id', 'pwerk_boards' => 'project_id'] as $tabelle => $spalte) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($tabelle)
				->set('owner_user_id', $qb->createNamedParameter($nach))
				->where($qb->expr()->eq($spalte, $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($von)));
			$geaendert = $qb->executeStatement() > 0 || $geaendert;
		}

		return $geaendert;
	}

	private function merke(string $userId, bool $offen): void {
		$bisher = $this->pending();
		$liste = array_values(array_diff($bisher, [$userId]));
		if ($offen) {
			$liste[] = $userId;
		}
		if ($liste === $bisher) {
			return;
		}
		try {
			$this->config->setValueArray(Application::APP_ID, self::KEY_PENDING, $liste, true);
		} catch (\Throwable $e) {
			// Nicht weiterwerfen: Das Löschen des Kontos darf hieran nicht scheitern.
			$this->logger->error('ProjektWerk: Merkliste fuer das Aufraeumen nicht gespeichert', ['exception' => $e, 'userId' => $userId]);
		}
	}

	/** Projekte gehen an das dienstälteste interne Mitglied mit Verwaltungsrecht, sonst bleiben sie ohne Eigentümer. */
	private function uebergebeEigentum(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('pwerk_projects')
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId)));
		$projekte = array_map(static fn (array $zeile): int => (int)$zeile['id'], $qb->executeQuery()->fetchAll());

		foreach ($projekte as $projectId) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('user_id')
				->from('pwerk_members')
				->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(ViewerContext::ROLE_INTERNAL)))
				->andWhere($qb->expr()->eq('is_manager', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->notIn('user_id', $qb->createNamedParameter([$userId, ...$this->pending()], IQueryBuilder::PARAM_STR_ARRAY)))
				->orderBy('added_at', 'ASC')
				->addOrderBy('id', 'ASC')
				->setMaxResults(1);
			$this->setzeEigentuemer($projectId, $userId, (string)($qb->executeQuery()->fetchOne() ?: ''));
		}

		// Boards mit abweichendem Eigentümer folgen ihrem Projekt.
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.id', 'p.owner_user_id')
			->from('pwerk_boards', 'b')
			->innerJoin('b', 'pwerk_projects', 'p', $qb->expr()->eq('p.id', 'b.project_id'))
			->where($qb->expr()->eq('b.owner_user_id', $qb->createNamedParameter($userId)));
		foreach ($qb->executeQuery()->fetchAll() as $zeile) {
			$update = $this->db->getQueryBuilder();
			$update->update('pwerk_boards')
				->set('owner_user_id', $update->createNamedParameter((string)$zeile['owner_user_id']))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$zeile['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('pwerk_boards')
			->set('created_by', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		return count($projekte);
	}

	/**
	 * Wie viele private Vorgänge das Entfernen aus **einem** Projekt löschen würde.
	 *
	 * Grundlage der bezifferten Rückfrage vor dem Entfernen eines Mitglieds
	 * (§5.29). Zählt nur, was auch {@see removeFromProject()} löscht: die privaten
	 * Vorgänge dieser Person in genau diesem Projekt. Interne und öffentliche
	 * bleiben stehen und werden nicht gezählt — sie gehören dem Projekt.
	 *
	 * @param string $userId Kennung der Person.
	 * @param int $projectId Kennung des Projekts.
	 */
	public function countPrivateInProject(string $userId, int $projectId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_tickets')
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('creator_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter(TicketScope::VISIBILITY_PRIVATE)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Eine Person aus **einem** Projekt entfernen — dieselbe Aufräumlogik wie
	 * {@see forget()}, aber auf das Projekt begrenzt (§5.29, manuelles Entfernen).
	 *
	 * Der Unterschied zum Kontolöschen: Die Person bleibt bestehen und in anderen
	 * Projekten Mitglied. Angefasst wird ausschließlich, was zu **diesem** Projekt
	 * gehört — die privaten Vorgänge dieser Person hier, ihre Zuweisungen hier,
	 * ihre Mitgliedschaft hier. Kanalschalter und Vorgemerktes bleiben, sie gelten
	 * projektübergreifend.
	 *
	 * **Projektweit, nicht je Board.** Die Mitgliedschaft gilt seit #246 für das
	 * ganze Projekt; `pwerk_members.board_id` nennt nur das Heimat-Board. Würde
	 * hier auf ein Board eingegrenzt, träfe das Entfernen über ein
	 * Geschwister-Board keine Zeile, und die Person behielte den Zugriff.
	 *
	 * Reihenfolge wie bei `forget`: erst Zuweisungen lösen, dann private Vorgänge
	 * löschen, dann die Mitgliedschaft. Alles in einer Transaktion. Bleibt die
	 * Mitgliedschaft stehen, wird alles zurückgerollt.
	 *
	 * @throws \RuntimeException wenn nicht genau eine Mitgliedschaft entfernt wurde
	 *
	 * @param string $userId Kennung der Person.
	 * @param int $projectId Kennung des Projekts.
	 */
	public function removeFromProject(string $userId, int $projectId, string $actorUserId = TicketLog::ACTOR_SYSTEM): void {
		$this->db->beginTransaction();

		try {
			$this->protokolliereZuweisungen($userId, $projectId, $actorUserId);
			$geloest = $this->loeseZuweisungenImProjekt($userId, $projectId);
			$geloescht = $this->loeschePrivateTicketsImProjekt($userId, $projectId);
			$this->entferneMitgliedschaftImProjekt($userId, $projectId);

			$this->db->commit();

			$this->logger->info(sprintf(
				'ProjektWerk: %s aus Projekt %d entfernt — %d Zuweisungen geloest, %d private Vorgaenge entfernt.',
				$userId,
				$projectId,
				$geloest,
				$geloescht,
			));
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Die IDs aller Vorgänge eines Projekts — als Unterabfrage für die
	 * projektbegrenzten Aufräumschritte, die selbst kein `project_id` tragen
	 * (Schritte, Mitarbeitende hängen am Vorgang, nicht am Projekt).
	 *
	 * @param IQueryBuilder $outer Der Query, in dessen Kontext die Unterabfrage steht.
	 * @param int $projectId Kennung des Projekts.
	 */
	private function ticketIdsInProject(IQueryBuilder $outer, int $projectId): IQueryBuilder {
		$sub = $this->db->getQueryBuilder();
		$sub->select('id')
			->from('pwerk_tickets')
			->where($sub->expr()->eq('project_id', $outer->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

		return $sub;
	}

	/**
	 * Verlaufseinträge für die Zuweisungen, die gleich aufgehoben werden; die der eigenen privaten Vorgänge räumt der Purge mit ab.
	 *
	 * @param string $userId Kennung der Person.
	 * @param int $projectId Kennung des Projekts.
	 * @param string $actorUserId Wer entfernt.
	 */
	private function protokolliereZuweisungen(string $userId, int $projectId, string $actorUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'visibility')
			->from('pwerk_tickets')
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		foreach ($result->fetchAll() as $row) {
			$this->log->recordFor($actorUserId, (int)$row['id'], (string)$row['visibility'], TicketLog::RESPONSIBLE, null, $userId, null);
		}
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('s.title', 't.id', 't.visibility')
			->from('pwerk_steps', 's')
			->innerJoin('s', 'pwerk_tickets', 't', $qb->expr()->eq('t.id', 's.ticket_id'))
			->where($qb->expr()->eq('t.project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.assigned_user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		foreach ($result->fetchAll() as $row) {
			$this->log->recordFor($actorUserId, (int)$row['id'], (string)$row['visibility'], TicketLog::STEP_ASSIGNED, (string)$row['title'], $userId, null);
		}
		$result->closeCursor();
	}

	/**
	 * Offene Zuweisungen dieser Person in **diesem** Projekt aufheben.
	 *
	 * @param string $userId Kennung der Person.
	 * @param int $projectId Kennung des Projekts.
	 */
	private function loeseZuweisungenImProjekt(string $userId, int $projectId): int {
		$anzahl = 0;

		$qb = $this->db->getQueryBuilder();
		$qb->update('pwerk_tickets')
			->set('responsible_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)));
		$anzahl += $qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->update('pwerk_steps')
			->set('assigned_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('assigned_role', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('assigned_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('assigned_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('ticket_id', $qb->createFunction($this->ticketIdsInProject($qb, $projectId)->getSQL())));
		$anzahl += $qb->executeStatement();

		return $anzahl;
	}

	/**
	 * Die privaten Vorgänge dieser Person in **diesem** Projekt löschen, samt
	 * Kindern. Interne und öffentliche bleiben stehen — sie gehören dem Projekt.
	 *
	 * @param string $userId Kennung der Person.
	 * @param int $projectId Kennung des Projekts.
	 */
	private function loeschePrivateTicketsImProjekt(string $userId, int $projectId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('pwerk_tickets')
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('creator_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter(TicketScope::VISIBILITY_PRIVATE)));

		$ids = array_map(
			static fn (array $zeile): int => (int)$zeile['id'],
			$qb->executeQuery()->fetchAll(),
		);

		if ($ids === []) {
			return 0;
		}

		foreach (['pwerk_comments', 'pwerk_steps', 'pwerk_attachments', 'pwerk_ticket_users', 'pwerk_reads', 'pwerk_ticket_log'] as $tabelle) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($tabelle)
				->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('pwerk_tickets')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qb->executeStatement();
	}

	/**
	 * Die Mitgliedschaft dieser Person in **diesem** Projekt entfernen — und ihre
	 * Mitarbeit an den verbleibenden (internen/öffentlichen) Vorgängen des
	 * Projekts, damit keine tote Zuordnung stehen bleibt.
	 *
	 * @param string $userId Kennung der Person.
	 * @param int $projectId Kennung des Projekts.
	 */
	private function entferneMitgliedschaftImProjekt(string $userId, int $projectId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('pwerk_ticket_users')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('ticket_id', $qb->createFunction($this->ticketIdsInProject($qb, $projectId)->getSQL())));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('pwerk_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
		$entfernt = $qb->executeStatement();

		// Der Aufrufer hat die Mitgliedschaft vorher festgestellt. Trifft das
		// Löschen trotzdem nicht genau eine Zeile, wäre „entfernt" eine falsche
		// Auskunft — lieber scheitern als Zugriff stehen lassen.
		if ($entfernt !== 1) {
			throw new \RuntimeException(sprintf(
				'ProjektWerk: Mitgliedschaft von %s in Projekt %d nicht entfernt (%d Zeilen).',
				$userId,
				$projectId,
				$entfernt,
			));
		}
	}

	/**
	 * Offene Zuweisungen aufheben — an Vorgängen **und** an Arbeitsschritten.
	 *
	 * Ohne das bliebe der Wartezustand „wartet auf Kunde" ewig stehen: Er wird
	 * aus offenen Schritten gerechnet, und ein Schritt, der einer gelöschten
	 * Person gehört, wird nie erledigt.
	 *
	 * @param string $userId Kennung der gelöschten Person.
	 */
	private function loeseZuweisungen(string $userId): int {
		$anzahl = 0;

		$qb = $this->db->getQueryBuilder();
		$qb->update('pwerk_tickets')
			->set('responsible_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter($userId)));
		$anzahl += $qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->update('pwerk_steps')
			->set('assigned_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('assigned_role', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('assigned_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('assigned_user_id', $qb->createNamedParameter($userId)));
		$anzahl += $qb->executeStatement();

		return $anzahl;
	}

	/**
	 * Die privaten Vorgänge dieser Person — mitsamt ihren Kindern.
	 *
	 * **Nur `private`.** Ein interner oder öffentlicher Vorgang gehört dem
	 * Projekt, nicht der Person: Er bleibt stehen, sichtbar wie zuvor, nur ohne
	 * Zuständige. Das ist der Unterschied zwischen „aufräumen" und „Arbeit
	 * vernichten".
	 *
	 * Die Anhänge werden **nicht** angefasst — die App löscht keine Dateien
	 * (§5.18). Es bleiben Zeilen ohne Ticket zurück; die räumt derselbe
	 * Durchlauf mit ab, die Dateien im Projektordner bleiben liegen.
	 *
	 * @param string $userId Kennung der gelöschten Person.
	 */
	private function loeschePrivateTickets(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('pwerk_tickets')
			->where($qb->expr()->eq('creator_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq(
				'visibility',
				$qb->createNamedParameter(TicketScope::VISIBILITY_PRIVATE),
			));

		$ids = array_map(
			static fn (array $zeile): int => (int)$zeile['id'],
			$qb->executeQuery()->fetchAll(),
		);

		if ($ids === []) {
			return 0;
		}

		foreach (['pwerk_comments', 'pwerk_steps', 'pwerk_attachments', 'pwerk_ticket_users', 'pwerk_ticket_log'] as $tabelle) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($tabelle)
				->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('pwerk_tickets')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qb->executeStatement();
	}

	/**
	 * @param string $userId Kennung der gelöschten Person.
	 */
	private function entferneMitgliedschaften(string $userId): void {
		foreach (['pwerk_members', 'pwerk_ticket_users'] as $tabelle) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($tabelle)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
	}

	/**
	 * Kanalschalter und offene Mails.
	 *
	 * Eine vorgemerkte Mail an ein gelöschtes Konto hätte keinen Empfänger
	 * mehr; der Nachlauf käme in jedem Durchlauf daran vorbei, bis die
	 * Versuchsgrenze erreicht ist. Sie gleich zu entfernen ist ehrlicher.
	 *
	 * @param string $userId Kennung der gelöschten Person.
	 */
	private function entferneEinstellungen(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('pwerk_notify_prefs')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('pwerk_mail_outbox')
			->where($qb->expr()->eq('recipient_uid', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
