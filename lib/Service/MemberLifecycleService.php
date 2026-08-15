<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
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
			$this->entferneMitgliedschaften($userId);
			$this->entferneEinstellungen($userId);

			$this->db->commit();

			$this->logger->info(sprintf(
				'ProjektWerk: Konto %s aufgeraeumt — %d Zuweisungen geloest, %d private Vorgaenge entfernt.',
				$userId,
				$geloest,
				$geloescht,
			));
		} catch (\Throwable $e) {
			$this->db->rollBack();

			// **Nicht weiterwerfen.** Das Ereignis kommt von Nextcloud beim
			// Loeschen eines Kontos; eine Ausnahme hier braeche den
			// Loeschvorgang ab und liesse ein halbes Konto zurueck. Was hier
			// misslingt, gehoert ins Log und in eine spaetere Bereinigung.
			$this->logger->error('ProjektWerk: Aufraeumen nach Kontoloeschung gescheitert', [
				'exception' => $e,
				'userId' => $userId,
			]);
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

		foreach (['pwerk_comments', 'pwerk_steps', 'pwerk_attachments', 'pwerk_ticket_users'] as $tabelle) {
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
