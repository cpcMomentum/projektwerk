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
			// Global zuerst, danach die Projekte — dieselbe Reihenfolge, in der
			// die Oberflaeche sie zeigt.
			->orderBy('board_id', 'ASC')
			->addOrderBy('pref_key', 'ASC');

		return $this->findEntities($qb);
	}

	/** Der `board_id`-Wert, der „gilt fuer alle Projekte" bedeutet. */
	public const GLOBAL_SCOPE = 0;

	/**
	 * Ist dieser Kanal für diese Person in diesem Projekt eingeschaltet?
	 *
	 * **Drei Stufen, in dieser Reihenfolge:**
	 *
	 * 1. Gibt es eine Zeile für **genau dieses Projekt**, gilt sie.
	 * 2. Sonst die **globale** Zeile (`board_id = 0`) — sie deckt damit auch
	 *    jedes Projekt ab, das es beim Einstellen noch gar nicht gab.
	 * 3. Sonst **an**.
	 *
	 * Die dritte Stufe steht hier und nicht in der Datenbank: Ein neues Mitglied
	 * bekommt Benachrichtigungen, ohne dass jemand für es eine Zeile anlegen
	 * müsste. Wäre es andersherum, bliebe jeder Kunde stumm, bis ihn jemand
	 * freischaltet — und niemand würde merken, dass er nie etwas bekommt.
	 *
	 * @param string $userId Kennung der Person.
	 * @param string $prefKey Einer der Kanäle oder Anlässe aus {@see NotifyPref}.
	 * @param int $boardId Projekt, oder {@see GLOBAL_SCOPE} wenn es um keins geht.
	 */
	public function isEnabled(string $userId, string $prefKey, int $boardId = self::GLOBAL_SCOPE): bool {
		$global = null;

		foreach ($this->findForUser($userId) as $pref) {
			if ($pref->getPrefKey() !== $prefKey) {
				continue;
			}
			if ((int)$pref->getBoardId() === $boardId && $boardId !== self::GLOBAL_SCOPE) {
				return (int)$pref->getEnabled() === 1;
			}
			if ((int)$pref->getBoardId() === self::GLOBAL_SCOPE) {
				$global = (int)$pref->getEnabled() === 1;
			}
		}

		return $global ?? true;
	}
}
