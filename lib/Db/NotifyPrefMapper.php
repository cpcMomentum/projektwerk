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
 * Die Kanalschalter einer Person.
 *
 * **Auch dieser Mapper kennt keinen Betrachter** — und zwar aus einem anderen
 * Grund als der {@see MailOutboxMapper}: Hier ist die Person, um deren Daten es
 * geht, dieselbe, die sie liest. Es gibt nichts zu filtern, weil es nichts
 * Fremdes zu sehen gibt. Der erste Parameter ist eine Benutzerkennung, und
 * genau die ist die Grenze.
 *
 * Die einzige Stelle, an der eine **fremde** Kennung hereinkommt, ist der
 * Versandweg: Er fragt „will diese Person Mails?", bevor er ihr welche
 * schreibt. Auch das ist kein Sichtbarkeitsfall — die Antwort ist ein Schalter,
 * kein Projektinhalt.
 *
 * @template-extends QBMapper<NotifyPref>
 */
class NotifyPrefMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_notify_prefs', NotifyPref::class);
	}

	/**
	 * Alle Schalter einer Person.
	 *
	 * @param string $userId Kennung der Person.
	 * @return NotifyPref[]
	 */
	public function findForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq(
				'user_id',
				$qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
			))
			->orderBy('channel', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Ist dieser Kanal für diese Person eingeschaltet?
	 *
	 * **Keine Zeile heisst „an".** Die Vorgabe steht hier und nicht in der
	 * Datenbank: Ein neues Mitglied bekommt Benachrichtigungen, ohne dass jemand
	 * für es eine Zeile anlegen müsste. Wäre es andersherum, bliebe jeder Kunde
	 * stumm, bis ihn jemand freischaltet — und niemand würde merken, dass er
	 * nie etwas bekommt.
	 *
	 * @param string $userId Kennung der Person.
	 * @param string $channel Einer der Kanäle aus {@see NotifyPref}.
	 */
	public function isEnabled(string $userId, string $channel): bool {
		foreach ($this->findForUser($userId) as $pref) {
			if ($pref->getChannel() === $channel) {
				return (int)$pref->getEnabled() === 1;
			}
		}

		return true;
	}
}
