<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Der Ausgangskorb — gelesen **nur** vom Nachlauf-Job.
 *
 * **Dieser Mapper kennt keinen Betrachter, und das ist die Zusage.** Jeder
 * andere Lesepfad der App beginnt mit einem {@see \OCA\Projektwerk\Access\ViewerContext}
 * oder einer bereits gefilterten Menge, weil an seinem Ende ein Mensch steht.
 * Hier steht am Ende ein Hintergrundjob, der eine Mail nachreicht — er hat
 * keine Rolle, kein Board und keine Sichtbarkeit.
 *
 * Deshalb steht dieser Mapper in der Leak-Matrix **mit einer anderen Art von
 * Erwartung**: nicht „was sieht wer", sondern „hier kommt niemand her". Der
 * Test dazu prüft, dass keine Methode einen `ViewerContext` annimmt. Trägt
 * jemand später eine betrachterabhängige Abfrage nach, wird er rot — und dann
 * braucht es eine echte Erwartung.
 *
 * @template-extends QBMapper<MailOutbox>
 */
class MailOutboxMapper extends QBMapper {

	/**
	 * Nach wie vielen Fehlversuchen aufgegeben wird.
	 *
	 * Die Grenze steht **hier** und nicht im Job: Wer die Menge der offenen
	 * Zeilen bestimmt, bestimmt auch, was „offen" heisst. Zwei Orte waeren zwei
	 * Zahlen, die auseinanderlaufen.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Wie viele Zeilen ein Nachlauf hoechstens anfasst.
	 *
	 * Ohne Deckel zoege ein einzelner Lauf bei einem laenger toten Mailserver
	 * beliebig lange — und ein Hintergrundjob, der nicht endet, blockiert alle
	 * uebrigen (§ Fallstricke, Cron-Modus `ajax`).
	 */
	public const BATCH_SIZE = 20;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_mail_outbox', MailOutbox::class);
	}

	/**
	 * Was der Nachlauf sich vornimmt: offen oder gescheitert, noch nicht
	 * aufgegeben.
	 *
	 * **Ohne Argument**, obwohl eine Obergrenze naheliegend waere. Beide Zahlen
	 * sind Regeln dieser Klasse und keine Entscheidung des Aufrufers; als
	 * Parameter waeren sie an jeder Aufrufstelle neu zu treffen — und die
	 * Signatur begaenne mit einem nackten `int`, was der Bauform-Test zu Recht
	 * ablehnt.
	 *
	 * `skipped_no_address` ist ausdruecklich **nicht** dabei: Ein erneuter
	 * Versuch aendert nichts an einer fehlenden Adresse.
	 *
	 * @return MailOutbox[]
	 */
	public function findRetryable(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'status',
				$qb->createNamedParameter(
					[MailOutbox::STATUS_PENDING, MailOutbox::STATUS_FAILED],
					IQueryBuilder::PARAM_STR_ARRAY,
				),
			))
			->andWhere($qb->expr()->lt(
				'attempts',
				$qb->createNamedParameter(self::MAX_ATTEMPTS, IQueryBuilder::PARAM_INT),
			))
			// Aeltestes zuerst: Eine Mail, die seit Stunden liegt, ist
			// dringender als eine von gerade eben.
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(self::BATCH_SIZE);

		return $this->findEntities($qb);
	}

	/**
	 * Gab es zu diesem Vorgang schon eine Mail dieses Anlasses an diese Person?
	 *
	 * Die Frage hinter dem Unterdrueckungsfenster (#98): Ein lebhafter Abgleich
	 * mit zehn Kommentaren erzeugte sonst zehn Mails an jeden Beteiligten — und
	 * das ist der Punkt, an dem jemand den Mailkanal abschaltet und dabei die
	 * Zuweisungen verliert, die die wichtigen sind.
	 *
	 * **Kein neuer Zustand.** Diese Tabelle wird ohnehin geschrieben; sie fuehrt
	 * Empfaenger, Vorgang, Anlass und Zeitpunkt. Ein Digest waere der andere Weg
	 * gewesen und ist verworfen: Er haette cron fuer den Normalfall tragend
	 * gemacht, und genau das ist der Vorwurf an Nextclouds eingebauten Weg.
	 *
	 * Gezaehlt wird ueber `created_at`, nicht ueber `sent_at`: Massgeblich ist,
	 * wann die Nachricht **entstand**. Eine Zeile, die noch im Nachlauf haengt,
	 * hat ihren Zweck als Ankuendigung bereits erfuellt.
	 *
	 * **Unterdrueckte Zeilen zaehlen ausdruecklich nicht mit.** Sonst
	 * erneuerte jede Unterdrueckung das Fenster: Ein Vorgang mit einem
	 * Kommentar alle 25 Minuten bliebe dauerhaft stumm, weil die jeweils letzte
	 * unterdrueckte Zeile die naechste Pruefung wieder ausloest. Gemeint ist
	 * „hoechstens eine Mail je Fenster" — nicht „Stille, solange jemand
	 * schreibt".
	 *
	 * @param string $recipientUid Wer benachrichtigt wuerde.
	 * @param int $ticketId Der Vorgang.
	 * @param string $event Einer der `EVENT_*`-Werte.
	 * @param \DateTimeInterface $seit Ab wann gezaehlt wird.
	 */
	public function existsSince(string $recipientUid, int $ticketId, string $event, \DateTimeInterface $seit): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from($this->getTableName())
			->where($qb->expr()->eq('recipient_uid', $qb->createNamedParameter($recipientUid)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('event', $qb->createNamedParameter($event)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($seit, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(MailOutbox::STATUS_SUPPRESSED)));

		$ergebnis = $qb->executeQuery();
		$anzahl = (int)$ergebnis->fetchOne();
		$ergebnis->closeCursor();

		return $anzahl > 0;
	}
}
